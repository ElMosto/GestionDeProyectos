<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures as F;

final class SalesApiTest extends DatabaseTestCase
{
    public function test_D_formula_120_y_snapshot_se_congela_al_emitir_no_al_crear(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $product = $f->product($admin);
        $extra = $f->extra($admin);
        $f->link($admin, $product, [$extra]);
        $draft = $f->draft($admin, [F::item($product, '2.000', [
            ['extra_id' => $extra['id'], 'quantity_per_unit' => '1.000'],
        ])]);
        self::assertSame('120.00', $draft['total']);
        $current = F::ok(Api::get('/api/v1/products/' . $product['id'], bearer: $admin));
        $changed = F::ok(Api::patch('/api/v1/products/' . $product['id'], [
            'version' => $current['version'], 'price' => '60.00', 'name' => 'Nombre al emitir',
        ], $admin));
        $issued = $f->issue($admin, $draft);
        self::assertSame('140.00', $issued['total']);
        self::assertSame('Nombre al emitir', $issued['items'][0]['name_snapshot']);
        F::ok(Api::patch('/api/v1/products/' . $product['id'], [
            'version' => $changed['version'], 'price' => '500.00', 'name' => 'Nombre posterior',
        ], $admin));
        F::ok(Api::patch('/api/v1/extras/' . $extra['id'], [
            'version' => $extra['version'], 'price' => '100.00', 'name' => 'Extra posterior',
        ], $admin));
        $after = F::ok(Api::get('/api/v1/sales/' . $issued['id'], bearer: $admin));
        self::assertSame('140.00', $after['total']);
        self::assertSame('60.00', $after['items'][0]['unit_price']);
        self::assertSame('Nombre al emitir', $after['items'][0]['name_snapshot']);
        self::assertSame('10.00', $after['items'][0]['extras'][0]['unit_price']);
        self::assertSame($extra['name'], $after['items'][0]['extras'][0]['name_snapshot']);
    }

    public function test_D_formula_sin_redondeo_intermedio_y_partidas_redondeadas_por_separado(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $product = $f->product($admin, '0.00');
        $extra = $f->extra($admin, '0.01');
        $f->link($admin, $product, [$extra]);
        $sale = $f->draft($admin, [F::item($product, '2.000', [
            ['extra_id' => $extra['id'], 'quantity_per_unit' => '0.500'],
        ])]);
        self::assertSame('0.01', $sale['total']);
        $cent = $f->product($admin, '0.01');
        $sale = $f->draft($admin, [F::item($cent, '0.500'), F::item($cent, '0.500')]);
        self::assertSame('0.02', $sale['total']);
    }

    public function test_D_extra_ajeno_precio_forjado_concepto_libre_y_tipo_reserva_rechazados(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $collaborator] = $f->actor(Role::COLABORADOR);
        $product = $f->product($admin);
        $extra = $f->extra($admin);
        $bodies = [
            ['items' => [F::item($product, extras: [['extra_id' => $extra['id'], 'quantity_per_unit' => '1.000']])]],
            ['items' => [array_replace(F::item($product), ['unit_price' => '0.01'])]],
            ['items' => [['product_id' => null, 'name' => 'Libre', 'unit_price' => '1.00', 'quantity' => '1.000', 'extras' => []]]],
            ['items' => [F::item($product)], 'type' => 'RESERVA'],
            ['items' => [F::item($product)], 'total' => '0.00'],
        ];
        foreach ($bodies as $index => $body) {
            $result = Api::post('/api/v1/sales', $body, $collaborator, F::key());
            self::assertSame($index === 2 ? 403 : 422, $result->status, $result->json());
            self::assertSame($index === 2 ? 'FORBIDDEN' : 'VALIDATION_FAILED', $result->errorCode());
        }
        $allowed = $f->draft($admin, $bodies[2]['items']);
        self::assertSame('1.00', $allowed['total']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function test_D_borrador_modificable_emitida_inmutable_y_version_obsoleta(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $draft = $f->draft($admin, [F::item($f->product($admin))]);
        $updated = F::ok(Api::patch('/api/v1/sales/' . $draft['id'], [
            'notes' => 'Editable', 'version' => $draft['version'],
        ], $admin));
        self::assertSame(409, Api::patch('/api/v1/sales/' . $draft['id'], [
            'notes' => 'Obsoleto', 'version' => $draft['version'],
        ], $admin)->status);
        $issued = $f->issue($admin, $updated);
        $result = Api::patch('/api/v1/sales/' . $issued['id'], [
            'notes' => 'Mutación prohibida', 'version' => $issued['version'],
        ], $admin);
        self::assertSame(409, $result->status);
        self::assertSame('SALE_NOT_DRAFT', $result->errorCode());
    }

    public function test_D_creacion_emision_y_cancelacion_idempotentes_con_mismo_status_y_sin_duplicados(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $body = ['items' => [F::item($f->product($admin))]];
        $key = F::key();
        $first = Api::post('/api/v1/sales', $body, $admin, $key);
        $draft = F::ok($first, 201);
        $replay = Api::post('/api/v1/sales', $body, $admin, $key);
        self::assertSame($first->status, $replay->status);
        self::assertSame($first->data(), $replay->data());
        $conflict = Api::post('/api/v1/sales', array_replace($body, ['notes' => 'Otro cuerpo']), $admin, $key);
        self::assertSame(409, $conflict->status);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->errorCode());
        $path = '/api/v1/sales/' . $draft['id'];
        $key = F::key();
        $body = ['version' => $draft['version']];
        $first = Api::post($path . '/issue', $body, $admin, $key);
        $issued = F::ok($first);
        $replay = Api::post($path . '/issue', $body, $admin, $key);
        self::assertSame($first->status, $replay->status);
        self::assertSame($first->data(), $replay->data());
        $body = ['version' => $issued['version'], 'reason' => 'Error documentado'];
        $key = F::key();
        $first = Api::post($path . '/cancel', $body, $admin, $key);
        F::ok($first);
        $replay = Api::post($path . '/cancel', $body, $admin, $key);
        self::assertSame($first->status, $replay->status);
        self::assertSame($first->data(), $replay->data());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_A_D_venta_y_replay_reautorizan_propietario_actual_sin_filtrar_finanzas(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [$owner, $token] = $f->actor(Role::COLABORADOR);
        [$other, $foreign] = $f->actor(Role::COLABORADOR);
        $body = ['items' => [F::item($f->product($admin, '987.65'))]];
        $key = F::key();
        $draft = F::ok(Api::post('/api/v1/sales', $body, $token, $key), 201);
        foreach (['', '/payments', '/ticket'] as $suffix) {
            $denied = Api::get('/api/v1/sales/' . $draft['id'] . $suffix, bearer: $foreign);
            self::assertContains($denied->status, [403, 404]);
            self::assertStringNotContainsString('987.65', $denied->rawBody);
        }
        // Initial ownership change models a reassignment; no production reassignment API is presumed.
        $this->pdo->prepare('UPDATE sales SET responsible_id = ? WHERE id = ?')->execute([$other['id'], $draft['id']]);
        $denied = Api::post('/api/v1/sales', $body, $token, $key);
        self::assertContains($denied->status, [403, 404]);
        self::assertStringNotContainsString('987.65', $denied->json());
    }

    public function test_D_cancelar_solo_admin_motivo_obligatorio_y_borrador_no_inventa_emision(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $leader] = $f->actor(Role::LIDER_PROYECTO);
        $draft = $f->draft($leader, [F::item($f->product($admin))]);
        $path = '/api/v1/sales/' . $draft['id'] . '/cancel';
        $body = ['version' => $draft['version'], 'reason' => 'Cancelación'];
        self::assertSame(403, Api::post($path, $body, $leader, F::key())->status);
        self::assertSame(422, Api::post($path, ['version' => $draft['version']], $admin, F::key())->status);
        $cancelled = F::ok(Api::post($path, $body, $admin, F::key()));
        self::assertSame('CANCELADA', $cancelled['status']);
        self::assertNull($cancelled['issued_at']);
    }

    public function test_C_H_ticket_coincide_con_api_escapa_xss_y_reimprimir_no_crea_movimientos(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $longName = '<script>alert(1)</script>' . str_repeat(' Texto largo', 11);
        $product = $f->product($admin, '1000.00', ['name' => $longName]);
        $sale = $f->issue($admin, $f->draft($admin, [F::item($product)], ['notes' => str_repeat('Texto largo ', 200)]));
        F::pay($admin, $sale['id']);
        $before = $this->counts();
        foreach (['80mm', 'A4'] as $paper) {
            $ticket = Api::get('/api/v1/sales/' . $sale['id'] . '/ticket', ['paper' => $paper], $admin);
            self::assertSame(200, $ticket->status, $ticket->rawBody);
            self::assertStringContainsString('text/html', (string) $ticket->contentType());
            self::assertStringContainsString('Comprobante interno; no es factura fiscal', $ticket->rawBody);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $ticket->rawBody);
            self::assertStringNotContainsString('<script>alert(1)</script>', $ticket->rawBody);
            self::assertMatchesRegularExpression('/1,?000\.00/', $ticket->rawBody);
            // §10 requires item names, not optional sale notes, on the ticket.
            self::assertStringContainsString(htmlspecialchars($longName, ENT_QUOTES, 'UTF-8'), $ticket->rawBody);
            foreach (['300.00', '700.00'] as $amount) {
                self::assertStringContainsString($amount, $ticket->rawBody);
            }
        }
        self::assertSame($before, $this->counts());
    }

    private function counts(): array
    {
        return array_map(fn (string $table): int => (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
            ['sales', 'payments', 'refunds']);
    }
}
