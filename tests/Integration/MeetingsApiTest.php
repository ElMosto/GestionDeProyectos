<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\MeetingFixtures as F;
use App\Tests\Support\ReservationFixtures;

/** §13 / §19.F, §19.A/H, D-24/D-25. Real MariaDB + production middleware/router. */
final class MeetingsApiTest extends DatabaseTestCase
{
    private F $f;
    private array $organizer;
    private string $owner;
    private array $alice;
    private string $a;
    private array $bob;
    private string $b;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new F($this->fixtures);
        [$this->organizer, $this->owner] = $this->f->actor();
        [$this->alice, $this->a] = $this->f->actor();
        [$this->bob, $this->b] = $this->f->actor(Role::LIDER_PROYECTO);
        [, $this->admin] = $this->f->actor(Role::ADMINISTRADOR);
    }

    public function test_F_organizador_desde_creacion_pendientes_sin_agenda_aceptacion_individual(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        self::assertSame([$m['id']], array_column(F::ok(F::agenda($this->owner)), 'id'));
        self::assertSame([], F::ok(F::agenda($this->a)));
        self::assertSame([], F::ok(F::agenda($this->b)));
        $accepted = F::respond($this->a, $m);
        self::assertSame('ACEPTADA', $accepted['invitation']['response']);
        self::assertNotNull($accepted['invitation']['responded_at']);
        self::assertSame([$m['id']], array_column(F::ok(F::agenda($this->a)), 'id'));
        self::assertSame([], F::ok(F::agenda($this->b)));
        self::assertSame('PENDIENTE', F::detail($this->b, $m)['invitation']['response']);
    }

    public function test_F_rechazo_individual_no_cancela_ni_afecta_aceptacion_ajena(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        F::respond($this->a, $m);
        $declined = F::respond($this->b, $m, 'DECLINED');
        self::assertSame('RECHAZADA', $declined['invitation']['response']);
        self::assertSame([], F::ok(F::agenda($this->b)));
        self::assertCount(1, F::ok(F::agenda($this->a)));
        self::assertSame('PROGRAMADA', F::detail($this->owner, $m)['status']);
    }

    public function test_F_cambio_inicio_resetea_todas_incluso_rechazadas_y_version_vieja_no_acepta_hora_nueva(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        $oldA = F::respond($this->a, $m);
        $oldB = F::respond($this->b, $m, 'RECHAZADA');
        $m = F::edit($this->owner, F::detail($this->owner, $m), [
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z',
        ]);
        foreach ([[$this->a, $oldA], [$this->b, $oldB]] as [$token, $old]) {
            $invitation = F::detail($token, $m)['invitation'];
            self::assertSame('PENDIENTE', $invitation['response']);
            self::assertNull($invitation['responded_at']);
            self::assertGreaterThan((int) $old['invitation']['version'], (int) $invitation['version']);
            self::assertSame([], F::ok(F::agenda($token)));
            $before = F::snapshot($this->pdo);
            $stale = Api::post('/api/v1/meetings/' . $m['id'] . '/response', [
                'version' => $old['invitation']['version'], 'response' => 'ACEPTADA',
            ], $token, F::key());
            self::assertSame(409, $stale->status, $stale->json());
            self::assertSame('VERSION_CONFLICT', $stale->errorCode());
            self::assertSame($before, F::snapshot($this->pdo));
        }
    }

    public function test_F_cambiar_solo_fin_resetea_pero_titulo_y_lugar_conservan_respuesta(): void
    {
        $m = F::create($this->owner, [$this->alice]);
        $accepted = F::respond($this->a, $m)['invitation'];
        $m = F::edit($this->owner, F::detail($this->owner, $m), ['title' => 'Nuevo título', 'location' => 'Nueva sala']);
        self::assertSame($accepted, F::detail($this->a, $m)['invitation']);
        $m = F::edit($this->owner, $m, ['end_at' => '2030-01-07T12:00:00Z']);
        self::assertSame('PENDIENTE', F::detail($this->a, $m)['invitation']['response']);
        self::assertSame([], F::ok(F::agenda($this->a)));
    }

    public function test_F_response_usa_version_propia_no_version_de_reunion(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        $own = F::detail($this->a, $m)['invitation'];
        $m = F::edit($this->owner, $m, ['title' => 'Edición sin cambio horario']);
        self::assertNotSame((int) $m['version'], (int) $own['version']);
        $response = Api::post('/api/v1/meetings/' . $m['id'] . '/response', [
            'version' => $own['version'], 'response' => 'ACCEPTED',
        ], $this->a, F::key());
        self::assertSame('ACEPTADA', F::ok($response)['invitation']['response']);
        self::assertSame('PENDIENTE', F::detail($this->b, $m)['invitation']['response']);
    }

    public function test_F_privacidad_pendiente_rechazado_aceptado_y_organizador(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        foreach (['PENDIENTE', 'RECHAZADA'] as $state) {
            if ($state === 'RECHAZADA') {
                F::respond($this->a, $m, $state);
            }
            $minimal = F::detail($this->a, $m);
            self::assertSame($state, $minimal['invitation']['response']);
            foreach (['description', 'location', 'space_id', 'invitations'] as $key) {
                self::assertArrayNotHasKey($key, $minimal);
            }
            $this->assertNoSecrets($minimal, ['secret-room', '<script>', $this->bob['email']]);
        }
        $full = F::respond($this->a, $m);
        self::assertSame($m['description'], $full['description']);
        self::assertSame($m['location'], $full['location']);
        self::assertArrayNotHasKey('invitations', $full);
        self::assertCount(2, F::detail($this->owner, $m)['invitations']);
    }

    public function test_A_F_admin_ajeno_no_lee_ni_edita_ni_cancela_no_scope_leak_en_meta(): void
    {
        $m = F::create($this->owner, [$this->alice]);
        foreach ([Api::get('/api/v1/meetings/' . $m['id'], bearer: $this->admin),
            Api::patch('/api/v1/meetings/' . $m['id'], ['version' => $m['version'], 'title' => 'Ataque'], $this->admin, F::key()),
            Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', ['version' => $m['version'], 'reason' => 'Ataque'], $this->admin, F::key()),
        ] as $denied) {
            self::assertContains($denied->status, [403, 404], $denied->json());
            $this->assertNoSecrets($denied->payload, [$m['title'], $m['description'], $m['location']]);
        }
        foreach ([$this->admin, $this->b] as $token) {
            $list = Api::get('/api/v1/meetings', ['limit' => '1'], $token);
            self::assertSame([], F::ok($list));
            self::assertSame(0, (int) $list->payload['meta']['total']);
            $agenda = F::agenda($token);
            self::assertSame([], F::ok($agenda));
            self::assertSame(0, (int) $agenda->payload['meta']['total']);
        }
    }

    public function test_F_invitados_fijos_solo_activos_sin_organizador_ni_ids_cliente(): void
    {
        $inactive = $this->fixtures->createUser(Role::COLABORADOR, active: false);
        $client = $this->fixtures->createClient((int) $this->organizer['id']);
        // Make the client-only identifier unambiguously absent from users.
        $clientOnlyId = (string) ((int) $client['id'] + 1000000);
        $this->pdo->prepare('UPDATE clients SET id=? WHERE id=?')->execute([$clientOnlyId, $client['id']]);
        foreach ([[(string) $inactive['id']], [(string) $this->organizer['id']], [$clientOnlyId],
            [(string) $this->alice['id'], (string) $this->alice['id']]] as $ids) {
            $before = F::snapshot($this->pdo);
            $r = Api::post('/api/v1/meetings', F::body([], ['invited_user_ids' => $ids]), $this->owner, F::key());
            self::assertSame(422, $r->status, $r->json());
            self::assertSame($before, F::snapshot($this->pdo));
        }
        $m = F::create($this->owner, [$this->alice]);
        $before = F::snapshot($this->pdo);
        $r = Api::patch('/api/v1/meetings/' . $m['id'], [
            'version' => $m['version'], 'invited_user_ids' => [(string) $this->bob['id']],
        ], $this->owner, F::key());
        self::assertSame(422, $r->status, $r->json());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_A_F_invitado_no_edita_cancela_ni_responde_por_otro(): void
    {
        $m = F::create($this->owner, [$this->alice, $this->bob]);
        $before = F::snapshot($this->pdo);
        foreach ([Api::patch('/api/v1/meetings/' . $m['id'], ['version' => $m['version'], 'title' => 'No'], $this->a, F::key()),
            Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', ['version' => $m['version'], 'reason' => 'No'], $this->a, F::key()),
            Api::post('/api/v1/meetings/' . $m['id'] . '/response', [
                'version' => 1, 'response' => 'ACEPTADA', 'invitee_id' => (string) $this->bob['id'],
            ], $this->a, F::key()),
        ] as $r) {
            self::assertContains($r->status, [403, 404, 422], $r->json());
        }
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_F_cancelar_quita_agenda_de_todos_preserva_historial_sin_dinero(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $m = F::create($this->owner, [$this->alice, $this->bob], ['space_id' => $space['id']]);
        F::respond($this->a, $m);
        F::respond($this->b, $m);
        $m = F::detail($this->owner, $m);
        $cancelled = F::ok(Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', [
            'version' => $m['version'], 'reason' => 'Motivo privado de cancelación',
        ], $this->owner, F::key()));
        self::assertSame('CANCELADA', $cancelled['status']);
        foreach ([$this->owner, $this->a, $this->b] as $token) {
            self::assertSame([], F::ok(F::agenda($token)));
            self::assertSame($m['id'], F::detail($token, $m)['id']);
        }
        self::assertSame('CANCELLED', $this->pdo->query('SELECT status FROM space_occupancies')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM meeting_invitations')->fetchColumn());
        $this->assertNoFinancialWrites();
        $before = F::snapshot($this->pdo);
        $r = Api::post('/api/v1/meetings/' . $m['id'] . '/response', [
            'version' => F::detail($this->a, $m)['invitation']['version'], 'response' => 'ACEPTADA',
        ], $this->a, F::key());
        self::assertSame(409, $r->status, $r->json());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_F_espacio_ocupacion_meeting_sin_venta_transferencia_preserva_id_y_libera_anterior(): void
    {
        $rf = new ReservationFixtures($this->fixtures);
        $first = $rf->space($this->admin);
        $second = $rf->space($this->admin);
        $m = F::create($this->owner, [$this->alice], ['space_id' => $first['id']]);
        $old = $this->pdo->query('SELECT * FROM space_occupancies')->fetch();
        self::assertSame('MEETING', $old['kind']);
        $m = F::edit($this->owner, $m, ['space_id' => $second['id']]);
        $new = $this->pdo->query('SELECT * FROM space_occupancies')->fetch();
        self::assertSame((string) $old['id'], (string) $new['id']);
        self::assertSame((string) $second['id'], (string) $new['space_id']);
        self::assertSame((string) $second['id'], $m['space_id']);
        self::assertSame((string) $old['id'], (string) $this->pdo->query('SELECT occupancy_id FROM meetings')->fetchColumn());
        F::create($this->b, [], ['space_id' => $first['id']]);
        $this->assertNoFinancialWrites();
    }

    public function test_F_quitar_espacio_cancela_ocupacion_y_volver_agregar_crea_bloqueo_coherente(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $m = F::create($this->owner, [], ['space_id' => $space['id']]);
        $oldId = $this->pdo->query('SELECT occupancy_id FROM meetings')->fetchColumn();
        $m = F::edit($this->owner, $m, ['space_id' => null]);
        self::assertNull($m['space_id']);
        self::assertNull($this->pdo->query('SELECT occupancy_id FROM meetings')->fetchColumn());
        self::assertSame('CANCELLED', $this->pdo->query('SELECT status FROM space_occupancies')->fetchColumn());
        $m = F::edit($this->owner, $m, ['space_id' => $space['id']]);
        $row = $this->pdo->query('SELECT o.* FROM meetings m JOIN space_occupancies o ON o.id=m.occupancy_id')->fetch();
        self::assertNotSame((string) $oldId, (string) $row['id']);
        self::assertSame('CONFIRMED', $row['status']);
        self::assertSame((string) $space['id'], $m['space_id']);
        $this->assertNoFinancialWrites();
    }

    public function test_F_reprogramacion_conflictiva_revierte_ocupacion_e_invitaciones_aceptadas(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $m = F::create($this->owner, [$this->alice], ['space_id' => $space['id']]);
        F::respond($this->a, $m);
        F::create($this->b, [], ['space_id' => $space['id'],
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z']);
        $m = F::detail($this->owner, $m);
        $before = F::snapshot($this->pdo);
        $r = Api::patch('/api/v1/meetings/' . $m['id'], [
            'version' => $m['version'], 'start_at' => '2030-01-08T10:00:00Z',
            'end_at' => '2030-01-08T11:30:00Z', 'acknowledge_conflicts' => true,
        ], $this->owner, F::key());
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('RESERVATION_CONFLICT', $r->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
        self::assertSame('ACEPTADA', F::detail($this->a, $m)['invitation']['response']);
    }

    public function test_F_conflicto_personal_aviso_generico_confirmable_pero_espacio_no(): void
    {
        $private = F::create($this->owner, [], ['title' => 'Secreto ajeno no repetir']);
        $body = F::body([$this->alice]);
        $before = F::snapshot($this->pdo);
        $r = Api::post('/api/v1/meetings', $body, $this->owner, F::key());
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('CONFLICTS_REQUIRE_ACK', $r->errorCode());
        $this->assertNoSecrets($r->payload, [$private['title'], $private['description'], $private['location']]);
        self::assertSame($before, F::snapshot($this->pdo));
        F::ok(Api::post('/api/v1/meetings', array_replace($body, ['acknowledge_conflicts' => true]), $this->owner, F::key()), 201);
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        F::create($this->b, [], ['space_id' => $space['id']]);
        $r = Api::post('/api/v1/meetings', F::body([], [
            'space_id' => $space['id'], 'acknowledge_conflicts' => true,
        ]), $this->a, F::key());
        self::assertSame(409, $r->status);
        self::assertSame('RESERVATION_CONFLICT', $r->errorCode());
    }

    public function test_F_aceptar_cruce_personal_exige_ack_sin_exponer_actividad(): void
    {
        $own = F::create($this->a, [], ['title' => 'Actividad personal privada']);
        $m = F::create($this->owner, [$this->alice]);
        $invitation = F::detail($this->a, $m)['invitation'];
        $before = F::snapshot($this->pdo);
        $r = Api::post('/api/v1/meetings/' . $m['id'] . '/response', [
            'version' => $invitation['version'], 'response' => 'ACEPTADA',
        ], $this->a, F::key());
        self::assertSame(409, $r->status);
        self::assertSame('CONFLICTS_REQUIRE_ACK', $r->errorCode());
        $this->assertNoSecrets($r->payload, [$own['title'], $own['description'], $own['location']]);
        self::assertSame($before, F::snapshot($this->pdo));
        F::respond($this->a, $m, 'ACEPTADA', ['acknowledge_conflicts' => true]);
        self::assertCount(2, F::ok(F::agenda($this->a)));
    }

    public function test_F_agenda_fechas_civiles_inclusivas_zona_usuario_y_maximo_31_dias(): void
    {
        // Fixtures use America/Mexico_City: local Jan 7 includes Jan 8 at 05:59 UTC.
        $m = F::create($this->owner, [], ['start_at' => '2030-01-08T05:00:00Z', 'end_at' => '2030-01-08T05:30:00Z']);
        F::create($this->owner, [], ['start_at' => '2030-01-08T06:00:00Z', 'end_at' => '2030-01-08T06:30:00Z']);
        $events = F::ok(F::agenda($this->owner, ['from' => '2030-01-07', 'to' => '2030-01-07']));
        self::assertSame([$m['id']], array_column($events, 'id'));
        self::assertSame('MEETING', $events[0]['kind']);
        foreach ([[], ['from' => '2030-01-07'], ['from' => '2030-01-09', 'to' => '2030-01-07'],
            ['from' => '2030-01-01', 'to' => '2030-02-01'], ['from' => "' OR 1=1 --", 'to' => '2030-01-08']] as $query) {
            $r = Api::get('/api/v1/agenda/meetings', $query, $this->owner);
            self::assertSame(422, $r->status, $r->json());
        }
        F::ok(F::agenda($this->owner, ['from' => '2030-01-01', 'to' => '2030-01-31']));
    }

    public function test_F_toda_escritura_exige_key_y_version_coherente_if_match(): void
    {
        $r = Api::post('/api/v1/meetings', F::body(), $this->owner);
        self::assertSame(422, $r->status, $r->json());
        $m = F::create($this->owner, [$this->alice]);
        $before = F::snapshot($this->pdo);
        foreach ([Api::patch('/api/v1/meetings/' . $m['id'], ['version' => $m['version'], 'title' => 'Sin key'], $this->owner),
            Api::post('/api/v1/meetings/' . $m['id'] . '/cancel', ['version' => $m['version'], 'reason' => 'Sin key'], $this->owner),
            Api::post('/api/v1/meetings/' . $m['id'] . '/response', ['version' => 1, 'response' => 'ACEPTADA'], $this->a),
        ] as $r) {
            self::assertSame(422, $r->status, $r->json());
        }
        self::assertSame($before, F::snapshot($this->pdo));
        $headers = F::key() + ['If-Match' => '"' . $m['version'] . '"'];
        $updated = F::ok(Api::patch('/api/v1/meetings/' . $m['id'], ['title' => 'If-Match solo'], $this->owner, $headers));
        $before = F::snapshot($this->pdo);
        $r = Api::patch('/api/v1/meetings/' . $m['id'], [
            'version' => $m['version'], 'title' => 'Cabeceras incoherentes',
        ], $this->owner, F::key() + ['If-Match' => '"' . $updated['version'] . '"']);
        self::assertSame(422, $r->status, $r->json());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_F_replay_create_actualiza_representacion_y_key_cuerpo_distinto_409(): void
    {
        $body = F::body([$this->alice]);
        $key = F::key();
        $m = F::ok(Api::post('/api/v1/meetings', $body, $this->owner, $key), 201);
        $updated = F::edit($this->owner, $m, ['title' => 'Título actual']);
        $before = F::snapshot($this->pdo);
        $replayed = F::ok(Api::post('/api/v1/meetings', $body, $this->owner, $key), 201);
        self::assertSame($updated, $replayed);
        self::assertSame($before, F::snapshot($this->pdo));
        $r = Api::post('/api/v1/meetings', array_replace($body, ['title' => 'Otro cuerpo']), $this->owner, $key);
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $r->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_F_editar_responder_cancelar_replay_sin_duplicar_notificaciones_y_reusar_cuerpo_distinto_409(): void
    {
        $m = F::create($this->owner, [$this->alice]);
        $path = '/api/v1/meetings/' . $m['id'];
        $edit = ['version' => $m['version'], 'title' => 'Edición idempotente'];
        $key = F::key();
        $m = F::ok(Api::patch($path, $edit, $this->owner, $key));
        $before = F::snapshot($this->pdo);
        self::assertSame($m, F::ok(Api::patch($path, $edit, $this->owner, $key)));
        self::assertSame($before, F::snapshot($this->pdo));
        $r = Api::patch($path, array_replace($edit, ['title' => 'Otro título']), $this->owner, $key);
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $r->errorCode());

        $response = ['version' => F::detail($this->a, $m)['invitation']['version'], 'response' => 'ACEPTADA'];
        $key = F::key();
        $accepted = F::ok(Api::post($path . '/response', $response, $this->a, $key));
        $before = F::snapshot($this->pdo);
        self::assertSame($accepted, F::ok(Api::post($path . '/response', $response, $this->a, $key)));
        self::assertSame($before, F::snapshot($this->pdo));
        $r = Api::post($path . '/response', array_replace($response, ['response' => 'RECHAZADA']), $this->a, $key);
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $r->errorCode());

        $cancel = ['version' => F::detail($this->owner, $m)['version'], 'reason' => 'Cancelar idempotente'];
        $key = F::key();
        $cancelled = F::ok(Api::post($path . '/cancel', $cancel, $this->owner, $key));
        $before = F::snapshot($this->pdo);
        self::assertSame($cancelled, F::ok(Api::post($path . '/cancel', $cancel, $this->owner, $key)));
        self::assertSame($before, F::snapshot($this->pdo));
        $r = Api::post($path . '/cancel', array_replace($cancel, ['reason' => 'Otro motivo']), $this->owner, $key);
        self::assertSame(409, $r->status, $r->json());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $r->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_F_validacion_intervalos_estado_organizador_ocupacion_y_granularidad_estudio(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        foreach ([['start_at' => '2030-01-07T11:30:00Z'], ['end_at' => '2030-01-07T09:30:00Z'],
            ['title' => ''], ['modality' => 'OTRA'], ['organizer_id' => (string) $this->bob['id']],
            ['status' => 'FINALIZADA'], ['occupancy_id' => '1'], ['sale_id' => '1'],
            ['space_id' => $space['id'], 'start_at' => '2030-01-07T10:01:00Z'],
        ] as $overrides) {
            $before = F::snapshot($this->pdo);
            $r = Api::post('/api/v1/meetings', F::body([], $overrides), $this->owner, F::key());
            self::assertSame(422, $r->status, $r->json());
            self::assertSame('VALIDATION_FAILED', $r->errorCode());
            self::assertSame($before, F::snapshot($this->pdo));
        }
        // Personal meetings do not inherit studio granularity.
        F::create($this->owner, [], ['start_at' => '2030-01-07T10:01:13Z', 'end_at' => '2030-01-07T10:02:14Z']);
    }

    public function test_F_replay_respuesta_no_filtra_detalle_privado_despues_de_reprogramar(): void
    {
        $m = F::create($this->owner, [$this->alice]);
        $body = ['version' => F::detail($this->a, $m)['invitation']['version'], 'response' => 'ACEPTADA'];
        $key = F::key();
        F::ok(Api::post('/api/v1/meetings/' . $m['id'] . '/response', $body, $this->a, $key));
        F::edit($this->owner, F::detail($this->owner, $m), ['start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z']);
        $before = F::snapshot($this->pdo);
        $replayed = F::ok(Api::post('/api/v1/meetings/' . $m['id'] . '/response', $body, $this->a, $key));
        self::assertSame('PENDIENTE', $replayed['invitation']['response']);
        self::assertArrayNotHasKey('description', $replayed);
        self::assertArrayNotHasKey('location', $replayed);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_A_F_anonimo_e_inactivo_401_en_reuniones_agenda_y_notificaciones(): void
    {
        $m = F::create($this->owner, [$this->alice]);
        $this->pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$this->alice['id']]);
        foreach ([null, $this->a] as $token) {
            foreach (['/api/v1/meetings', '/api/v1/meetings/' . $m['id'], '/api/v1/agenda/meetings', '/api/v1/notifications'] as $path) {
                $r = Api::get($path, ['from' => '2030-01-07', 'to' => '2030-01-09'], $token);
                self::assertSame(401, $r->status, $r->json());
            }
        }
    }

    public function test_H_F_sql_injection_titulo_literal_no_altera_scope_y_disponibilidad_no_filtra_privados(): void
    {
        $space = (new ReservationFixtures($this->fixtures))->space($this->admin);
        $title = "' OR 1=1 -- <script>alert(1)</script>";
        $m = F::create($this->owner, [$this->alice], ['title' => $title, 'space_id' => $space['id']]);
        self::assertSame($title, F::detail($this->owner, $m)['title']);
        self::assertSame([], F::ok(Api::get('/api/v1/meetings', bearer: $this->admin)));
        $r = Api::get('/api/v1/spaces/' . $space['id'] . '/availability', [
            'from' => '2030-01-07T00:00:00Z', 'to' => '2030-01-09T00:00:00Z',
        ], $this->b);
        F::ok($r);
        $this->assertNoSecrets($r->payload, [$title, $m['description'], $m['location'], $this->alice['email']]);
        foreach (['organizer', 'invitations', 'description', 'location', 'notes'] as $field) {
            self::assertStringNotContainsString('"' . $field . '"', $r->json());
        }
    }

    private function assertNoFinancialWrites(): void
    {
        foreach (['sales', 'sale_items', 'payments', 'refunds', 'expenses'] as $table) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
    }
}
