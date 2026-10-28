<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ReservationFixtures as F;

/** §12, §13, §19.A/E/H; real router and MariaDB, not HTTP transport evidence. */
final class SpacesApiTest extends DatabaseTestCase
{
    public function test_E_admin_configura_tarifa_bloques_y_siete_ventanas_con_extremo_24(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $space = $f->space($admin);
        self::assertSame('120.00', $space['hourly_rate']);
        self::assertSame(30, (int) $space['block_minutes']);
        self::assertCount(7, $space['hours']);
        self::assertSame(['24:00:00'], array_values(array_unique(array_column($space['hours'], 'local_end_time'))));
        $changed = F::ok(Api::patch('/api/v1/spaces/' . $space['id'], [
            'version' => $space['version'], 'hourly_rate' => '150.00',
        ], $admin));
        self::assertSame('150.00', $changed['hourly_rate']);
        self::assertGreaterThan((int) $space['version'], (int) $changed['version']);
    }

    public function test_A_todos_ven_espacios_pero_solo_admin_configura_y_ve_tarifa(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $space = $f->space($admin);
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            [, $actor] = $f->actor($role);
            $detail = F::ok(Api::get('/api/v1/spaces/' . $space['id'], bearer: $actor));
            self::assertArrayNotHasKey('hourly_rate', $detail);
            $list = Api::get('/api/v1/spaces', bearer: $actor);
            F::ok($list);
            self::assertArrayNotHasKey('hourly_rate', $list->items()[0]);
            self::assertSame(403, Api::post('/api/v1/spaces', [
                'name' => 'Prohibida', 'hourly_rate' => '1.00',
            ], $actor)->status);
            self::assertSame(403, Api::patch('/api/v1/spaces/' . $space['id'], [
                'version' => $space['version'], 'hourly_rate' => '1.00',
            ], $actor)->status);
            self::assertSame(403, Api::call('PUT', '/api/v1/spaces/' . $space['id'] . '/hours', [
                'version' => $space['version'], 'hours' => [],
            ], bearer: $actor)->status);
        }
    }

    public function test_E_version_obsoleta_y_ventanas_solapadas_no_sobrescriben_configuracion(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $space = $f->space($admin);
        $path = '/api/v1/spaces/' . $space['id'];
        F::ok(Api::patch($path, ['version' => $space['version'], 'description' => 'Nueva'], $admin));
        $before = F::ok(Api::get($path, bearer: $admin));
        $old = Api::call('PUT', $path . '/hours', ['version' => $space['version'], 'hours' => []], bearer: $admin);
        self::assertSame(409, $old->status);
        self::assertSame('VERSION_CONFLICT', $old->errorCode());
        $bad = F::hours();
        $bad[] = ['weekday' => 1, 'local_start_time' => '10:00:00', 'local_end_time' => '11:00:00'];
        self::assertSame(422, Api::call('PUT', $path . '/hours', [
            'version' => $before['version'], 'hours' => $bad,
        ], bearer: $admin)->status);
        self::assertSame($before, F::ok(Api::get($path, bearer: $admin)));
    }

    public function test_E_sin_ventanas_no_hay_reserva_ni_documentos_parciales(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin, withHours: false);
        $band = $f->band((int) $user['id']);
        $before = F::snapshot($this->pdo);
        $result = Api::post('/api/v1/reservations', F::body($band, $space), $admin, F::key());
        self::assertSame(422, $result->status, $result->json());
        self::assertSame($before, F::snapshot($this->pdo));
        $availability = F::ok(Api::get('/api/v1/spaces/' . $space['id'] . '/availability', F::range(), $admin));
        self::assertSame([], $availability['windows']);
        self::assertFalse($availability['guaranteed']);
    }

    public function test_E_calendario_minimo_oculta_banda_notas_tarifa_y_motivo_mantenimiento(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        [, $other] = $f->actor(Role::COLABORADOR);
        $space = $f->space($admin);
        $band = $f->band((int) $user['id']);
        F::reserve($admin, $band, $space);
        F::ok(Api::post('/api/v1/space-blocks', F::blockBody($space, [
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:00:00Z',
        ]), $admin, F::key()), 201);
        $calendar = F::ok(Api::get('/api/v1/spaces/' . $space['id'] . '/availability', F::range(), $other));
        self::assertCount(2, $calendar['occupancies']);
        foreach ($calendar['occupancies'] as $occupancy) {
            $keys = array_keys($occupancy);
            sort($keys);
            self::assertSame(['end_at', 'id', 'kind', 'space_id', 'start_at', 'status'], $keys);
        }
        $json = json_encode($calendar);
        foreach ([$band['name'], 'Nota privada de reserva QA', 'Motivo privado mantenimiento QA',
            'hourly_rate', 'sale_id', 'payments', 'responsible_id'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function test_E_disponibilidad_exige_rango_acotado_y_no_modifica_datos(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $space = $f->space($admin);
        $path = '/api/v1/spaces/' . $space['id'] . '/availability';
        $before = F::snapshot($this->pdo);
        foreach ([[], ['from' => '2030-01-01T00:00:00Z', 'to' => '2030-02-02T00:00:00Z'],
            ['from' => '2030-01-02T00:00:00Z', 'to' => '2030-01-01T00:00:00Z']] as $range) {
            self::assertSame(422, Api::get($path, $range, $admin)->status);
        }
        F::ok(Api::get($path, F::range(), $admin));
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_mantenimiento_solo_admin_conflicto_cancela_y_libera_sin_borrar(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin);
        $band = $f->band((int) $user['id']);
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            [, $actor] = $f->actor($role);
            self::assertSame(403, Api::get('/api/v1/space-blocks', bearer: $actor)->status);
            self::assertSame(403, Api::post('/api/v1/space-blocks', F::blockBody($space), $actor, F::key())->status);
        }
        $key = F::key();
        $first = Api::post('/api/v1/space-blocks', F::blockBody($space), $admin, $key);
        $block = F::ok($first, 201);
        self::assertSame($block, F::ok(Api::post('/api/v1/space-blocks', F::blockBody($space), $admin, $key), 201));
        $conflict = Api::post('/api/v1/reservations', F::body($band, $space), $admin, F::key());
        self::assertSame(409, $conflict->status);
        self::assertSame('RESERVATION_CONFLICT', $conflict->errorCode());
        $list = Api::get('/api/v1/space-blocks', bearer: $admin);
        F::ok($list);
        self::assertCount(1, $list->items());
        [, $leader] = $f->actor(Role::LIDER_PROYECTO);
        $path = '/api/v1/space-blocks/' . $block['id'] . '/cancel';
        $body = ['version' => $block['version'], 'reason' => 'Mantenimiento resuelto'];
        self::assertSame(403, Api::post($path, $body, $leader, F::key())->status);
        $cancelKey = F::key();
        $cancelled = F::ok(Api::post($path, $body, $admin, $cancelKey));
        self::assertSame($cancelled, F::ok(Api::post($path, $body, $admin, $cancelKey)));
        self::assertSame('CANCELLED', $cancelled['status']);
        F::reserve($admin, $band, $space);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM space_blocks')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM space_occupancies')->fetchColumn());
    }

    public function test_E_no_desactiva_espacio_con_reservas_futuras_bloqueantes(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin);
        F::reserve($admin, $f->band((int) $user['id']), $space);
        $before = F::snapshot($this->pdo);
        $result = Api::patch('/api/v1/spaces/' . $space['id'], [
            'version' => $space['version'], 'active' => false,
        ], $admin);
        self::assertSame(409, $result->status);
        self::assertSame('SPACE_HAS_FUTURE_OCCUPANCIES', $result->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_configuracion_no_invalida_ocupaciones_y_espacio_inactivo_no_reservable(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin);
        F::ok(Api::post('/api/v1/space-blocks', F::blockBody($space), $admin, F::key()), 201);
        $path = '/api/v1/spaces/' . $space['id'];
        foreach ([Api::patch($path, ['version' => $space['version'], 'active' => false], $admin),
            Api::patch($path, ['version' => $space['version'], 'block_minutes' => 60], $admin),
            Api::call('PUT', $path . '/hours', ['version' => $space['version'], 'hours' => []], bearer: $admin),
        ] as $conflict) {
            self::assertSame(409, $conflict->status, $conflict->json());
            self::assertSame('SPACE_HAS_FUTURE_OCCUPANCIES', $conflict->errorCode());
        }
        $other = $f->space($admin);
        F::ok(Api::patch('/api/v1/spaces/' . $other['id'], ['version' => $other['version'], 'active' => false], $admin));
        $before = F::snapshot($this->pdo);
        $result = Api::post('/api/v1/reservations', F::body($f->band((int) $user['id']), $other), $admin, F::key());
        self::assertSame(409, $result->status);
        self::assertSame('SPACE_INACTIVE', $result->errorCode());
        self::assertSame($before, F::snapshot($this->pdo));
    }

    public function test_E_ventanas_adyacentes_cubren_cruce_medianoche_sin_hueco_artificial(): void
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin, withHours: false);
        $hours = [];
        foreach (range(1, 7) as $day) {
            $hours[] = ['weekday' => $day, 'local_start_time' => '00:00:00', 'local_end_time' => '12:00:00'];
            $hours[] = ['weekday' => $day, 'local_start_time' => '12:00:00', 'local_end_time' => '24:00:00'];
        }
        $space = F::ok(Api::call('PUT', '/api/v1/spaces/' . $space['id'] . '/hours', [
            'version' => $space['version'], 'hours' => $hours,
        ], bearer: $admin));
        // UTC interval necessarily crosses a configured local boundary in the configured studio zone.
        // Full 24h covers both noon adjacency and 24:00 -> 00:00 without assuming a fixed offset.
        $r = F::reserve($admin, $f->band((int) $user['id']), $space, [
            'start_at' => '2030-01-07T10:00:00Z', 'end_at' => '2030-01-08T10:00:00Z',
        ]);
        self::assertSame('2880.00', $r['total']);
    }
}
