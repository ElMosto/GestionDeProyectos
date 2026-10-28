<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policies;

use App\Auth\AuthContext;
use App\Policies\Authorizer;
use App\Policies\Role;
use PHPUnit\Framework\TestCase;

/** §5, §13, §16, D-24/D-25: privacidad por objeto, también para ADMIN. */
final class MeetingAuthorizerTest extends TestCase
{
    private const ROLES = [Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR];

    private static function policy(?string $role, mixed $id = 7, mixed $active = 1): Authorizer
    {
        $context = new AuthContext();
        if ($role !== null) {
            $context->setUser(['id' => $id, 'role_code' => $role, 'active' => $active], AuthContext::METHOD_SESSION);
        }

        return new Authorizer($context);
    }

    public function test_organizer_sees_and_manages_own_meeting_but_cannot_respond(): void
    {
        foreach (self::ROLES as $role) {
            foreach ([7, '7'] as $owner) {
                foreach ([true, false] as $invited) {
                    $policy = self::policy($role);
                    $meeting = ['organizer_id' => $owner, 'is_invited' => $invited, 'invitation_response' => 'PENDIENTE'];
                    self::assertTrue($policy->canViewMeeting($meeting));
                    self::assertTrue($policy->canViewMeetingAgenda($meeting));
                    self::assertTrue($policy->canManageMeeting($meeting));
                    self::assertFalse($policy->canRespondMeeting($meeting));
                }
            }
        }
    }

    public function test_only_accepted_invitee_enters_agenda_and_no_invitee_manages_meeting(): void
    {
        foreach (self::ROLES as $role) {
            foreach (['ACEPTADA', 'PENDIENTE', 'RECHAZADA', 'aceptada', null, true] as $response) {
                $policy = self::policy($role);
                $meeting = ['organizer_id' => '99', 'is_invited' => true, 'invitation_response' => $response];
                self::assertTrue($policy->canViewMeeting($meeting));
                self::assertSame($response === 'ACEPTADA', $policy->canViewMeetingAgenda($meeting));
                self::assertFalse($policy->canManageMeeting($meeting));
                self::assertTrue($policy->canRespondMeeting($meeting));
            }
        }
    }

    public function test_no_global_admin_private_access_or_payload_invitee_grant(): void
    {
        foreach (self::ROLES as $role) {
            $policy = self::policy($role);
            foreach ([null, [], ['organizer_id' => '99'], ['organizer_id' => null]] as $meeting) {
                self::assertMeetingDenied($policy, $meeting);
            }
            foreach ([1, '1', 'true', false, null, [], 1.0] as $invited) {
                self::assertMeetingDenied($policy, [
                    'organizer_id' => 99,
                    'is_invited' => $invited,
                    'invitation_response' => 'ACEPTADA',
                    'invitee_id' => 7,
                    'user_id' => 7,
                ]);
            }
            // Missing organizer grants no management, even to ADMIN; only trusted invitation grants view/response.
            self::assertTrue($policy->canViewMeeting(['is_invited' => true]));
            self::assertTrue($policy->canRespondMeeting(['is_invited' => true]));
            self::assertFalse($policy->canManageMeeting(['is_invited' => true]));
            self::assertFalse($policy->canViewMeetingAgenda(['is_invited' => true]));
        }
    }

    public function test_notification_is_strictly_own_for_every_role(): void
    {
        foreach (self::ROLES as $role) {
            $policy = self::policy($role, '7', '1');
            foreach ([7, '7'] as $owner) {
                self::assertTrue($policy->canReadNotification(['user_id' => $owner]));
            }
            foreach ([null, [], ['user_id' => 99], ['user_id' => '99', 'created_by' => 7]] as $note) {
                self::assertFalse($policy->canReadNotification($note));
            }
        }
    }

    public function test_anonymous_unknown_role_inactive_or_invalid_identity_is_denied(): void
    {
        $policies = [self::policy(null), self::policy('UNKNOWN')];
        foreach (self::ROLES as $role) {
            foreach ([false, 0, '0', null, 'true', 2] as $active) {
                $policies[] = self::policy($role, 7, $active);
            }
            foreach ([null, false, true, 0, '0', '07', '7abc', 7.0, ' 7', '7e0'] as $id) {
                $policies[] = self::policy($role, $id);
            }
        }
        foreach ($policies as $policy) {
            self::assertMeetingDenied($policy, ['organizer_id' => 7, 'is_invited' => true, 'invitation_response' => 'ACEPTADA']);
            self::assertFalse($policy->canReadNotification(['user_id' => 7]));
        }
    }

    public function test_relation_ids_never_coerce_or_truncate_including_admin(): void
    {
        foreach (self::ROLES as $role) {
            $policy = self::policy($role, '7');
            foreach ([null, true, false, 7.0, '07', '7abc', '+7', '7 ', '7.0', '7e0', [], '0'] as $owner) {
                self::assertMeetingDenied($policy, ['organizer_id' => $owner]);
                self::assertFalse($policy->canReadNotification(['user_id' => $owner]));
            }
            $bigId = '18446744073709551614';
            $policy = self::policy($role, $bigId, true);
            self::assertTrue($policy->canViewMeeting(['organizer_id' => $bigId]));
            self::assertTrue($policy->canManageMeeting(['organizer_id' => $bigId]));
            self::assertTrue($policy->canViewMeetingAgenda(['organizer_id' => $bigId]));
            self::assertTrue($policy->canReadNotification(['user_id' => $bigId]));
            self::assertMeetingDenied($policy, ['organizer_id' => '18446744073709551615']);
            self::assertFalse($policy->canReadNotification(['user_id' => '18446744073709551615']));
        }
    }

    private static function assertMeetingDenied(Authorizer $policy, ?array $meeting): void
    {
        self::assertFalse($policy->canViewMeeting($meeting));
        self::assertFalse($policy->canViewMeetingAgenda($meeting));
        self::assertFalse($policy->canManageMeeting($meeting));
        self::assertFalse($policy->canRespondMeeting($meeting));
    }
}
