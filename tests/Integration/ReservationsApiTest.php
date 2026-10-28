<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ReservationFixtures as F;
use App\Tests\Support\SalesFixtures;
use DateTimeImmutable;
use DateTimeZone;

/** §12 / §19.E (plus object authorization §19.A and ticket §19.C). */
final class ReservationsApiTest extends DatabaseTestCase
{
    private F $f;
    private array $adminUser;
    private string $admin;
    private array $space;
    private array $band;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new F($this->fixtures);
        [$this->adminUser, $this->admin] = $this->f->actor();
        $this->space = $this->f->space($this->admin);
        $this->band = $this->f->band((int) $this->adminUser['id']);
    }

    public function test_E_normal_e_inactiva_rechazadas_banda_activa_crea_confirmada(): void
    {
        foreach ([['type' => 'NORMAL'], ['active' => 0]] as $overrides) {
            $client = $this->f->band((int) $this->adminUser['id'], $overrides);
            $before = F::snapshot($this->pdo);
            $result = Api::post('/api/v1/reservations', F::body($client, $this->space), $this->admin, F::key());
            self::assertSame(422, $result->status, $result->json());
            self::assertSame('VALIDATION_FAILED', $result->errorCode());
            self::assertSame($before, F::snapshot($this->pdo));
        }
        $reservation = F::reserve($this->admin, $this->band, $this->space);
        self::assertSame('CONFIRMED', $reservation['status']);
        self::assertSame((string) $this->adminUser['id'], $reservation['responsible_id']);
    }

    public function test_E_90_minutos_tarifa_120_son_180_una_venta_emitida_partida_y_cero_pagos(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        self::assertSame('120.00', $r['hourly_rate_snapshot']);
        self::assertSame('180.00', $r['total']);
        $snapshot = F::snapshot($this->pdo);
        foreach (['reservations', 'space_occupancies', 'sales', 'sale_items'] as $table) {
            self::assertCount(1, $snapshot[$table]);
        }
        self::assertSame([], $snapshot['payments']);
        self::assertSame([], $snapshot['refunds']);
        self::assertSame([], $snapshot['sale_item_extras']);
        $sale = $snapshot['sales'][0];
        self::assertSame($r['sale_id'], (string) $sale['id']);
        self::assertSame('RESERVA', $sale['type']);
        self::assertSame('EMITIDA', $sale['status']);
        self::assertNotEmpty($sale['issued_at']);
        self::assertSame((string) $this->band['id'], (string) $sale['client_id']);
        self::assertSame($r['responsible_id'], (string) $sale['responsible_id']);
        self::assertSame('180.00', $sale['total']);
        $item = $snapshot['sale_items'][0];
        self::assertNull($item['product_id']);
        self::assertSame('1.000', $item['quantity']);
        self::assertSame('180.00', $item['unit_price']);
        self::assertSame('180.00', $item['line_total']);
        self::assertSame('RESERVATION', $snapshot['space_occupancies'][0]['kind']);
        self::assertSame('CONFIRMED', $snapshot['space_occupancies'][0]['status']);
    }

    public function test_E_instantes_con_offset_se_normalizan_a_utc(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space, [
            'start_at' => '2030-01-07T04:00:00-06:00', 'end_at' => '2030-01-07T05:30:00-06:00',
        ]);
        self::assertSame('2030-01-07T10:00:00Z', $r['start_at']);
        self::assertSame('2030-01-07T11:30:00Z', $r['end_at']);
        self::assertSame('2030-01-07 10:00:00', $this->pdo->query('SELECT start_at FROM space_occupancies')->fetchColumn());
    }

    public function test_E_no_admite_responsable_precio_estado_total_ni_ocupacion_forjados(): void
    {
        foreach (['responsible_id' => (string) $this->adminUser['id'], 'hourly_rate_snapshot' => '0.01',
            'total' => '0.01', 'status' => 'FINISHED', 'occupancy_id' => '1', 'sale_id' => '1'] as $field => $value) {
            $before = F::snapshot($this->pdo);
            $result = Api::post('/api/v1/reservations', F::body($this->band, $this->space, [$field => $value]),
                $this->admin, F::key());
            self::assertSame(422, $result->status, $field . ': ' . $result->json());
            self::assertSame($before, F::snapshot($this->pdo));
        }
    }

    public function test_E_intervalos_adyacentes_y_otras_salas_validos_solape_no_deja_filas(): void
    {
        F::reserve($this->admin, $this->band, $this->space, ['end_at' => '2030-01-07T11:00:00Z']);
        F::reserve($this->admin, $this->band, $this->space, [
            'start_at' => '2030-01-07T11:00:00Z', 'end_at' => '2030-01-07T12:00:00Z',
        ]);
        $otherSpace = $this->f->space($this->admin);
        F::reserve($this->admin, $this->band, $otherSpace);
        $before = F::snapshot($this->pdo);
        $result = Api::post('/api/v1/reservations', F::body($this->band, $this->space, [
            'start_at' => '2030-01-07T10:30:00Z', 'end_at' => '2030-01-07T11:30:00Z',
        ]), $this->admin, F::key());
        self::assertSame(409, $result->status);
        self::assertSame('RESERVATION_CONFLICT', $result->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_reprogramacion_conflictiva_y_version_obsoleta_sin_cambios_parciales(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        F::reserve($this->admin, $this->band, $this->space, [
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z',
        ]);
        $before = F::snapshot($this->pdo);
        $path = '/api/v1/reservations/' . $r['id'] . '/reschedule';
        $conflict = Api::post($path, F::move($r), $this->admin, F::key());
        self::assertSame(409, $conflict->status);
        self::assertSame('RESERVATION_CONFLICT', $conflict->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
        $moved = F::ok(Api::post($path, F::move($r, [
            'start_at' => '2030-01-09T10:00:00Z', 'end_at' => '2030-01-09T11:30:00Z',
        ]), $this->admin, F::key()));
        self::assertGreaterThan((int) $r['version'], (int) $moved['version']);
        $before = F::snapshot($this->pdo);
        $old = Api::post($path, F::move($r), $this->admin, F::key());
        self::assertSame(409, $old->status);
        self::assertSame('VERSION_CONFLICT', $old->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_reprogramar_conserva_ids_y_tarifa_historica_misma_sala_destino_adopta_actual(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $before = F::snapshot($this->pdo);
        F::ok(Api::patch('/api/v1/spaces/' . $this->space['id'], [
            'version' => $this->space['version'], 'hourly_rate' => '900.00',
        ], $this->admin));
        $same = F::ok(Api::post('/api/v1/reservations/' . $r['id'] . '/reschedule', F::move($r), $this->admin, F::key()));
        self::assertSame('120.00', $same['hourly_rate_snapshot']);
        self::assertSame('180.00', $same['total']);
        $destination = $this->f->space($this->admin, '250.00');
        $moved = F::ok(Api::post('/api/v1/reservations/' . $r['id'] . '/reschedule', F::move($same, [
            'space_id' => $destination['id'],
        ]), $this->admin, F::key()));
        self::assertSame('250.00', $moved['hourly_rate_snapshot']);
        self::assertSame('375.00', $moved['total']);
        foreach (['id', 'sale_id', 'occupancy_id'] as $id) {
            self::assertSame($r[$id], $moved[$id]);
        }
        $after = F::snapshot($this->pdo);
        foreach (['reservations', 'space_occupancies', 'sales', 'sale_items'] as $table) {
            self::assertCount(1, $after[$table]);
            self::assertSame((string) $before[$table][0]['id'], (string) $after[$table][0]['id']);
        }
        self::assertSame($before['sales'][0]['folio'], $after['sales'][0]['folio']);
        self::assertSame($before['sales'][0]['issued_at'], $after['sales'][0]['issued_at']);
        self::assertSame('375.00', $after['sales'][0]['total']);
        self::assertSame('375.00', $after['sale_items'][0]['line_total']);
        self::assertSame('1.000', $after['sale_items'][0]['quantity']);
    }

    public function test_E_reprogramar_no_baja_importe_del_neto_cobrado_ni_reembolsa_automaticamente(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        SalesFixtures::pay($this->admin, $r['sale_id'], '150.00');
        $before = F::snapshot($this->pdo);
        $result = Api::post('/api/v1/reservations/' . $r['id'] . '/reschedule', F::move($r, [
            'end_at' => '2030-01-08T11:00:00Z',
        ]), $this->admin, F::key());
        self::assertSame(409, $result->status, $result->json());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_transiciones_sincronizan_ocupacion_y_finalizar_libera_hueco(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $path = '/api/v1/reservations/' . $r['id'] . '/transitions';
        $before = F::snapshot($this->pdo);
        self::assertSame(409, Api::post($path, ['version' => $r['version'], 'status' => 'FINISHED'],
            $this->admin, F::key())->status);
        self::assertSame($before, F::snapshot($this->pdo));
        foreach (['IN_PROGRESS', 'FINISHED'] as $status) {
            $r = F::ok(Api::post($path, ['version' => $r['version'], 'status' => $status], $this->admin, F::key()));
            self::assertSame($status, $r['status']);
            $query = $this->pdo->prepare('SELECT status FROM space_occupancies WHERE id = ?');
            $query->execute([$r['occupancy_id']]);
            self::assertSame($status, $query->fetchColumn());
        }
        F::reserve($this->admin, $this->band, $this->space);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
    }

    public function test_E_cancelar_solo_admin_incluso_sin_pagos_y_libera_con_historial(): void
    {
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            [, $owner] = $this->f->actor($role);
            $r = F::reserve($owner, $this->band, $this->space);
            $path = '/api/v1/reservations/' . $r['id'] . '/cancel';
            $body = ['version' => $r['version'], 'reason' => 'Cancelación documentada'];
            $before = F::snapshot($this->pdo);
            self::assertSame(403, Api::post($path, $body, $owner, F::key())->status);
            self::assertSame($before, F::snapshot($this->pdo));
            $cancelled = F::ok(Api::post($path, $body, $this->admin, F::key()));
            self::assertSame('CANCELLED', $cancelled['status']);
            $sale = F::ok(Api::get('/api/v1/sales/' . $r['sale_id'], bearer: $this->admin));
            self::assertSame('CANCELADA', $sale['status']);
        }
        F::reserve($this->admin, $this->band, $this->space);
        $snapshot = F::snapshot($this->pdo);
        self::assertCount(3, $snapshot['reservations']);
        self::assertCount(3, $snapshot['sales']);
        self::assertCount(3, $snapshot['space_occupancies']);
        self::assertSame(['CANCELLED', 'CANCELLED', 'CONFIRMED'], array_column($snapshot['space_occupancies'], 'status'));
        self::assertSame([], $snapshot['payments']);
    }

    public function test_E_cancelar_con_neto_positivo_exige_resolucion_manual_sin_borrar_pago(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $payment = SalesFixtures::pay($this->admin, $r['sale_id'], '150.00')['payment'];
        $path = '/api/v1/reservations/' . $r['id'] . '/cancel';
        $body = ['version' => $r['version'], 'reason' => 'Sesión cancelada'];
        $before = F::snapshot($this->pdo);
        $result = Api::post($path, $body, $this->admin, F::key());
        self::assertSame(409, $result->status);
        self::assertSame('PAYMENTS_REQUIRE_RESOLUTION', $result->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
        SalesFixtures::ok(Api::post('/api/v1/payments/' . $payment['id'] . '/refunds',
            SalesFixtures::refundBody('150.00'), $this->admin, SalesFixtures::key()), 201);
        $current = F::ok(Api::get('/api/v1/reservations/' . $r['id'], bearer: $this->admin));
        $cancelled = F::ok(Api::post($path, array_replace($body, ['version' => $current['version']]), $this->admin, F::key()));
        self::assertSame('CANCELLED', $cancelled['status']);
        $after = F::snapshot($this->pdo);
        self::assertSame($before['payments'], $after['payments']);
        self::assertCount(1, $after['refunds']);
        F::reserve($this->admin, $this->band, $this->space);
    }

    public function test_E_crear_reprogramar_cancelar_idempotentes_y_clave_distinto_cuerpo_conflicto(): void
    {
        $path = '/api/v1/reservations';
        $body = F::body($this->band, $this->space);
        $r = $this->assertReplay($path, $body, 201);
        $r = $this->assertReplay($path . '/' . $r['id'] . '/reschedule', F::move($r));
        $this->assertReplay($path . '/' . $r['id'] . '/cancel', [
            'version' => $r['version'], 'reason' => 'Idempotencia cancelación',
        ]);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function test_E_clave_faltante_o_invalida_no_crea_reserva(): void
    {
        foreach ([[], ['Idempotency-Key' => 'clave con espacios'], ['Idempotency-Key' => str_repeat('a', 201)]] as $headers) {
            $before = F::snapshot($this->pdo);
            $result = Api::post('/api/v1/reservations', F::body($this->band, $this->space), $this->admin, $headers);
            self::assertSame(422, $result->status, $result->json());
            self::assertSame($before, F::snapshot($this->pdo));
        }
    }

    public function test_E_mutaciones_exigen_clave_y_version_coherente_sin_escrituras(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $path = '/api/v1/reservations/' . $r['id'];
        foreach (['/reschedule' => F::move($r),
            '/cancel' => ['version' => $r['version'], 'reason' => 'Falta clave'],
            '/transitions' => ['version' => $r['version'], 'status' => 'IN_PROGRESS'],
        ] as $suffix => $body) {
            $before = F::snapshot($this->pdo);
            self::assertSame(422, Api::post($path . $suffix, $body, $this->admin)->status);
            unset($body['version']);
            self::assertSame(422, Api::post($path . $suffix, $body, $this->admin, F::key())->status);
            self::assertSame($before, F::snapshot($this->pdo));
        }
        $before = F::snapshot($this->pdo);
        self::assertSame(422, Api::post($path . '/reschedule', F::move($r), $this->admin,
            array_replace(F::key(), ['If-Match' => '"999"']))->status);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_A_lider_y_colaborador_solo_propias_lista_meta_detalle_venta_y_mutaciones(): void
    {
        $foreign = F::reserve($this->admin, $this->band, $this->space);
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $index => $role) {
            [$user, $actor] = $this->f->actor($role);
            $own = F::reserve($actor, $this->band, $this->space, [
                'start_at' => '2030-01-0' . ($index + 8) . 'T10:00:00Z',
                'end_at' => '2030-01-0' . ($index + 8) . 'T11:30:00Z',
            ]);
            self::assertSame((string) $user['id'], $own['responsible_id']);
            F::ok(Api::get('/api/v1/reservations/' . $own['id'], bearer: $actor));
            $list = Api::get('/api/v1/reservations', F::range(), $actor);
            F::ok($list);
            self::assertSame(1, (int) $list->meta()['total']);
            self::assertSame([$own['id']], array_column($list->items(), 'id'));
            $path = '/api/v1/reservations/' . $foreign['id'];
            foreach ([Api::get($path, bearer: $actor),
                Api::get('/api/v1/sales/' . $foreign['sale_id'], bearer: $actor),
                Api::get('/api/v1/sales/' . $foreign['sale_id'] . '/ticket', bearer: $actor),
                Api::post($path . '/reschedule', F::move($foreign), $actor, F::key()),
                Api::patch($path, ['version' => $foreign['version'], 'notes' => 'Intrusión'], $actor, F::key()),
                Api::post($path . '/transitions', ['version' => $foreign['version'], 'status' => 'IN_PROGRESS'], $actor, F::key()),
            ] as $denied) {
                self::assertContains($denied->status, [403, 404], $denied->json());
                self::assertStringNotContainsString('Nota privada de reserva QA', $denied->json());
                self::assertStringNotContainsString('180.00', $denied->json());
            }
        }
        $all = Api::get('/api/v1/reservations', F::range(), $this->admin);
        F::ok($all);
        self::assertSame(3, (int) $all->meta()['total']);
    }

    public function test_A_replay_reautoriza_responsabilidad_actual(): void
    {
        [, $owner] = $this->f->actor(Role::COLABORADOR);
        $key = F::key();
        $body = F::body($this->band, $this->space);
        $r = F::ok(Api::post('/api/v1/reservations', $body, $owner, $key), 201);
        $moveKey = F::key();
        $moveBody = F::move($r);
        $r = F::ok(Api::post('/api/v1/reservations/' . $r['id'] . '/reschedule', $moveBody, $owner, $moveKey));
        // Administrative reassignment fixture: exercise replay access, not an invented API route.
        $this->pdo->prepare('UPDATE reservations SET responsible_id = ? WHERE id = ?')->execute([$this->adminUser['id'], $r['id']]);
        $this->pdo->prepare('UPDATE sales SET responsible_id = ? WHERE id = ?')->execute([$this->adminUser['id'], $r['sale_id']]);
        $before = F::snapshot($this->pdo);
        $replay = Api::post('/api/v1/reservations', $body, $owner, $key);
        self::assertContains($replay->status, [403, 404], $replay->json());
        $replay = Api::post('/api/v1/reservations/' . $r['id'] . '/reschedule', $moveBody, $owner, $moveKey);
        self::assertContains($replay->status, [403, 404], $replay->json());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_A_cancelacion_replay_no_ignora_revocacion_del_rol_admin(): void
    {
        [$operator, $token] = $this->f->actor();
        $r = F::reserve($token, $this->band, $this->space);
        $path = '/api/v1/reservations/' . $r['id'] . '/cancel';
        $body = ['version' => $r['version'], 'reason' => 'Operación antes de revocar permiso'];
        $key = F::key();
        F::ok(Api::post($path, $body, $token, $key));
        $this->pdo->prepare('UPDATE users SET role_id = ? WHERE id = ?')->execute([
            $this->fixtures->roleId(Role::COLABORADOR), $operator['id'],
        ]);
        $before = F::snapshot($this->pdo);
        self::assertSame(403, Api::post($path, $body, $token, $key)->status);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_venta_reserva_no_admite_cancelacion_aislada_y_cobro_va_solo_a_sale_id(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $sale = F::ok(Api::get('/api/v1/sales/' . $r['sale_id'], bearer: $this->admin));
        $before = F::snapshot($this->pdo);
        $denied = Api::post('/api/v1/sales/' . $r['sale_id'] . '/cancel', [
            'version' => $sale['version'], 'reason' => 'No omitir coordinador',
        ], $this->admin, F::key());
        self::assertSame(409, $denied->status);
        self::assertSame($before, F::snapshot($this->pdo));
        SalesFixtures::pay($this->admin, $r['sale_id'], '180.00');
        $rows = F::snapshot($this->pdo);
        self::assertCount(1, $rows['sales']);
        self::assertCount(1, $rows['reservations']);
        self::assertCount(1, $rows['payments']);
        self::assertSame($r['sale_id'], (string) $rows['payments'][0]['sale_id']);
        self::assertNull($rows['payments'][0]['project_id']);
        self::assertSame('180.00', $rows['payments'][0]['amount']);
        // Dashboard aggregation itself belongs to phase 6; no invented endpoint here.
    }

    public function test_E_total_cero_sin_cargo_y_cancelacion_sin_pago_ficticio(): void
    {
        $free = $this->f->space($this->admin, '0.00');
        $r = F::reserve($this->admin, $this->band, $free);
        self::assertSame('0.00', $r['total']);
        self::assertSame('SIN_CARGO', $r['financial_summary']['payment_status']);
        F::ok(Api::post('/api/v1/reservations/' . $r['id'] . '/cancel', [
            'version' => $r['version'], 'reason' => 'Sin cargo también exige ADMIN',
        ], $this->admin, F::key()));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_E_notas_patch_no_permite_bypass_de_horario_y_if_match_funciona(): void
    {
        $r = F::reserve($this->admin, $this->band, $this->space);
        $path = '/api/v1/reservations/' . $r['id'];
        $before = F::snapshot($this->pdo);
        self::assertSame(422, Api::patch($path, F::move($r), $this->admin, F::key())->status);
        self::assertSame($before, F::snapshot($this->pdo));
        $headers = array_replace(F::key(), ['If-Match' => '"' . $r['version'] . '"']);
        $updated = F::ok(Api::patch($path, ['notes' => "<script>alert(1)</script> ' OR 1=1 --"], $this->admin, $headers));
        self::assertSame("<script>alert(1)</script> ' OR 1=1 --", $updated['notes']);
        self::assertSame($r['occupancy_id'], $updated['occupancy_id']);
        $before = F::snapshot($this->pdo);
        $headers = array_replace(F::key(), ['If-Match' => '"' . $r['version'] . '"']);
        self::assertSame(409, Api::patch($path, ['notes' => 'Obsoleto'], $this->admin, $headers)->status);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_fecha_sin_zona_fin_invalido_y_bloque_fraccionado_rechazados(): void
    {
        foreach ([['start_at' => '2030-01-07T10:00:00'], ['end_at' => '2030-01-07T09:00:00Z'],
            ['start_at' => '2030-01-07T10:01:00Z'], ['end_at' => '2030-01-07T11:31:00Z'],
            ['start_at' => '2030-01-07T10:00:01Z']] as $override) {
            $before = F::snapshot($this->pdo);
            $result = Api::post('/api/v1/reservations', F::body($this->band, $this->space, $override), $this->admin, F::key());
            self::assertSame(422, $result->status, $result->json());
            self::assertSame($before, F::snapshot($this->pdo));
        }
    }

    public function test_E_retrospectiva_exige_admin_y_motivo(): void
    {
        $past = ['start_at' => '2020-01-07T10:00:00Z', 'end_at' => '2020-01-07T11:30:00Z'];
        self::assertSame(422, Api::post('/api/v1/reservations', F::body($this->band, $this->space, $past),
            $this->admin, F::key())->status);
        [, $collaborator] = $this->f->actor(Role::COLABORADOR);
        $withReason = array_replace($past, ['reason' => 'Carga histórica justificada QA']);
        $denied = Api::post('/api/v1/reservations', F::body($this->band, $this->space, $withReason), $collaborator, F::key());
        self::assertContains($denied->status, [403, 422], $denied->json());
        $r = F::reserve($this->admin, $this->band, $this->space, $withReason);
        self::assertSame('2020-01-07T10:00:00Z', $r['start_at']);
    }

    public function test_C_ticket_reserva_anexa_banda_sala_horario_reimprimir_no_mueve_dinero(): void
    {
        $this->band = $this->f->band((int) $this->adminUser['id'], ['name' => 'Banda <script>alert(1)</script>']);
        $r = F::reserve($this->admin, $this->band, $this->space, ['notes' => '<script>alert(1)</script>']);
        $before = F::snapshot($this->pdo);
        foreach (['80mm', 'A4'] as $paper) {
            $ticket = Api::get('/api/v1/sales/' . $r['sale_id'] . '/ticket', ['paper' => $paper], $this->admin);
            self::assertSame(200, $ticket->status, $ticket->json());
            $text = html_entity_decode(strip_tags($ticket->rawBody), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringContainsString($this->band['name'], $text);
            self::assertStringContainsString($this->space['name'], $text);
            foreach (['start_at', 'end_at'] as $field) {
                $local = (new DateTimeImmutable($r[$field]))->setTimezone(new DateTimeZone('America/Mexico_City'));
                self::assertStringContainsString($local->format('d/m/Y H:i'), $text);
            }
            self::assertStringContainsString('180.00', $text);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $ticket->rawBody);
            self::assertStringNotContainsString('<script>alert(1)</script>', $ticket->rawBody);
        }
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_H_busqueda_sql_maliciosa_no_elude_aislamiento_ni_modifica_filas(): void
    {
        F::reserve($this->admin, $this->band, $this->space);
        [, $other] = $this->f->actor(Role::COLABORADOR);
        $before = F::snapshot($this->pdo);
        $result = Api::get('/api/v1/reservations', array_replace(F::range(), ['q' => "' OR 1=1 --"]), $other);
        F::ok($result);
        self::assertSame([], $result->items());
        self::assertSame(0, (int) $result->meta()['total']);
        self::assertSame($before, F::snapshot($this->pdo));
    }

    private function assertReplay(string $path, array $body, int $status = 200): array
    {
        $key = F::key();
        $first = Api::post($path, $body, $this->admin, $key);
        $result = F::ok($first, $status);
        $before = F::snapshot($this->pdo);
        $replay = Api::post($path, $body, $this->admin, $key);
        self::assertSame($first->status, $replay->status);
        self::assertSame($result, $replay->data());
        self::assertSame($before, F::snapshot($this->pdo));
        $different = array_replace($body, str_ends_with($path, '/cancel')
            ? ['reason' => 'Distinto motivo'] : (str_ends_with($path, '/reschedule')
                ? ['end_at' => '2030-01-08T12:00:00Z'] : ['notes' => 'Otro cuerpo']));
        $conflict = Api::post($path, $different, $this->admin, $key);
        self::assertSame(409, $conflict->status);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
        return $result;
    }
}
