<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Policies\Role;
use PDO;

/** §13 / §19.F: fixture identities only; meetings and invitations always use the API. */
final class MeetingFixtures
{
    public function __construct(private readonly Fixtures $fixtures)
    {
    }

    public function actor(string $role = Role::COLABORADOR): array
    {
        return (new SalesFixtures($this->fixtures))->actor($role);
    }

    public static function key(): array
    {
        return ['Idempotency-Key' => 'meeting-test-' . Fixtures::uniqueSuffix()];
    }

    public static function ok(ApiResult $result, int $status = 200): array
    {
        return SalesFixtures::ok($result, $status);
    }

    public static function body(array $invitees = [], array $overrides = []): array
    {
        return array_replace([
            'title' => 'Título privado QA ' . Fixtures::uniqueSuffix(),
            'description' => 'Descripción privada <script>alert(1)</script>',
            'location' => 'https://private.test/secret-room',
            'start_at' => '2030-01-07T10:00:00Z', 'end_at' => '2030-01-07T11:30:00Z',
            'modality' => 'VIRTUAL', 'invited_user_ids' => array_map('strval', array_column($invitees, 'id')),
        ], $overrides);
    }

    public static function create(string $token, array $invitees = [], array $overrides = []): array
    {
        return self::ok(InProcessApi::post('/api/v1/meetings', self::body($invitees, $overrides), $token, self::key()), 201);
    }

    public static function detail(string $token, array $meeting): array
    {
        return self::ok(InProcessApi::get('/api/v1/meetings/' . $meeting['id'], bearer: $token));
    }

    public static function respond(string $token, array $meeting, string $response = 'ACCEPTED', array $overrides = []): array
    {
        $own = self::detail($token, $meeting);
        return self::ok(InProcessApi::post('/api/v1/meetings/' . $meeting['id'] . '/response', array_replace([
            'version' => $own['invitation']['version'], 'response' => $response,
        ], $overrides), $token, self::key()));
    }

    public static function edit(string $token, array $meeting, array $overrides): array
    {
        return self::ok(InProcessApi::patch('/api/v1/meetings/' . $meeting['id'], array_replace([
            'version' => $meeting['version'],
        ], $overrides), $token, self::key()));
    }

    public static function agenda(string $token, array $range = []): ApiResult
    {
        return InProcessApi::get('/api/v1/agenda/meetings', array_replace([
            'from' => '2030-01-07', 'to' => '2030-01-09',
        ], $range), $token);
    }

    /** Includes every business row potentially affected by a meeting mutation. */
    public static function snapshot(PDO $pdo): array
    {
        $rows = ReservationFixtures::snapshot($pdo);
        foreach (['meetings', 'meeting_invitations', 'notifications', 'audit_logs', 'expenses'] as $table) {
            $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }
}
