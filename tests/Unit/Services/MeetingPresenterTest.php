<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Auth\AuthContext;
use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Policies\Role;
use App\Services\MeetingPresenter;
use PHPUnit\Framework\TestCase;

/** §13/§15: mismas políticas en DTO/agenda, versiones estables para Android. */
final class MeetingPresenterTest extends TestCase
{
    private function presenter(string $role, int $id): MeetingPresenter
    {
        $auth = new AuthContext();
        $auth->setUser(['id' => $id, 'role_code' => $role, 'active' => 1], AuthContext::METHOD_TOKEN);
        return new MeetingPresenter(new Authorizer($auth));
    }

    private function meeting(): array
    {
        return [
            'id' => 9, 'organizer_id' => 7, 'organizer_name' => 'Organizador',
            'title' => 'Solicitud', 'start_at' => '2030-11-05 10:00:00', 'end_at' => '2030-11-05 11:00:00',
            'status' => 'PROGRAMADA', 'modality' => 'VIRTUAL', 'version' => 3,
            'description' => 'DESCRIPCION_PRIVADA', 'location' => 'LUGAR_PRIVADO', 'space_id' => null,
            'is_invited' => true, 'invitation_id' => 12, 'invitation_response' => 'PENDIENTE',
            'invitation_version' => 2, 'invitation_responded_at' => null,
        ];
    }

    public function test_F_pending_and_declined_dtos_keep_versions_strings_and_exclude_private_details(): void
    {
        foreach (['PENDIENTE', 'RECHAZADA'] as $response) {
            $data = $this->presenter(Role::COLABORADOR, 8)->detail(array_replace($this->meeting(), ['invitation_response' => $response]));
            $this->assertSame('9', $data['id']);
            $this->assertSame('3', $data['version']);
            $this->assertSame('2', $data['invitation']['version']);
            foreach (['description', 'location', 'space_id', 'invitations'] as $field) {
                $this->assertArrayNotHasKey($field, $data);
            }
        }
    }

    public function test_F_organizer_can_see_guest_versions_as_strings_but_invitees_cannot_see_other_responses(): void
    {
        $row = $this->meeting();
        $guests = [['id' => 12, 'invitee_id' => 8, 'invitee_name' => 'Invitado', 'version' => 2, 'response' => 'PENDIENTE', 'responded_at' => null]];
        $data = $this->presenter(Role::COLABORADOR, 7)->detail($row, $guests);
        $this->assertSame('2', $data['invitations'][0]['version']);
        $this->assertSame('DESCRIPCION_PRIVADA', $data['description']);
        $accepted = $this->presenter(Role::COLABORADOR, 8)->detail(array_replace($row, ['invitation_response' => 'ACEPTADA']), $guests);
        $this->assertSame('LUGAR_PRIVADO', $accepted['location']);
        $this->assertArrayNotHasKey('invitations', $accepted);
    }

    public function test_F_pending_invitee_cannot_serialize_an_agenda_event(): void
    {
        $this->expectException(ApiException::class);
        $this->presenter(Role::COLABORADOR, 8)->event($this->meeting());
    }

    public function test_F_unrelated_admin_cannot_serialize_private_meeting_details(): void
    {
        $this->expectException(ApiException::class);
        $this->presenter(Role::ADMINISTRADOR, 99)->detail(array_replace($this->meeting(), ['is_invited' => false]));
    }
}
