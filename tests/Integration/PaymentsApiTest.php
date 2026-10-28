<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures;
use App\Tests\Support\SalesFixtures as F;

final class PaymentsApiTest extends DatabaseTestCase
{
    public function test_D_abono_300_de_1000_parcial_saldo700_y_resto_pagado(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $partial = F::pay($admin, $sale['id']);
        self::assertSame('PARCIAL', $partial['financial_summary']['payment_status']);
        self::assertSame('700.00', $partial['financial_summary']['balance']);
        self::assertSame('300.00', $partial['financial_summary']['net_paid']);
        self::assertSame('2026-10-15T18:00:00Z', $partial['payment']['effective_at']);
        self::assertIsString($partial['payment']['id']);
        self::assertSame('REGISTRADO', $partial['payment']['status']);
        self::assertArrayNotHasKey('idempotency_key', $partial['payment']);
        $paid = F::pay($admin, $sale['id'], '700.00');
        self::assertSame('PAGADO', $paid['financial_summary']['payment_status']);
        self::assertSame('0.00', $paid['financial_summary']['balance']);
        $over = Api::post('/api/v1/sales/' . $sale['id'] . '/payments', F::paymentBody('0.01'), $admin, F::key());
        self::assertSame(409, $over->status);
        self::assertSame('OVERPAYMENT', $over->errorCode());
        self::assertSame(2, $this->countRows('payments'));
    }

    public function test_D_pago_idempotente_mismo_status_y_cuerpo_distinto_409(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $path = '/api/v1/sales/' . $sale['id'] . '/payments';
        $key = F::key();
        $first = Api::post($path, F::paymentBody(), $admin, $key);
        F::ok($first, 201);
        $again = Api::post($path, F::paymentBody(), $admin, $key);
        self::assertSame($first->status, $again->status);
        self::assertSame($first->data(), $again->data());
        $conflict = Api::post($path, F::paymentBody('301.00'), $admin, $key);
        self::assertSame(409, $conflict->status);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->errorCode());
        self::assertSame(1, $this->countRows('payments'));
    }

    public function test_D_misma_clave_http_distintos_actores_no_colisiona_unique_global_D19(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $owner] = $f->actor(Role::COLABORADOR);
        $sale = $f->issued($admin, ownerToken: $owner);
        $path = '/api/v1/sales/' . $sale['id'] . '/payments';
        $key = F::key();
        F::ok(Api::post($path, F::paymentBody(), $admin, $key), 201);
        F::ok(Api::post($path, F::paymentBody(), $owner, $key), 201);
        self::assertSame(2, $this->countRows('payments'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(DISTINCT idempotency_key) FROM payments')->fetchColumn());
        self::assertSame('600.00', F::ok(Api::get('/api/v1/sales/' . $sale['id'], bearer: $owner))['financial_summary']['net_paid']);
    }

    public function test_D_pago_rechaza_importes_invalidos_float_y_fecha_sin_zona(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $path = '/api/v1/sales/' . $sale['id'] . '/payments';
        foreach (['-1.00', '0.00', '1', '1.001', 1.5, 1] as $amount) {
            $body = F::paymentBody(overrides: ['amount' => $amount]);
            $result = Api::post($path, $body, $admin, F::key());
            self::assertSame(422, $result->status, $result->json());
        }
        foreach ([['effective_at' => '2026-10-15T12:00:00'], ['method' => 'CARD'], ['total' => '1.00']] as $invalid) {
            self::assertSame(422, Api::post($path, F::paymentBody(overrides: $invalid), $admin, F::key())->status);
        }
        self::assertSame(422, Api::post($path, F::paymentBody(), $admin)->status);
        self::assertSame(0, $this->countRows('payments'));
    }

    public function test_D_version_opcional_if_match_obsoleto_rechaza_sin_efecto(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        F::pay($admin, $sale['id']);
        $result = Api::post('/api/v1/sales/' . $sale['id'] . '/payments', F::paymentBody(), $admin,
            F::key() + ['If-Match' => '"' . $sale['version'] . '"']);
        self::assertSame(409, $result->status);
        self::assertSame('VERSION_CONFLICT', $result->errorCode());
        self::assertSame(1, $this->countRows('payments'));
    }

    public function test_D_marcar_pagada_registra_solo_saldo_y_replay_no_duplica(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        F::pay($admin, $sale['id']);
        $body = F::paymentBody();
        unset($body['amount']);
        $path = '/api/v1/sales/' . $sale['id'] . '/mark-paid';
        $key = F::key();
        $first = Api::post($path, $body, $admin, $key);
        $data = F::ok($first);
        self::assertSame('700.00', $data['payment']['amount']);
        self::assertSame('PAGADO', $data['financial_summary']['payment_status']);
        $replay = Api::post($path, $body, $admin, $key);
        self::assertSame($first->status, $replay->status);
        self::assertSame($first->data(), $replay->data());
        self::assertSame(2, $this->countRows('payments'));
    }

    public function test_D_total_cero_sin_cargo_sin_pago_ficticio(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin, '0.00');
        self::assertSame('SIN_CARGO', $sale['financial_summary']['payment_status']);
        $body = F::paymentBody();
        unset($body['amount']);
        $marked = F::ok(Api::post('/api/v1/sales/' . $sale['id'] . '/mark-paid', $body, $admin, F::key()));
        self::assertNull($marked['payment']);
        self::assertSame('SIN_CARGO', $marked['financial_summary']['payment_status']);
        self::assertSame(0, $this->countRows('payments'));
    }

    public function test_D_anular_solo_admin_con_motivo_conserva_historia_y_es_idempotente(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $owner] = $f->actor(Role::LIDER_PROYECTO);
        $sale = $f->issued($admin, ownerToken: $owner);
        $payment = F::pay($owner, $sale['id'])['payment'];
        $path = '/api/v1/payments/' . $payment['id'] . '/void';
        $body = ['reason' => 'Error de captura'];
        self::assertSame(403, Api::post($path, $body, $owner, F::key())->status);
        self::assertSame(422, Api::post($path, [], $admin, F::key())->status);
        $key = F::key();
        $first = Api::post($path, $body, $admin, $key);
        $data = F::ok($first);
        self::assertSame('ANULADO', $data['payment']['status']);
        self::assertSame('Error de captura', $data['payment']['void_reason']);
        self::assertSame('NO_PAGADO', $data['financial_summary']['payment_status']);
        self::assertSame('0.00', $data['financial_summary']['net_paid']);
        $again = Api::post($path, $body, $admin, $key);
        self::assertSame($first->status, $again->status);
        self::assertSame($first->data(), $again->data());
        self::assertSame(409, Api::post($path, ['reason' => 'Otro'], $admin, $key)->status);
        self::assertSame(1, $this->countRows('payments'));
        $history = F::ok(Api::get('/api/v1/sales/' . $sale['id'] . '/payments', bearer: $owner));
        self::assertCount(1, $history);
        self::assertSame('ANULADO', $history[0]['status']);
        $refund = Api::post('/api/v1/payments/' . $payment['id'] . '/refunds', F::refundBody('1.00'), $admin, F::key());
        self::assertSame(409, $refund->status);
        self::assertSame('PAYMENT_VOIDED', $refund->errorCode());
    }

    public function test_D_reembolso_limitado_admin_idempotente_y_pago_con_reembolso_no_anulable(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $owner] = $f->actor(Role::COLABORADOR);
        $sale = $f->issued($admin, ownerToken: $owner);
        $payment = F::pay($owner, $sale['id'])['payment'];
        $path = '/api/v1/payments/' . $payment['id'] . '/refunds';
        self::assertSame(403, Api::post($path, F::refundBody('100.00'), $owner, F::key())->status);
        $key = F::key();
        $body = F::refundBody('100.00');
        $first = Api::post($path, $body, $admin, $key);
        $data = F::ok($first, 201);
        self::assertSame('200.00', $data['financial_summary']['net_paid']);
        self::assertSame('800.00', $data['financial_summary']['balance']);
        $again = Api::post($path, $body, $admin, $key);
        self::assertSame($first->status, $again->status);
        self::assertSame($first->data(), $again->data());
        self::assertSame(409, Api::post($path, F::refundBody('99.00'), $admin, $key)->status);
        $excess = Api::post($path, F::refundBody('200.01'), $admin, F::key());
        self::assertSame(409, $excess->status);
        self::assertSame('REFUND_EXCEEDS_PAYMENT', $excess->errorCode());
        $void = Api::post('/api/v1/payments/' . $payment['id'] . '/void', ['reason' => 'Error'], $admin, F::key());
        self::assertSame(409, $void->status);
        self::assertSame('PAYMENT_HAS_REFUNDS', $void->errorCode());
        self::assertSame(1, $this->countRows('refunds'));
        $history = F::ok(Api::get($path, bearer: $owner));
        self::assertCount(1, $history);
        self::assertSame('100.00', $history[0]['amount']);
    }

    public function test_A_D_pagos_ajenos_denegados_y_replay_reautoriza_nuevo_propietario(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $owner] = $f->actor(Role::COLABORADOR);
        [$other, $foreign] = $f->actor(Role::COLABORADOR);
        $sale = $f->issued($admin, ownerToken: $owner);
        $path = '/api/v1/sales/' . $sale['id'] . '/payments';
        $key = F::key();
        $payment = F::ok(Api::post($path, F::paymentBody(), $owner, $key), 201)['payment'];
        foreach ([$path, '/api/v1/payments/' . $payment['id'] . '/refunds'] as $read) {
            $result = Api::get($read, bearer: $foreign);
            self::assertContains($result->status, [403, 404]);
            self::assertStringNotContainsString('300.00', $result->json());
        }
        self::assertContains(Api::post($path, F::paymentBody(), $foreign, F::key())->status, [403, 404]);
        $this->pdo->prepare('UPDATE sales SET responsible_id = ? WHERE id = ?')->execute([$other['id'], $sale['id']]);
        $denied = Api::post($path, F::paymentBody(), $owner, $key);
        self::assertContains($denied->status, [403, 404]);
        self::assertStringNotContainsString('300.00', $denied->json());
        self::assertSame(1, $this->countRows('payments'));
    }

    public function test_D_cancelacion_con_neto_positivo_rechaza_sin_reembolsos_automaticos(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $paid = F::pay($admin, $sale['id']);
        $current = F::ok(Api::get('/api/v1/sales/' . $sale['id'], bearer: $admin));
        $cancel = Api::post('/api/v1/sales/' . $sale['id'] . '/cancel', [
            'version' => $current['version'], 'reason' => 'Petición del cliente',
        ], $admin, F::key());
        self::assertSame(409, $cancel->status);
        self::assertSame('PAYMENTS_REQUIRE_RESOLUTION', $cancel->errorCode());
        self::assertSame(0, $this->countRows('refunds'));
        self::assertSame('REGISTRADO', $this->pdo->query('SELECT status FROM payments')->fetchColumn());
    }

    public function test_A_D_replay_de_reembolso_revalida_rol_admin_actual(): void
    {
        $f = new F($this->fixtures);
        [$administrator, $admin] = $f->actor();
        $f->actor(); // Another active administrator remains.
        $sale = $f->issued($admin);
        $payment = F::pay($admin, $sale['id'])['payment'];
        $path = '/api/v1/payments/' . $payment['id'] . '/refunds';
        $key = F::key();
        $body = F::refundBody('100.00');
        F::ok(Api::post($path, $body, $admin, $key), 201);
        $this->pdo->prepare('UPDATE users SET role_id = ? WHERE id = ?')->execute([
            $this->fixtures->roleId(Role::COLABORADOR), $administrator['id'],
        ]);
        $denied = Api::post($path, $body, $admin, $key);
        self::assertSame(403, $denied->status);
        self::assertStringNotContainsString('100.00', $denied->json());
        self::assertSame(1, $this->countRows('refunds'));
    }

    public function test_D_solo_venta_emitida_recibe_cobros(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $draft = $f->draft($admin, [F::item($f->product($admin))]);
        $path = '/api/v1/sales/' . $draft['id'];
        self::assertContains(Api::post($path . '/payments', F::paymentBody('1.00'), $admin, F::key())->status, [403, 409, 422]);
        $cancelled = F::ok(Api::post($path . '/cancel', [
            'version' => $draft['version'], 'reason' => 'Nunca emitida',
        ], $admin, F::key()));
        self::assertSame('CANCELADA', $cancelled['status']);
        self::assertContains(Api::post($path . '/payments', F::paymentBody('1.00'), $admin, F::key())->status, [403, 409, 422]);
        self::assertSame(0, $this->countRows('payments'));
    }

    public function test_B_D_proyecto_interno_no_cobrable_y_finanzas_ajenas_denegadas(): void
    {
        $f = new F($this->fixtures);
        [$leader, $owner] = $f->actor(Role::LIDER_PROYECTO);
        [, $foreign] = $f->actor(Role::LIDER_PROYECTO);
        [$collaborator, $member] = $f->actor(Role::COLABORADOR);
        $projects = new ProjectFixtures($this->fixtures);
        $project = $projects->project($owner, $leader['id'], ['base_amount' => '1000.00']);
        $projects->members($owner, $project['id'], [$collaborator['id']]);
        $path = '/api/v1/projects/' . $project['id'] . '/payments';
        foreach ([$foreign, $member] as $token) {
            self::assertContains(Api::get($path, bearer: $token)->status, [403, 404]);
            self::assertContains(Api::post($path, F::paymentBody(), $token, F::key())->status, [403, 404]);
        }
        // §5/§11: nonbillable documents have no payment capability (403), not a payable balance.
        $notBillable = Api::post($path, F::paymentBody(), $owner, F::key());
        self::assertSame(403, $notBillable->status);
        self::assertSame('FORBIDDEN', $notBillable->errorCode());
        self::assertSame(0, $this->countRows('payments'));
    }

    public function test_D_proyecto_finalizado_cobrable_admite_saldo_al_lider_y_admin(): void
    {
        $f = new F($this->fixtures);
        [$leader, $owner] = $f->actor(Role::LIDER_PROYECTO);
        [$administrator, $admin] = $f->actor();
        $client = $this->fixtures->createClient($administrator['id']);
        $project = (new ProjectFixtures($this->fixtures))->project($owner, $leader['id'], [
            'type' => 'EXTERNO', 'billable' => true, 'client_id' => (string) $client['id'], 'base_amount' => '1000.00',
        ]);
        // Initial state only: no task completion or transition is under test here.
        $this->pdo->prepare("UPDATE projects SET status = 'FINALIZADO' WHERE id = ?")->execute([$project['id']]);
        $path = '/api/v1/projects/' . $project['id'] . '/payments';
        $partial = F::ok(Api::post($path, F::paymentBody(), $owner, F::key()), 201);
        self::assertSame('PARCIAL', $partial['financial_summary']['payment_status']);
        $paid = F::ok(Api::post($path, F::paymentBody('700.00'), $admin, F::key()), 201);
        self::assertSame('PAGADO', $paid['financial_summary']['payment_status']);
    }

    public function test_D_reserva_cobra_por_venta_unica_pero_no_cancela_directamente(): void
    {
        $f = new F($this->fixtures);
        [$administrator, $admin] = $f->actor();
        $band = $this->fixtures->createClient($administrator['id'], ['type' => 'BANDA']);
        $reservation = $this->fixtures->createFutureReservation($band['id'], $administrator['id']);
        $saleId = (string) $reservation['sale_id'];
        $paid = F::pay($admin, $saleId, '500.00');
        self::assertSame('PAGADO', $paid['financial_summary']['payment_status']);
        F::ok(Api::post('/api/v1/payments/' . $paid['payment']['id'] . '/void', ['reason' => 'Prueba'], $admin, F::key()));
        $sale = F::ok(Api::get('/api/v1/sales/' . $saleId, bearer: $admin));
        $cancel = Api::post('/api/v1/sales/' . $saleId . '/cancel', [
            'version' => $sale['version'], 'reason' => 'Intento de saltar ocupación',
        ], $admin, F::key());
        self::assertSame(409, $cancel->status);
        self::assertSame('RESERVATION_MANAGED', $cancel->errorCode());
        self::assertSame(1, $this->countRows('sales'));
        self::assertSame('CONFIRMED', $this->pdo->query('SELECT status FROM space_occupancies')->fetchColumn());
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
