<?php

namespace Shoutbox\Shout;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use DateTimeImmutable;
use Shoutbox\Event\ShoutboxEvent;
use Shoutbox\Realtime\Realtime;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The shouts and pinned announcements: reading, posting with the rules (bans, rate limit → automatic ban), hiding,
 * unpinning, purging, and the rows rendered for the browser.
 */
class ShoutService
{
    /** @var Connection */
    protected $db;

    /** @var Settings */
    protected $settings;

    /** @var Access */
    protected $access;

    /** @var Bans */
    protected $bans;

    /** @var SeatResolver */
    protected $seats;

    /** @var UserInfoRepository */
    protected $users;

    /** @var Realtime */
    protected $realtime;

    /** @var array<int, array<string, mixed>> */
    protected $people = [];

    public function __construct(Connection $db, Settings $settings, Access $access, Bans $bans, SeatResolver $seats, UserInfoRepository $users, Realtime $realtime)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->access = $access;
        $this->bans = $bans;
        $this->seats = $seats;
        $this->users = $users;
        $this->realtime = $realtime;
    }

    /**
     * Visible shouts, newest first: the last $limit ones, or everything newer than $sinceId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit, int $sinceId = 0): array
    {
        $sql = 'SELECT * FROM sbMessages WHERE kind = ? AND hiddenAt IS NULL';
        $params = [Settings::SHOUT];
        if ($sinceId > 0) {
            $sql .= ' AND id > ?';
            $params[] = $sinceId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));

        return $this->renderAll($this->db->fetchAllAssociative($sql, $params));
    }

    /**
     * Announcements still pinned, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pins(int $sinceId = 0): array
    {
        $sql = 'SELECT * FROM sbMessages WHERE kind = ? AND hiddenAt IS NULL AND unpinnedAt IS NULL AND (pinnedUntil IS NULL OR pinnedUntil > ?)';
        $params = [Settings::PIN, Bans::now()];
        if ($sinceId > 0) {
            $sql .= ' AND id > ?';
            $params[] = $sinceId;
        }

        return $this->renderAll($this->db->fetchAllAssociative($sql . ' ORDER BY id DESC LIMIT 50', $params));
    }

    public function todayCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM sbMessages WHERE kind = ? AND hiddenAt IS NULL AND createdAt >= ?',
            [Settings::SHOUT, (new DateTimeImmutable('today'))->format('Y-m-d H:i:s')]
        );
    }

    public function lastId(): int
    {
        return (int) $this->db->fetchOne('SELECT MAX(id) FROM sbMessages');
    }

    /**
     * A member shouts into a channel.
     *
     * @throws UserMessageException
     * @throws BannedException
     *
     * @return array<string, mixed> the rendered shout
     */
    public function shout(User $user, string $body, string $channel): array
    {
        $uID = (int) $user->getUserID();
        $body = $this->cleanBody($body, $this->settings->getMaxLength());
        if ($body === '') {
            throw new UserMessageException(t('Write something first.'));
        }
        if ($this->settings->getChannel($channel) === null) {
            throw new UserMessageException(t('Unknown channel.'));
        }
        $this->guard($user);

        $id = $this->insert([
            'kind' => Settings::SHOUT, 'uID' => $uID, 'channel' => $channel, 'title' => null, 'body' => $body, 'byLabel' => null,
        ]);
        $rendered = $this->renderOne($id);
        $this->realtime->publish(ShoutboxEvent::message($rendered));

        return $rendered;
    }

    /**
     * A moderator pins an announcement to the top of a channel.
     *
     * @param int $minutes 0 = until it is unpinned
     *
     * @throws UserMessageException
     *
     * @return array<string, mixed> the rendered announcement
     */
    public function announce(User $user, string $channel, string $title, string $body, int $minutes): array
    {
        $title = mb_substr(trim(strip_tags($title)), 0, 120);
        $body = $this->cleanBody($body, 1000);
        if ($title === '') {
            throw new UserMessageException(t('Give the announcement a title.'));
        }
        if ($this->settings->getChannel($channel) === null) {
            throw new UserMessageException(t('Unknown channel.'));
        }
        $minutes = max(0, min(10080, $minutes));
        $id = $this->insert([
            'kind' => Settings::PIN, 'uID' => (int) $user->getUserID(), 'channel' => $channel, 'title' => $title, 'body' => $body,
            'byLabel' => mb_substr($this->access->moderatorLabel($user), 0, 64),
            'pinnedUntil' => $minutes > 0 ? (new DateTimeImmutable())->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s') : null,
        ]);
        $rendered = $this->renderOne($id);
        $this->realtime->publish(ShoutboxEvent::message($rendered));

        return $rendered;
    }

    public function unpin(int $id): bool
    {
        $done = $this->db->executeStatement(
            'UPDATE sbMessages SET unpinnedAt = ? WHERE id = ? AND kind = ? AND unpinnedAt IS NULL',
            [Bans::now(), $id, Settings::PIN]
        ) > 0;
        if ($done) {
            $this->realtime->publish(ShoutboxEvent::unpinned($id));
        }

        return $done;
    }

    public function hide(int $id, int $byUID): bool
    {
        $done = $this->db->executeStatement(
            'UPDATE sbMessages SET hiddenAt = ?, hiddenBy = ? WHERE id = ? AND hiddenAt IS NULL',
            [Bans::now(), $byUID, $id]
        ) > 0;
        if ($done) {
            $this->realtime->publish(ShoutboxEvent::hidden($id));
        }

        return $done;
    }

    /**
     * Bans a member for $minutes; the rules (no moderators) are checked here.
     *
     * @throws UserMessageException
     *
     * @return array<string, mixed>
     */
    public function ban(User $by, int $uID, int $minutes, ?string $reason): array
    {
        $target = User::getByUserID($uID);
        if (!$target || !$target->isRegistered()) {
            throw new UserMessageException(t('Member not found.'));
        }
        if ($this->access->canModerate($target)) {
            throw new UserMessageException(t('Moderators cannot be banned.'));
        }
        $ban = $this->bans->ban($uID, max(1, min(10080, $minutes)) * 60, $reason, (int) $by->getUserID(), false);
        $this->realtime->publish(ShoutboxEvent::banned($uID, $ban));

        return $ban;
    }

    /**
     * Rows older than the retention go, pinned announcements only once they are unpinned, expired or hidden.
     */
    public function purge(int $days): int
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . max(0, $days) . ' days')->format('Y-m-d H:i:s');

        return $this->db->executeStatement(
            'DELETE FROM sbMessages WHERE createdAt < ? AND (kind = ? OR hiddenAt IS NOT NULL OR unpinnedAt IS NOT NULL OR (pinnedUntil IS NOT NULL AND pinnedUntil < ?))',
            [$cutoff, Settings::SHOUT, Bans::now()]
        );
    }

    /**
     * Name, initials and avatar of a member, for the browser.
     *
     * @return array{uID: int, name: string, initials: string, avatar: string|null}
     */
    public function person(int $uID): array
    {
        if (!isset($this->people[$uID])) {
            $info = $this->users->getByID($uID);
            $name = $info ? (string) $info->getUserDisplayName() : t('Deleted user');
            $this->people[$uID] = [
                'uID' => $uID,
                'name' => $name,
                'initials' => mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?: $name, 0, 2)),
                'avatar' => $info && $info->hasAvatar() ? (string) $info->getUserAvatar()->getPath() : null,
            ];
        }

        return $this->people[$uID];
    }

    public function seatFor(int $uID): ?string
    {
        return $this->seats->seatFor($uID);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function render(array $row): array
    {
        $created = new DateTimeImmutable((string) $row['createdAt']);
        $pinnedUntil = $row['pinnedUntil'] ? new DateTimeImmutable((string) $row['pinnedUntil']) : null;

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'channel' => (string) $row['channel'],
            'user' => $this->person((int) $row['uID']),
            'seat' => $this->seats->seatFor((int) $row['uID']),
            'title' => $row['title'] !== null && $row['title'] !== '' ? (string) $row['title'] : null,
            'body' => (string) $row['body'],
            'by' => $row['byLabel'] !== null && $row['byLabel'] !== '' ? (string) $row['byLabel'] : null,
            'createdAt' => $created->format(DATE_ATOM),
            'time' => app('helper/date')->formatCustom('H:i', $created),
            'pinnedUntil' => $pinnedUntil ? $pinnedUntil->format(DATE_ATOM) : null,
        ];
    }

    /**
     * Ban and rate limit; a member who shouts too often in a row gets the automatic ban.
     *
     * @throws BannedException
     */
    protected function guard(User $user): void
    {
        $uID = (int) $user->getUserID();
        $ban = $this->bans->active($uID);
        if ($ban !== null) {
            throw new BannedException(t('You are banned until %s.', $ban['until']), $ban);
        }
        if ($this->access->canModerate($user)) {
            return;
        }
        $since = (new DateTimeImmutable())->modify('-' . $this->settings->getRateWindow() . ' seconds')->format('Y-m-d H:i:s');
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM sbMessages WHERE uID = ? AND createdAt > ? AND hiddenAt IS NULL', [$uID, $since]);
        if ($count >= $this->settings->getRateCount()) {
            $seconds = $this->settings->getRateBan();
            $ban = $this->bans->ban($uID, $seconds, t('Too many shouts in a row'), 0, true);
            $this->realtime->publish(ShoutboxEvent::banned($uID, $ban));
            throw new BannedException(t('Too fast: take a %s minute break.', (int) ceil($seconds / 60)), $ban);
        }
    }

    protected function cleanBody(string $body, int $max): string
    {
        $body = strip_tags(str_replace(["\r\n", "\r"], "\n", $body));
        $body = preg_replace("/[ \t]+\n/", "\n", $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        $body = trim($body);
        if (mb_strlen($body) > $max) {
            throw new UserMessageException(t('At most %s characters, please.', $max));
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insert(array $row): int
    {
        $this->db->insert('sbMessages', $row + ['createdAt' => Bans::now()]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderOne(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM sbMessages WHERE id = ?', [$id]);

        return $this->render($row);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderAll(array $rows): array
    {
        $this->seats->preload(array_map(static fn ($row) => (int) $row['uID'], $rows));

        return array_map([$this, 'render'], $rows);
    }
}
