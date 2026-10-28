<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Policies\Role;
use PDO;

/** §12 / §19.E: identities are fixtures; every occupation is created through the API.
 * Fixed 2030 dates intentionally avoid the current clock; all windows span ISO weekdays 1–7.
 */
final class ReservationFixtures
{
    public function __construct(private readonly Fixtures $fixtures)
    {
    }

    public function actor(string $role = Role::ADMINISTRADOR): array
    {
        return (new SalesFixtures($this->fixtures))->actor($role);
    }

    public function band(int $creator, array $overrides = []): array
    {
        return $this->fixtures->createClient($creator, array_replace(['type' => 'BANDA'], $overrides));
    }

    public static function key(): array
    {
        return ['Idempotency-Key' => 'reservation-test-' . Fixtures::uniqueSuffix()];
    }

    public static function ok(ApiResult $result, int $status = 200): array
    {
        return SalesFixtures::ok($result, $status);
    }

    public static function hours(): array
    {
        return array_map(static fn (int $day): array => [
            'weekday' => $day, 'local_start_time' => '00:00:00', 'local_end_time' => '24:00:00',
        ], range(1, 7));
    }

    public function space(string $admin, string $rate = '120.00', bool $withHours = true): array
    {
        $space = self::ok(InProcessApi::post('/api/v1/spaces', [
            'name' => 'Sala QA ' . Fixtures::uniqueSuffix(), 'hourly_rate' => $rate,
            'block_minutes' => 30, 'description' => 'Espacio de prueba aislado',
        ], $admin), 201);
        if (!$withHours) {
            return $space;
        }
        return self::ok(InProcessApi::call('PUT', '/api/v1/spaces/' . $space['id'] . '/hours', [
            'version' => $space['version'], 'hours' => self::hours(),
        ], bearer: $admin));
    }

    public static function body(array $band, array $space, array $overrides = []): array
    {
        return array_replace([
            'band_client_id' => (string) $band['id'], 'space_id' => $space['id'],
            'start_at' => '2030-01-07T10:00:00+00:00', 'end_at' => '2030-01-07T11:30:00+00:00',
            'notes' => 'Nota privada de reserva QA',
        ], $overrides);
    }

    public static function reserve(string $actor, array $band, array $space, array $overrides = []): array
    {
        return self::ok(InProcessApi::post('/api/v1/reservations', self::body($band, $space, $overrides),
            $actor, self::key()), 201);
    }

    public static function move(array $reservation, array $overrides = []): array
    {
        return array_replace([
            'version' => $reservation['version'], 'space_id' => $reservation['space_id'],
            'start_at' => '2030-01-08T10:00:00Z', 'end_at' => '2030-01-08T11:30:00Z',
        ], $overrides);
    }

    public static function range(): array
    {
        return ['from' => '2030-01-07T00:00:00Z', 'to' => '2030-01-10T00:00:00Z'];
    }

    public static function blockBody(array $space, array $overrides = []): array
    {
        return array_replace([
            'space_id' => $space['id'], 'start_at' => '2030-01-07T10:00:00Z',
            'end_at' => '2030-01-07T11:30:00Z', 'reason' => 'Motivo privado mantenimiento QA',
        ], $overrides);
    }

    /** Full ordered business rows detect partial writes, not merely row counts. */
    public static function snapshot(PDO $pdo): array
    {
        $rows = [];
        foreach (['reservations', 'space_occupancies', 'space_blocks', 'sales', 'sale_items',
            'sale_item_extras', 'payments', 'refunds'] as $table) {
            $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }
}
