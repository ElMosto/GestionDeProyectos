<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;

/** §13: agenda personal, incluso para ADMIN; no sustituye al calendario de ocupación. */
final class ReservationAgendaTest extends DatabaseTestCase
{
    public function test_E_reservation_agenda_contains_only_responsible_users_events(): void
    {
        $owner = $this->fixtures->createUser(Role::COLABORADOR);
        $other = $this->fixtures->createUser(Role::COLABORADOR);
        $mine = $this->reservation($owner['id'], '2030-11-05 12:00:00');
        $this->reservation($other['id'], '2030-11-06 12:00:00');
        $token = $this->fixtures->createToken($owner['id'])['token'];
        $result = Api::get('/api/v1/agenda/reservations', ['from' => '2030-11-01', 'to' => '2030-11-30'], $token);
        $this->assertSame(200, $result->status, $result->rawBody);
        $this->assertSame(1, (int) $result->payload['meta']['total']);
        $this->assertCount(1, $result->payload['data']);
        $event = $result->payload['data'][0];
        $this->assertSame((string) $mine, $event['reservation_id'] ?? $event['id']);
        foreach (['total', 'hourly_rate_snapshot', 'financial_summary', 'notes', 'band_client_id'] as $field) {
            $this->assertArrayNotHasKey($field, $event);
        }
        $this->assertStringNotContainsString('BANDA_PRIVADA', $result->rawBody);
        $this->assertStringNotContainsString('NOTA_PRIVADA', $result->rawBody);
        $this->assertStringNotContainsString('98765.43', $result->rawBody);
    }

    public function test_E_admin_personal_agenda_does_not_implicitly_include_every_reservation(): void
    {
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $other = $this->fixtures->createUser(Role::LIDER_PROYECTO);
        $this->reservation($other['id'], '2030-11-06 12:00:00');
        $token = $this->fixtures->createToken($admin['id'])['token'];
        $result = Api::get('/api/v1/agenda/reservations', ['from' => '2030-11-01', 'to' => '2030-11-30'], $token);
        $this->assertSame(200, $result->status, $result->rawBody);
        $this->assertSame([], $result->payload['data']);
        $this->assertSame(0, (int) $result->payload['meta']['total']);
    }

    public function test_E_reservation_agenda_requires_authentication_and_a_bounded_date_range(): void
    {
        $this->assertSame(401, Api::get('/api/v1/agenda/reservations', ['from' => '2030-11-01', 'to' => '2030-11-30'])->status);
        $user = $this->fixtures->createUser(Role::COLABORADOR);
        $token = $this->fixtures->createToken($user['id'])['token'];
        $this->assertSame(422, Api::get('/api/v1/agenda/reservations', ['from' => 'invalid', 'to' => '2030-11-30'], $token)->status);
    }

    private function reservation(int $ownerId, string $start): int
    {
        $suffix = bin2hex(random_bytes(6));
        $client = $this->fixtures->createClient($ownerId, ['type' => 'BANDA', 'name' => 'BANDA_PRIVADA ' . $suffix]);
        $this->pdo->prepare("INSERT INTO spaces (name,hourly_rate,created_by) VALUES (?, '98765.43', ?)")
            ->execute(['Sala agenda ' . $suffix, $ownerId]);
        $space = $this->pdo->lastInsertId();
        $end = substr($start, 0, 10) . ' 13:00:00';
        $this->pdo->prepare("INSERT INTO space_occupancies (space_id,start_at,end_at,kind,status,created_by) VALUES (?,?,?,'RESERVATION','CONFIRMED',?)")
            ->execute([$space, $start, $end, $ownerId]);
        $occupancy = $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO sales (folio,type,client_id,responsible_id,status,total,issued_at,created_by) VALUES (?,'RESERVA',?,?,'EMITIDA','98765.43',UTC_TIMESTAMP(),?)")
            ->execute(['VTA-AGENDA-' . $suffix, $client['id'], $ownerId, $ownerId]);
        $sale = $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO reservations (occupancy_id,band_client_id,sale_id,responsible_id,hourly_rate_snapshot,total,notes,created_by) VALUES (?,?,?,?,'98765.43','98765.43','NOTA_PRIVADA',?)")
            ->execute([$occupancy, $client['id'], $sale, $ownerId, $ownerId]);
        return (int) $this->pdo->lastInsertId();
    }
}
