<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\AuthContext;
use App\Policies\Role;
use App\Services\ClientService;
use App\Tests\Support\InProcessApi as Api;

/** §7, §19.A/H — aislamiento real en los endpoints de clientes. */
final class ClientsApiTest extends DatabaseTestCase
{
    private function actor(string $role = Role::COLABORADOR): array
    {
        $user = $this->fixtures->createUser($role);
        return [$user, $this->fixtures->createToken($user['id'])['token']];
    }

    public function test_A_band_requires_representative_and_phone(): void
    {
        [, $token] = $this->actor();
        $result = Api::post('/api/v1/clients', ['type' => 'BANDA', 'name' => 'Banda'], $token);
        $this->assertSame(422, $result->status);
        $this->assertArrayHasKey('representative_name', $result->payload['error']['fields']);
        $this->assertArrayHasKey('phone', $result->payload['error']['fields']);
    }

    public function test_A_duplicate_warns_without_blocking_creation(): void
    {
        [$owner, $token] = $this->actor();
        $this->fixtures->createClient($owner['id'], ['phone' => '5551234567']);
        $result = Api::post('/api/v1/clients', [
            'type' => 'BANDA', 'name' => 'Otra banda',
            'representative_name' => 'Ana', 'phone' => '5551234567',
        ], $token);
        $this->assertSame(201, $result->status);
        $this->assertNotEmpty($result->payload['meta']['warnings']);
        $this->assertIsString($result->payload['data']['id']);
    }

    public function test_A_sensitive_fields_are_trimmed_in_list_and_detail_for_other_users(): void
    {
        [$owner, $ownerToken] = $this->actor();
        [, $otherToken] = $this->actor();
        [, $adminToken] = $this->actor(Role::ADMINISTRADOR);
        $client = $this->fixtures->createClient($owner['id'], [
            'address' => 'Dirección privada', 'notes' => 'Nota privada',
        ]);
        $sensitive = ['email', 'address', 'notes', 'representative_name', 'created_by', 'version'];
        foreach ([$ownerToken, $adminToken] as $token) {
            $detail = Api::get('/api/v1/clients/' . $client['id'], bearer: $token);
            $this->assertSame(200, $detail->status);
            foreach ($sensitive as $field) {
                $this->assertArrayHasKey($field, $detail->payload['data']);
            }
        }
        $detail = Api::get('/api/v1/clients/' . $client['id'], bearer: $otherToken);
        $list = Api::get('/api/v1/clients', bearer: $otherToken);
        foreach ([$detail->payload['data'], $list->payload['data'][0]] as $row) {
            foreach ($sensitive as $field) {
                $this->assertArrayNotHasKey($field, $row);
            }
        }
    }

    public function test_A_creator_can_edit_contact_but_not_type_and_cannot_edit_others(): void
    {
        [$owner, $token] = $this->actor();
        [$other] = $this->actor();
        $own = $this->fixtures->createClient($owner['id']);
        $foreign = $this->fixtures->createClient($other['id']);
        $update = ['phone' => '5551234567', 'version' => '1'];
        $this->assertSame(403, Api::patch('/api/v1/clients/' . $foreign['id'], $update, $token)->status);
        $result = Api::patch('/api/v1/clients/' . $own['id'], $update, $token);
        $this->assertSame(200, $result->status);
        $this->assertSame('2', $result->payload['data']['version']);
        $this->assertSame(403, Api::patch('/api/v1/clients/' . $own['id'], [
            'type' => 'BANDA', 'version' => '2',
        ], $token)->status);
        $stale = Api::patch('/api/v1/clients/' . $own['id'], $update, $token);
        $this->assertSame(409, $stale->status);
        $this->assertSame('VERSION_CONFLICT', $stale->payload['error']['code']);
    }

    public function test_A_deactivation_is_admin_only_and_preserves_record(): void
    {
        [$owner, $token] = $this->actor();
        [, $adminToken] = $this->actor(Role::ADMINISTRADOR);
        $client = $this->fixtures->createClient($owner['id']);
        $path = '/api/v1/clients/' . $client['id'];
        $this->assertSame(403, Api::post($path . '/deactivate', bearer: $token)->status);
        $this->assertSame(200, Api::post($path . '/deactivate', bearer: $adminToken)->status);
        $result = Api::get($path, bearer: $adminToken);
        $this->assertSame(200, $result->status);
        $this->assertFalse($result->payload['data']['active']);
        $actions = array_column($this->fixtures->auditLogs(), 'action');
        $this->assertContains('CLIENT_DEACTIVATE', $actions);
    }

    public function test_A_direct_service_deactivation_also_enforces_object_policy(): void
    {
        [$owner] = $this->actor();
        $client = $this->fixtures->createClient($owner['id']);
        $actor = $this->fixtures->userRow($owner['id']);
        $this->service(AuthContext::class)->setUser($actor, AuthContext::METHOD_TOKEN);
        $this->assertApiException(
            fn () => $this->service(ClientService::class)->deactivate($client['id'], $actor),
            403, 'FORBIDDEN',
        );
    }

    public function test_A_band_with_future_reservation_cannot_be_converted_or_deactivated(): void
    {
        [$admin, $token] = $this->actor(Role::ADMINISTRADOR);
        $band = $this->fixtures->createClient($admin['id'], ['type' => 'BANDA']);
        $this->fixtures->createFutureReservation($band['id'], $admin['id']);
        $path = '/api/v1/clients/' . $band['id'];
        foreach ([
            Api::patch($path, ['type' => 'NORMAL', 'version' => '1'], $token),
            Api::post($path . '/deactivate', bearer: $token),
        ] as $result) {
            $this->assertSame(409, $result->status);
            $this->assertSame('CLIENT_HAS_FUTURE_RESERVATIONS', $result->payload['error']['code']);
        }
        $row = $this->fixtures->clientRow($band['id']);
        $this->assertSame('BANDA', $row['type']);
        $this->assertSame(1, $row['active']);
    }

    public function test_H_search_is_prepared_and_does_not_interpret_sql(): void
    {
        [$owner, $token] = $this->actor();
        $this->fixtures->createClient($owner['id']);
        $result = Api::get('/api/v1/clients', ['q' => "' OR 1=1 --"], $token);
        $this->assertSame(200, $result->status);
        $this->assertSame([], $result->payload['data']);
    }
}
