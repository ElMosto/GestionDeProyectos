<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;

/** Explicit allowlists: pending invitees never receive private location or guest lists. */
final class MeetingPresenter
{
    public function __construct(private readonly Authorizer $authorizer)
    {
    }

    public function detail(array $row, ?array $invitations = null): array
    {
        if (!$this->authorizer->canViewMeeting($row)) {
            throw ApiException::notFound();
        }
        $out = ['id' => (string) $row['id'], 'title' => $row['title'],
            'start_at' => self::iso($row['start_at']), 'end_at' => self::iso($row['end_at']),
            'status' => $row['status'], 'modality' => $row['modality'], 'version' => (string) $row['version'],
            'organizer' => ['id' => (string) $row['organizer_id'], 'name' => $row['organizer_name']]];
        if ($row['is_invited'] === true) {
            $out['invitation'] = ['id' => (string) $row['invitation_id'], 'response' => $row['invitation_response'],
                'version' => (string) $row['invitation_version'], 'responded_at' => self::iso($row['invitation_responded_at'])];
        }
        if ($this->authorizer->canViewMeetingAgenda($row)) {
            $out += ['description' => $row['description'], 'location' => $row['location'],
                'space_id' => $row['space_id'] === null ? null : (string) $row['space_id']];
        }
        if ($invitations !== null && $this->authorizer->canManageMeeting($row)) {
            $out['invitations'] = array_map(static fn (array $i): array => [
                'id' => (string) $i['id'], 'invitee' => ['id' => (string) $i['invitee_id'], 'name' => $i['invitee_name']],
                'response' => $i['response'], 'version' => (string) $i['version'], 'responded_at' => self::iso($i['responded_at']),
            ], $invitations);
        }
        return $out;
    }

    public function event(array $row): array
    {
        if (!$this->authorizer->canViewMeetingAgenda($row)) {
            throw ApiException::notFound();
        }
        return ['kind' => 'MEETING', 'id' => (string) $row['id'], 'title' => $row['title'],
            'start_at' => self::iso($row['start_at']), 'end_at' => self::iso($row['end_at']),
            'status' => $row['status'], 'modality' => $row['modality'], 'location' => $row['location']];
    }

    public static function iso(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
