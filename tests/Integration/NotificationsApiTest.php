<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\MeetingFixtures as F;
use App\Tests\Support\ReservationFixtures;
use App\Tests\Support\TestDatabase;
use PDOException;

/** §13 / §19.F/H, D-25: persisted own inbox, generic payloads, atomic domain events. */
final class NotificationsApiTest extends DatabaseTestCase
{
    private F $f;
    private array $ownerUser;
    private string $owner;
    private array $invitee;
    private string $recipient;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new F($this->fixtures);
        [$this->ownerUser, $this->owner] = $this->f->actor();
        [$this->invitee, $this->recipient] = $this->f->actor();
        [, $this->admin] = $this->f->actor(Role::ADMINISTRADOR);
    }

    public function test_F_notificacion_invitacion_persistida_propia_pendiente_sin_agenda(): void
    {
        $m = F::create($this->owner, [$this->invitee]);
        $notes = F::ok(Api::get('/api/v1/notifications', bearer: $this->recipient));
        self::assertCount(1, $notes);
        self::assertSame('MEETING_INVITED', $notes[0]['type']);
        self::assertSame('meeting', $notes[0]['entity_type']);
        self::assertSame($m['id'], $notes[0]['entity_id']);
        self::assertSame('/meetings/' . $m['id'], $notes[0]['payload']['action']);
        self::assertNull($notes[0]['read_at']);
        self::assertSame([], F::ok(F::agenda($this->recipient)));
        $this->assertNoSecrets($notes, [$m['title'], $m['description'], $m['location'], $this->ownerUser['email']]);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        foreach ([$this->owner, $this->admin] as $token) {
            $list = Api::get('/api/v1/notifications', bearer: $token);
            self::assertSame([], F::ok($list));
            self::assertSame(0, (int) $list->payload['meta']['total']);
            self::assertSame(0, (int) $list->payload['meta']['unread_count']);
        }
    }

    public function test_F_unread_count_global_mayor_que_pagina_y_filtros_propios(): void
    {
        foreach (range(7, 10) as $day) {
            F::create($this->owner, [$this->invitee], [
                'start_at' => sprintf('2030-01-%02dT10:00:00Z', $day),
                'end_at' => sprintf('2030-01-%02dT11:00:00Z', $day),
            ]);
        }
        $page = Api::get('/api/v1/notifications', ['limit' => '2', 'offset' => '1'], $this->recipient);
        $notes = F::ok($page);
        self::assertCount(2, $notes);
        self::assertSame(4, (int) $page->payload['meta']['total']);
        self::assertSame(4, (int) $page->payload['meta']['unread_count']);
        F::ok(Api::post('/api/v1/notifications/' . $notes[0]['id'] . '/read', bearer: $this->recipient));
        $unread = Api::get('/api/v1/notifications', ['limit' => '1', 'unread' => '1'], $this->recipient);
        self::assertCount(1, F::ok($unread));
        self::assertSame(3, (int) $unread->payload['meta']['total']);
        self::assertSame(3, (int) $unread->payload['meta']['unread_count']);
        $read = Api::get('/api/v1/notifications', ['unread' => '0'], $this->recipient);
        // unread=0 disables the unread-only filter (not "only read").
        self::assertCount(4, F::ok($read));
        self::assertSame(3, (int) $read->payload['meta']['unread_count']);
    }

    public function test_F_marcar_leida_sin_key_idempotente_conserva_primer_read_at(): void
    {
        F::create($this->owner, [$this->invitee]);
        $note = F::ok(Api::get('/api/v1/notifications', bearer: $this->recipient))[0];
        $read = F::ok(Api::post('/api/v1/notifications/' . $note['id'] . '/read', bearer: $this->recipient));
        self::assertNotNull($read['read_at']);
        // Historical fixture makes timestamp-reset detection deterministic without sleeps.
        $this->pdo->prepare('UPDATE notifications SET read_at=? WHERE id=?')->execute(['2029-12-01 12:34:56', $note['id']]);
        $again = F::ok(Api::post('/api/v1/notifications/' . $note['id'] . '/read', bearer: $this->recipient));
        self::assertSame('2029-12-01T12:34:56Z', $again['read_at']);
        $third = F::ok(Api::post('/api/v1/notifications/' . $note['id'] . '/read', bearer: $this->recipient));
        self::assertSame($again, $third);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function test_A_F_notificacion_ajena_no_marcable_incluso_admin_ni_anonimo(): void
    {
        F::create($this->owner, [$this->invitee]);
        $note = F::ok(Api::get('/api/v1/notifications', bearer: $this->recipient))[0];
        $before = F::snapshot($this->pdo);
        foreach ([$this->owner, $this->admin] as $token) {
            $r = Api::post('/api/v1/notifications/' . $note['id'] . '/read', bearer: $token);
            self::assertContains($r->status, [403, 404], $r->json());
        }
        self::assertSame(401, Api::post('/api/v1/notifications/' . $note['id'] . '/read')->status);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_H_F_payload_persistido_malicioso_no_se_reenvia_ni_ejecuta_link_arbitrario(): void
    {
        $m = F::create($this->owner, [$this->invitee]);
        $malicious = ['message' => '<script>alert(1)</script> private secret 999.99',
            'action' => 'javascript:alert(1)', 'title' => $m['title'], 'description' => $m['description'],
            'location' => $m['location'], 'band_name' => 'Banda privada'];
        $this->pdo->prepare('UPDATE notifications SET payload=?')->execute([json_encode($malicious, JSON_THROW_ON_ERROR)]);
        $r = Api::get('/api/v1/notifications', bearer: $this->recipient);
        $notes = F::ok($r);
        self::assertSame(['message', 'action'], array_keys($notes[0]['payload']));
        self::assertSame('/meetings/' . $m['id'], $notes[0]['payload']['action']);
        $this->assertNoSecrets($r->payload, ['<script>', 'javascript:', '999.99', 'Banda privada', $m['title'], $m['description'], $m['location']]);
        // Even a legacy/unknown type gets a generic safe fallback, not its raw payload.
        $this->pdo->exec("UPDATE notifications SET type='UNKNOWN_LEGACY', entity_type='sale'");
        $fallback = F::ok(Api::get('/api/v1/notifications', bearer: $this->recipient));
        self::assertSame('/notifications', $fallback[0]['payload']['action']);
        $this->assertNoSecrets($fallback, ['<script>', 'javascript:', '999.99', 'Banda privada']);
    }

    public function test_F_cambios_respuestas_cancelacion_notifican_destinatarios_correctos_sin_privados(): void
    {
        $m = F::create($this->owner, [$this->invitee]);
        F::respond($this->recipient, $m);
        $ownerNotes = F::ok(Api::get('/api/v1/notifications', bearer: $this->owner));
        self::assertSame(['MEETING_RESPONDED'], array_column($ownerNotes, 'type'));
        $m = F::edit($this->owner, F::detail($this->owner, $m), [
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z',
        ]);
        F::ok(Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', [
            'version' => $m['version'], 'reason' => 'Motivo privado no divulgar',
        ], $this->owner, F::key()));
        $notes = F::ok(Api::get('/api/v1/notifications', bearer: $this->recipient));
        $types = array_column($notes, 'type');
        sort($types);
        self::assertSame(['MEETING_CANCELLED', 'MEETING_CHANGED', 'MEETING_INVITED'], $types);
        $this->assertNoSecrets($notes, [$m['title'], $m['description'], $m['location'], 'Motivo privado no divulgar']);
        self::assertSame([], F::ok(Api::get('/api/v1/notifications', bearer: $this->admin)));
    }

    public function test_F_notificacion_read_no_concede_permiso_sobre_reunion_ajena(): void
    {
        // Legacy notification pointing at an existing but unrelated object.
        $m = F::create($this->owner);
        $insert = $this->pdo->prepare("INSERT INTO notifications(user_id,type,entity_type,entity_id,payload) VALUES(?,'MEETING_INVITED','meeting',?,?)");
        $insert->execute([$this->invitee['id'], $m['id'], '{}']);
        $id = $this->pdo->lastInsertId();
        F::ok(Api::post('/api/v1/notifications/' . $id . '/read', bearer: $this->recipient));
        $denied = Api::get('/api/v1/meetings/' . $m['id'], bearer: $this->recipient);
        self::assertContains($denied->status, [403, 404], $denied->json());
        $this->assertNoSecrets($denied->payload, [$m['title'], $m['description'], $m['location']]);
    }

    public function test_F_fallo_insert_notificacion_revierte_creacion_con_espacio_invitaciones_y_auditoria(): void
    {
        $this->assertCreateRollback('notifications');
    }

    public function test_F_fallo_auditoria_revierte_creacion_y_notificaciones_en_misma_transaccion(): void
    {
        $this->assertCreateRollback('audit_logs');
    }

    public function test_F_fallo_notificacion_o_auditoria_revierte_edicion_cancelacion_y_respuesta(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $m = F::create($this->owner, [$this->invitee], ['space_id' => $space['id']]);
        foreach (['notifications', 'audit_logs'] as $table) {
            foreach (['edit', 'cancel', 'response'] as $operation) {
                $this->withFailingInsert($table, function () use ($m, $operation): void {
                    match ($operation) {
                        'edit' => F::edit($this->owner, $m, [
                            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z',
                        ]),
                        'cancel' => F::ok(Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', [
                            'version' => $m['version'], 'reason' => 'Cancelación QA fallida',
                        ], $this->owner, F::key())),
                        'response' => F::respond($this->recipient, $m),
                    };
                });
            }
        }
        self::assertSame('PENDIENTE', F::detail($this->recipient, $m)['invitation']['response']);
    }

    private function assertCreateRollback(string $table): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $this->withFailingInsert($table, fn () => F::create($this->owner, [$this->invitee], ['space_id' => $space['id']]));
        // After trigger cleanup a normal write still works; no stranded transaction/claim.
        F::create($this->owner, [$this->invitee], ['space_id' => $space['id']]);
    }

    private function withFailingInsert(string $table, callable $mutation): void
    {
        self::assertSame('gestion_test', TestDatabase::databaseName());
        self::assertSame('gestion_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertContains($table, ['notifications', 'audit_logs']);
        $before = F::snapshot($this->pdo);
        $name = 'qa_meeting_fail_' . $table;
        try {
            $this->pdo->exec('CREATE TRIGGER ' . $name . ' BEFORE INSERT ON ' . $table
                . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_MEETING_ATOMICITY'");
            try {
                $mutation();
                self::fail('La inyección de fallo debe abortar la mutación: ' . $table);
            } catch (PDOException $exception) {
                self::assertStringContainsString('QA_MEETING_ATOMICITY', $exception->getMessage());
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame($before, F::snapshot($this->pdo));
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS ' . $name);
        }
    }
}
