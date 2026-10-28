<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Policies\Role;
use PDO;
use PHPUnit\Framework\Assert;

/** §19.B — scaffolding by the real API; SQL fixtures only create identities. */
final class ProjectFixtures
{
    public function __construct(private readonly Fixtures $fixtures)
    {
    }

    public function actor(string $role = Role::LIDER_PROYECTO, bool $active = true): array
    {
        $user = $this->fixtures->createUser($role, active: $active);

        return [$user, $this->fixtures->createToken($user['id'])['token']];
    }

    public static function body(int|string $leaderId, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Proyecto ' . Fixtures::uniqueSuffix(),
            'description' => 'Descripción obligatoria de aceptación §19.B',
            'type' => 'INTERNO', 'billable' => false, 'client_id' => null,
            'leader_id' => (string) $leaderId,
            'planned_start' => '2026-11-01', 'planned_end' => '2026-11-30',
            'base_amount' => '0.00', 'priority' => 'MEDIA',
        ], $overrides);
    }

    public function project(string $token, int|string $leaderId, array $overrides = []): array
    {
        return self::created(InProcessApi::post('/api/v1/projects', self::body($leaderId, $overrides), $token, self::key()));
    }

    public static function key(): array
    {
        return ['Idempotency-Key' => 'project-test-' . Fixtures::uniqueSuffix()];
    }

    public function stage(string $token, int|string $projectId, array $overrides = []): array
    {
        return self::created(InProcessApi::post('/api/v1/projects/' . $projectId . '/stages', array_replace([
            'name' => 'Etapa ' . Fixtures::uniqueSuffix(),
            'start_date' => '2026-11-02', 'end_date' => '2026-11-29',
        ], $overrides), $token));
    }

    public function members(string $token, int|string $projectId, array $userIds): void
    {
        $project = InProcessApi::get('/api/v1/projects/' . $projectId, bearer: $token);
        Assert::assertSame(200, $project->status);
        $result = InProcessApi::call('PUT', '/api/v1/projects/' . $projectId . '/members', [
            'user_ids' => array_map('strval', $userIds),
            'version' => $project->payload['data']['version'],
        ], bearer: $token);
        Assert::assertSame(200, $result->status, json_encode($result->payload));
    }

    public static function taskBody(int|string $assigneeId, array $overrides = []): array
    {
        return array_replace([
            'title' => 'Tarea ' . Fixtures::uniqueSuffix(), 'description' => 'Trabajo de prueba',
            'assignee_id' => (string) $assigneeId, 'priority' => 'MEDIA',
            'start_date' => '2026-11-03', 'end_date' => '2026-11-28', 'is_required' => true,
        ], $overrides);
    }

    public function task(string $token, int|string $stageId, int|string $assigneeId, array $overrides = []): array
    {
        return self::created(InProcessApi::post('/api/v1/stages/' . $stageId . '/tasks', self::taskBody($assigneeId, $overrides), $token));
    }

    public static function created(ApiResult $result): array
    {
        Assert::assertSame(201, $result->status, json_encode($result->payload));
        Assert::assertIsString($result->payload['data']['id']);

        return $result->payload['data'];
    }

    /** §19.B — initial expense state, not an expenses endpoint from a later phase. */
    public static function expense(PDO $pdo, int|string $projectId, int|string $responsibleId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO expenses (folio, project_id, concept, amount, expense_date, responsible_id, created_by)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'GAS-T-' . Fixtures::uniqueSuffix(), $projectId, 'Gasto inicial', '200.00',
            '2026-11-05', $responsibleId, $responsibleId,
        ]);
    }
}
