<?php

declare(strict_types=1);

namespace App\Repositories;

final class MeetingRepository extends ProjectStorage
{
    private const FIELDS = ['organizer_id', 'title', 'description', 'start_at', 'end_at',
        'modality', 'location', 'occupancy_id', 'status', 'created_by'];
    private const SELECT = 'm.*,u.name AS organizer_name,o.space_id,
        i.id AS invitation_id,i.response AS invitation_response,i.version AS invitation_version,
        i.responded_at AS invitation_responded_at';
    private const FROM = 'FROM meetings m JOIN users u ON u.id=m.organizer_id
        LEFT JOIN space_occupancies o ON o.id=m.occupancy_id
        LEFT JOIN meeting_invitations i ON i.meeting_id=m.id AND i.invitee_id=?';

    public function find(int $id, int $actor): ?array
    {
        $row = $this->one('SELECT ' . self::SELECT . ' ' . self::FROM . ' WHERE m.id=?', [$actor, $id]);
        return $row === null ? null : $this->trusted($row);
    }

    public function lockMeeting(int $id): ?array
    {
        return $this->one('SELECT * FROM meetings WHERE id=? FOR UPDATE', [$id]);
    }

    /** Parent already locked; only the caller decides when to lock invitations. */
    public function decorate(array $meeting, ?array $invitation, ?int $space): array
    {
        $meeting['organizer_name'] = $this->one('SELECT name FROM users WHERE id=?', [$meeting['organizer_id']])['name'];
        $meeting['space_id'] = $space;
        $meeting['invitation_id'] = $invitation['id'] ?? null;
        $meeting['invitation_response'] = $invitation['response'] ?? null;
        $meeting['invitation_version'] = $invitation['version'] ?? null;
        $meeting['invitation_responded_at'] = $invitation['responded_at'] ?? null;
        return $this->trusted($meeting);
    }

    public function listing(int $actor, array $q, bool $agenda = false): array
    {
        // Indexed actor scopes, not a global ADMIN/OR scan of private calendars.
        $scope = 'SELECT id FROM meetings WHERE organizer_id=?
            UNION SELECT meeting_id FROM meeting_invitations WHERE invitee_id=?';
        $where = '1=1';
        $params = [$actor, $actor, $actor];
        if ($agenda) {
            $scope = "SELECT id FROM meetings WHERE organizer_id=? AND status='PROGRAMADA'
                AND start_at<? AND end_at>?
                UNION SELECT m2.id FROM meeting_invitations i2 JOIN meetings m2 ON m2.id=i2.meeting_id
                WHERE i2.invitee_id=? AND i2.response='ACEPTADA' AND m2.status='PROGRAMADA'
                AND m2.start_at<? AND m2.end_at>?";
            $params = [$actor, $actor, $q['until'], $q['since'], $actor, $q['until'], $q['since']];
        }
        $from = self::FROM . ' JOIN (' . $scope . ') own_scope ON own_scope.id=m.id';
        $page = $this->page(self::SELECT, $from, $where, $params, 'm.start_at DESC,m.id DESC', $q);
        $page['rows'] = array_map($this->trusted(...), $page['rows']);
        return $page;
    }

    private function trusted(array $row): array
    {
        $row['is_invited'] = $row['invitation_id'] !== null;
        return $row;
    }

    public function invitations(int $meeting, bool $lock = false): array
    {
        return $this->rows('SELECT i.*,u.name AS invitee_name FROM meeting_invitations i
            JOIN users u ON u.id=i.invitee_id WHERE i.meeting_id=? ORDER BY i.id'
            . ($lock ? ' FOR UPDATE' : ''), [$meeting]);
    }

    public function ownInvitation(int $meeting, int $actor): ?array
    {
        return $this->one('SELECT * FROM meeting_invitations WHERE meeting_id=? AND invitee_id=? FOR UPDATE', [$meeting, $actor]);
    }

    public function assertActiveUsers(array $ids): bool
    {
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $user = $this->one('SELECT id,active FROM users WHERE id=? LOCK IN SHARE MODE', [$id]);
            if ($user === null || !(bool) $user['active']) {
                return false;
            }
        }
        return true;
    }

    public function create(array $fields): int
    {
        return $this->insertRow('meetings', $fields, self::FIELDS);
    }

    public function editMeeting(int $id, array $fields): void
    {
        $this->changeRow('meetings', $id, $fields,
            ['title', 'description', 'start_at', 'end_at', 'modality', 'location', 'occupancy_id']);
    }

    public function cancelMeeting(int $id): void
    {
        $this->changeRow('meetings', $id, ['status' => 'CANCELADA'], ['status']);
    }

    public function invite(int $meeting, int $invitee): void
    {
        $this->execute('INSERT INTO meeting_invitations(meeting_id,invitee_id) VALUES (?,?)', [$meeting, $invitee]);
    }

    public function resetInvitations(int $meeting): void
    {
        $this->execute("UPDATE meeting_invitations SET response='PENDIENTE',responded_at=NULL,
            version=version+1 WHERE meeting_id=?", [$meeting]);
    }

    public function respond(int $invitation, string $response): void
    {
        $this->execute('UPDATE meeting_invitations SET response=?,responded_at=UTC_TIMESTAMP(),
            version=version+1 WHERE id=?', [$response, $invitation]);
    }

    /** §13: generic own conflicts only; no foreign titles/notes are selected. */
    public function hasPersonalConflict(int $actor, string $start, string $end, int $exclude, string $firstDay, string $lastDay): bool
    {
        $meeting = $this->one("SELECT m.id FROM meetings m WHERE m.id<>? AND m.status='PROGRAMADA'
            AND m.start_at<? AND m.end_at>? AND (m.organizer_id=? OR EXISTS (
                SELECT 1 FROM meeting_invitations i WHERE i.meeting_id=m.id
                AND i.invitee_id=? AND i.response='ACEPTADA')) LIMIT 1", [$exclude, $end, $start, $actor, $actor]);
        $reservation = $this->one("SELECT r.id FROM reservations r JOIN space_occupancies o ON o.id=r.occupancy_id
            WHERE r.responsible_id=? AND r.status IN ('CONFIRMED','IN_PROGRESS')
            AND o.start_at<? AND o.end_at>? LIMIT 1", [$actor, $end, $start]);
        $task = $this->one("SELECT id FROM tasks WHERE assignee_id=? AND status<>'CANCELADA'
            AND COALESCE(start_date,end_date)<=? AND COALESCE(end_date,start_date)>=? LIMIT 1",
            [$actor, $lastDay, $firstDay]);
        return $meeting !== null || $reservation !== null || $task !== null;
    }
}
