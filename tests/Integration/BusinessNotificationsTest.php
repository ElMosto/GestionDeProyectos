<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ReservationFixtures as R;
use App\Tests\Support\SalesFixtures as F;
use PDOException;

/** §13: avisos de módulos existentes, atómicos y sin snapshots privados. */
final class BusinessNotificationsTest extends DatabaseTestCase
{
    public function test_F_task_assignment_and_reassignment_notify_only_the_affected_users(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [$first, $firstToken] = $f->actor(Role::COLABORADOR);
        [$second, $secondToken] = $f->actor(Role::LIDER_PROYECTO);
        $task = F::ok(Api::post('/api/v1/standalone-tasks', [
            'title' => 'TITULO_PRIVADO_NO_AVISO', 'description' => 'DESCRIPCION_PRIVADA_NO_AVISO',
            'assignee_id' => (string) $first['id'],
        ], $admin), 201);
        $created = Api::get('/api/v1/notifications', bearer: $firstToken);
        $this->assertSame(200, $created->status);
        $this->assertContains('TASK_ASSIGNED', array_column($created->payload['data'], 'type'));
        F::ok(Api::patch('/api/v1/tasks/' . $task['id'], [
            'version' => $task['version'], 'assignee_id' => (string) $second['id'],
        ], $admin));
        foreach ([$firstToken, $secondToken] as $token) {
            $result = Api::get('/api/v1/notifications', bearer: $token);
            $this->assertContains('TASK_REASSIGNED', array_column($result->payload['data'], 'type'));
            $this->assertStringNotContainsString('TITULO_PRIVADO_NO_AVISO', $result->rawBody);
            $this->assertStringNotContainsString('DESCRIPCION_PRIVADA_NO_AVISO', $result->rawBody);
        }
    }

    public function test_F_reservation_replay_does_not_duplicate_notifications_and_changes_are_generic(): void
    {
        $r = new R($this->fixtures);
        [, $admin] = $r->actor();
        [$owner, $token] = $r->actor(Role::COLABORADOR);
        $space = $r->space($admin);
        $band = $r->band($owner['id'], ['name' => 'BANDA_PRIVADA_NO_AVISO']);
        $body = R::body($band, $space, ['notes' => 'NOTAS_PRIVADAS_NO_AVISO']);
        $key = R::key();
        $reservation = R::ok(Api::post('/api/v1/reservations', $body, $token, $key), 201);
        R::ok(Api::post('/api/v1/reservations', $body, $token, $key), 201);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE type='RESERVATION_CREATED'")->fetchColumn());
        $updated = R::ok(Api::patch('/api/v1/reservations/' . $reservation['id'], [
            'version' => $reservation['version'], 'notes' => 'OTRA_NOTA_PRIVADA',
        ], $token, R::key()));
        R::ok(Api::post('/api/v1/reservations/' . $reservation['id'] . '/cancel', [
            'version' => $updated['version'], 'reason' => 'MOTIVO_PRIVADO_NO_AVISO',
        ], $admin, R::key()));
        $result = Api::get('/api/v1/notifications', bearer: $token);
        foreach (['RESERVATION_CREATED', 'RESERVATION_CHANGED', 'RESERVATION_CANCELLED'] as $type) {
            $this->assertContains($type, array_column($result->payload['data'], 'type'));
        }
        foreach (['BANDA_PRIVADA_NO_AVISO', 'NOTAS_PRIVADAS_NO_AVISO', 'OTRA_NOTA_PRIVADA', 'MOTIVO_PRIVADO_NO_AVISO', '180.00'] as $private) {
            $this->assertStringNotContainsString($private, $result->rawBody);
        }
    }

    public function test_H_notification_failure_rolls_back_the_assigned_task_and_its_audit(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [$assignee] = $f->actor(Role::COLABORADOR);
        $beforeAudit = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $this->rejectNotifications();
        try {
            Api::post('/api/v1/standalone-tasks', [
                'title' => 'Asignación atómica', 'assignee_id' => (string) $assignee['id'],
            ], $admin);
            $this->fail('Una tarea no debe confirmarse si falla persistir su aviso.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('Injected notification failure', $error->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER notification_test_failure');
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
        $this->assertSame($beforeAudit, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
    }

    public function test_H_notification_failure_rolls_back_reservation_occupancy_sale_and_idempotency(): void
    {
        $r = new R($this->fixtures);
        [$owner, $admin] = $r->actor();
        $space = $r->space($admin);
        $band = $r->band($owner['id']);
        $before = R::snapshot($this->pdo);
        $idempotency = (int) $this->pdo->query('SELECT COUNT(*) FROM idempotency_requests')->fetchColumn();
        $this->rejectNotifications();
        try {
            Api::post('/api/v1/reservations', R::body($band, $space), $admin, R::key());
            $this->fail('No debe confirmarse una reserva sin su aviso.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('Injected notification failure', $error->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER notification_test_failure');
        }
        $this->assertSame($before, R::snapshot($this->pdo));
        $this->assertSame($idempotency, (int) $this->pdo->query('SELECT COUNT(*) FROM idempotency_requests')->fetchColumn());
    }

    private function rejectNotifications(): void
    {
        $this->pdo->exec("CREATE TRIGGER notification_test_failure BEFORE INSERT ON notifications FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected notification failure'");
    }
}
