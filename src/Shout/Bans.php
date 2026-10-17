<?php

namespace Shoutbox\Shout;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\User\UserInfoRepository;
use DateTimeImmutable;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Timed bans: a banned member reads but cannot shout. Manual bans carry the moderator, automatic ones (too many
 * shouts in a row) byUID 0 and auto = 1.
 */
class Bans
{
    /** @var Connection */
    protected $db;

    /** @var UserInfoRepository */
    protected $users;

    public function __construct(Connection $db, UserInfoRepository $users)
    {
        $this->db = $db;
        $this->users = $users;
    }

    /**
     * @return array{id: int, until: string, untilIso: string, seconds: int, reason: string|null, auto: bool}|null
     */
    public function active(int $uID): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, `until`, reason, auto FROM sbBans WHERE uID = ? AND `until` > ? ORDER BY `until` DESC LIMIT 1',
            [$uID, self::now()]
        );

        return $row ? $this->describe($row) : null;
    }

    /**
     * A new ban replaces an active one.
     *
     * @return array{id: int, until: string, untilIso: string, seconds: int, reason: string|null, auto: bool}
     */
    public function ban(int $uID, int $seconds, ?string $reason, int $byUID, bool $auto = false): array
    {
        $now = self::now();
        $this->db->executeStatement('UPDATE sbBans SET `until` = ? WHERE uID = ? AND `until` > ?', [$now, $uID, $now]);
        $until = (new DateTimeImmutable())->modify('+' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s');
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 255) : null;
        $this->db->insert('sbBans', [
            'uID' => $uID, 'until' => $until, 'reason' => $reason !== '' ? $reason : null,
            'byUID' => $byUID, 'auto' => $auto ? 1 : 0, 'createdAt' => $now,
        ]);

        return $this->describe(['id' => (int) $this->db->lastInsertId(), 'until' => $until, 'reason' => $reason, 'auto' => $auto ? 1 : 0]);
    }

    public function unban(int $id): bool
    {
        return $this->db->executeStatement('UPDATE sbBans SET `until` = ? WHERE id = ? AND `until` > ?', [self::now(), $id, self::now()]) > 0;
    }

    /**
     * Active bans with the member's name, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM sbBans WHERE `until` > ? ORDER BY `until` DESC', [self::now()]) as $row) {
            $info = $this->users->getByID((int) $row['uID']);
            $by = $row['byUID'] ? $this->users->getByID((int) $row['byUID']) : null;
            $rows[] = $this->describe($row) + [
                'uID' => (int) $row['uID'],
                'name' => $info ? (string) $info->getUserDisplayName() : t('Deleted user'),
                'by' => $by ? (string) $by->getUserDisplayName() : ($row['auto'] ? t('automatic') : ''),
                'createdAt' => (string) $row['createdAt'],
            ];
        }

        return $rows;
    }

    public function purgeExpired(): int
    {
        return $this->db->executeStatement('DELETE FROM sbBans WHERE `until` < ?', [self::now()]);
    }

    public static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, until: string, untilIso: string, seconds: int, reason: string|null, auto: bool}
     */
    protected function describe(array $row): array
    {
        $until = new DateTimeImmutable((string) $row['until']);

        return [
            'id' => (int) $row['id'],
            'until' => app('helper/date')->formatCustom('H:i', $until),
            'untilIso' => $until->format(DATE_ATOM),
            'seconds' => max(0, $until->getTimestamp() - time()),
            'reason' => $row['reason'] !== null && $row['reason'] !== '' ? (string) $row['reason'] : null,
            'auto' => (bool) $row['auto'],
        ];
    }
}
