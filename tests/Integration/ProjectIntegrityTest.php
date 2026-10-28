<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures;
use PDOException;

/** §8, §16 — atomicidad, idempotencia y protección del importe ya cobrado. */
final class ProjectIntegrityTest extends DatabaseTestCase
{
    public function test_B_project_creation_replays_once_and_conflicting_body_has_no_effect(): void
    {
        $fixtures = new ProjectFixtures($this->fixtures);
        [$leader, $token] = $fixtures->actor();
        $body = ProjectFixtures::body($leader['id']);
        $headers = ProjectFixtures::key();
        $first = Api::post('/api/v1/projects', $body, $token, $headers);
        $this->assertSame(201, $first->status, $first->rawBody);
        $replay = Api::post('/api/v1/projects', $body, $token, $headers);
        $this->assertSame(201, $replay->status);
        $this->assertSame($first->payload, $replay->payload);
        $conflict = Api::post('/api/v1/projects', array_replace($body, ['name' => 'Otro proyecto']), $token, $headers);
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->payload['error']['code']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_B_existing_payment_prevents_non_admin_adjustment_and_total_below_net_paid(): void
    {
        $fixtures = new ProjectFixtures($this->fixtures);
        [$leader, $token] = $fixtures->actor();
        [$admin, $adminToken] = $fixtures->actor(Role::ADMINISTRADOR);
        $client = $this->fixtures->createClient($leader['id']);
        $project = $fixtures->project($token, $leader['id'], [
            'type' => 'EXTERNO', 'billable' => true,
            'client_id' => (string) $client['id'], 'base_amount' => '1000.00',
        ]);
        // Historial inicial para probar ajustes; registrar cobros por API es fase 3.
        $this->pdo->prepare(
            'INSERT INTO payments (folio, project_id, amount, effective_at, method, registered_by, idempotency_key)'
            . " VALUES ('PAG-INTEGRITY-1', ?, '900.00', UTC_TIMESTAMP(), 'EFECTIVO', ?, 'integrity-payment-1')"
        )->execute([$project['id'], $admin['id']]);
        $path = '/api/v1/projects/' . $project['id'];
        $forbidden = Api::patch($path, ['base_amount' => '1100.00', 'version' => $project['version']], $token, ProjectFixtures::key());
        $this->assertSame(403, $forbidden->status, $forbidden->rawBody);
        $underpaid = Api::patch($path, ['base_amount' => '800.00', 'version' => $project['version']], $adminToken, ProjectFixtures::key());
        $this->assertSame(409, $underpaid->status, $underpaid->rawBody);
        $this->assertSame('TOTAL_BELOW_PAID', $underpaid->payload['error']['code']);
        $this->assertSame('1000.00', Api::get($path, bearer: $adminToken)->payload['data']['base_amount']);
        $allowed = Api::patch($path, ['base_amount' => '1200.00', 'version' => $project['version']], $adminToken, ProjectFixtures::key());
        $this->assertSame(200, $allowed->status, $allowed->rawBody);
        $this->assertSame('1200.00', $allowed->payload['data']['base_amount']);
        $this->assertSame('900.00', $this->pdo->query('SELECT amount FROM payments')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM refunds')->fetchColumn());
    }

    public function test_H_failed_audit_rolls_back_project_and_idempotent_response(): void
    {
        $fixtures = new ProjectFixtures($this->fixtures);
        [$leader, $token] = $fixtures->actor();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM idempotency_requests')->fetchColumn();
        $this->pdo->exec(
            "CREATE TRIGGER project_test_reject_audit BEFORE INSERT ON audit_logs FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected audit failure for rollback test'"
        );
        $failed = false;
        try {
            Api::post('/api/v1/projects', ProjectFixtures::body($leader['id']), $token, ProjectFixtures::key());
        } catch (PDOException $exception) {
            $failed = true;
            $this->assertStringContainsString('Injected audit failure', $exception->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER project_test_reject_audit');
        }
        $this->assertTrue($failed, 'La API no debe confirmar una escritura si falla su auditoría.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM idempotency_requests')->fetchColumn());
    }

    public function test_A_idempotent_financial_snapshots_are_not_disclosed_after_leadership_transfer(): void
    {
        $fixtures = new ProjectFixtures($this->fixtures);
        [$leader, $token] = $fixtures->actor();
        [$newLeader] = $fixtures->actor();
        [, $adminToken] = $fixtures->actor(Role::ADMINISTRADOR);
        $body = ProjectFixtures::body($leader['id'], ['base_amount' => '98765.43']);
        $projectKey = ProjectFixtures::key();
        $project = ProjectFixtures::created(Api::post('/api/v1/projects', $body, $token, $projectKey));
        $path = '/api/v1/projects/' . $project['id'];
        $chargeBody = [
            'concept' => 'Concepto confidencial', 'quantity' => '1.000',
            'unit_price' => '1234.56', 'charge_date' => '2026-11-05',
        ];
        $chargeKey = ProjectFixtures::key();
        $this->assertSame(201, Api::post($path . '/charges', $chargeBody, $token, $chargeKey)->status);
        $current = Api::get($path, bearer: $adminToken)->payload['data'];
        $transfer = Api::patch($path, [
            'leader_id' => (string) $newLeader['id'], 'version' => $current['version'],
        ], $adminToken, ProjectFixtures::key());
        $this->assertSame(200, $transfer->status, $transfer->rawBody);
        foreach ([
            Api::post('/api/v1/projects', $body, $token, $projectKey),
            Api::post($path . '/charges', $chargeBody, $token, $chargeKey),
        ] as $replayed) {
            $this->assertContains($replayed->status, [403, 404]);
            $this->assertStringNotContainsString('98765.43', $replayed->rawBody);
            $this->assertStringNotContainsString('1234.56', $replayed->rawBody);
            $this->assertStringNotContainsString('Concepto confidencial', $replayed->rawBody);
        }
    }
}
