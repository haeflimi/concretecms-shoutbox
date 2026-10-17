<?php

namespace Shoutbox\Shout;

use Concrete\Core\Database\Connection\Connection;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The seat a member has at the event running right now (community_event_manager), shown next to the nick like in
 * the design. Without the event manager, or outside an event, there is no seat.
 */
class SeatResolver
{
    /** @var Connection */
    protected $db;

    /** @var int|null|false false = not looked up yet */
    protected $eventID = false;

    /** @var array<int, string|null> */
    protected $seats = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @param int[] $uIDs
     */
    public function preload(array $uIDs): void
    {
        $eventID = $this->liveEventID();
        $missing = array_values(array_diff(array_unique(array_map('intval', $uIDs)), array_keys($this->seats)));
        if ($eventID === null || $missing === []) {
            return;
        }
        foreach ($missing as $uID) {
            $this->seats[$uID] = null;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT uID, seatID FROM cemParticipants WHERE eventID = ? AND uID IN (' . implode(',', array_fill(0, count($missing), '?')) . ')',
            array_merge([$eventID], $missing)
        );
        foreach ($rows as $row) {
            $this->seats[(int) $row['uID']] = $row['seatID'] !== null && $row['seatID'] !== '' ? (string) $row['seatID'] : null;
        }
    }

    public function seatFor(int $uID): ?string
    {
        if (!array_key_exists($uID, $this->seats)) {
            $this->preload([$uID]);
        }

        return $this->seats[$uID] ?? null;
    }

    protected function liveEventID(): ?int
    {
        if ($this->eventID === false) {
            $this->eventID = null;
            if (class_exists(\CommunityEventManager\Entity\Event::class)) {
                try {
                    $now = date('Y-m-d H:i:s');
                    $id = $this->db->fetchOne(
                        'SELECT id FROM cemEvents WHERE status = ? AND startsAt <= ? AND COALESCE(endsAt, startsAt) >= ? ORDER BY startsAt DESC LIMIT 1',
                        ['published', $now, $now]
                    );
                    $this->eventID = $id ? (int) $id : null;
                } catch (\Throwable $e) {
                    $this->eventID = null;
                }
            }
        }

        return $this->eventID;
    }
}
