<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures;

/** §8, §19.A/B — assignment, scope, transitions and private task agenda. */
final class TasksApiTest extends DatabaseTestCase
{
    private ProjectFixtures $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new ProjectFixtures($this->fixtures);
    }

    private function world(): array
    {
        [$leader, $leaderToken] = $this->projects->actor();
        [$member, $memberToken] = $this->projects->actor(Role::COLABORADOR);
        $project = $this->projects->project($leaderToken, $leader['id']);
        $this->projects->members($leaderToken, $project['id'], [$member['id']]);
        $stage = $this->projects->stage($leaderToken, $project['id']);

        return [$leader, $leaderToken, $member, $memberToken, $project, $stage];
    }

    public function test_B_lider_asigna_solo_colaborador_activo_miembro(): void
    {
        [$leader, $token, $member, , , $stage] = $this->world();
        [$outsider] = $this->projects->actor(Role::COLABORADOR);
        [$inactive] = $this->projects->actor(Role::COLABORADOR, active: false);
        foreach ([$leader['id'], $outsider['id'], $inactive['id']] as $id) {
            $result = Api::post('/api/v1/stages/' . $stage['id'] . '/tasks', ProjectFixtures::taskBody($id), $token);
            $this->assertSame(422, $result->status, json_encode($result->payload));
        }
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $this->assertSame((string) $member['id'], $task['assignee_id']);
    }

    public function test_B_colaborador_actualiza_avance_pero_no_fechas_ni_alcance(): void
    {
        [, $token, $member, $memberToken, , $stage] = $this->world();
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/tasks/' . $task['id'];
        $progress = Api::patch($path, ['progress' => 35, 'version' => $task['version']], $memberToken);
        $this->assertSame(200, $progress->status);
        $this->assertSame(35, (int) $progress->payload['data']['progress']);
        foreach ([['end_date' => '2026-11-27'], ['title' => 'Nuevo alcance'], ['assignee_id' => '1']] as $change) {
            $result = Api::patch($path, [...$change, 'version' => $progress->payload['data']['version']], $memberToken);
            $this->assertSame(403, $result->status);
        }
        $after = Api::get($path, bearer: $token)->payload['data'];
        $this->assertSame($task['end_date'], $after['end_date']);
        $this->assertSame($task['title'], $after['title']);
        $this->assertSame($task['assignee_id'], $after['assignee_id']);
    }

    public function test_A_miembro_no_responsable_no_modifica_avance_ajeno(): void
    {
        [, $token, $member, , $project, $stage] = $this->world();
        [$other, $otherToken] = $this->projects->actor(Role::COLABORADOR);
        $this->projects->members($token, $project['id'], [$member['id'], $other['id']]);
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $this->assertSame(403, Api::patch('/api/v1/tasks/' . $task['id'], [
            'progress' => 99, 'version' => $task['version'],
        ], $otherToken)->status);
    }

    public function test_B_tarea_y_cambio_de_etapa_no_dejan_fechas_fuera(): void
    {
        [, $token, $member, , , $stage] = $this->world();
        $this->assertSame(422, Api::post('/api/v1/stages/' . $stage['id'] . '/tasks', ProjectFixtures::taskBody($member['id'], [
            'start_date' => '2026-11-01',
        ]), $token)->status);
        $this->projects->task($token, $stage['id'], $member['id']);
        $result = Api::patch('/api/v1/stages/' . $stage['id'], [
            'end_date' => '2026-11-10', 'version' => $stage['version'],
        ], $token);
        $this->assertSame(422, $result->status);
        $statement = $this->pdo->prepare('SELECT end_date FROM project_stages WHERE id = ?');
        $statement->execute([$stage['id']]);
        $this->assertSame('2026-11-29', $statement->fetchColumn());
    }

    public function test_B_completar_fija_cien_reabrir_conserva_comentarios_e_historial(): void
    {
        [, $token, $member, $memberToken, , $stage] = $this->world();
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/tasks/' . $task['id'];
        $comment = Api::post($path . '/comments', ['body' => 'Registro anterior al cierre'], $memberToken);
        $this->assertSame(201, $comment->status);
        $beforeLogs = count($this->fixtures->auditLogs());
        foreach (['EN_CURSO', 'COMPLETADA', 'EN_CURSO'] as $status) {
            $result = Api::post($path . '/transitions', [
                'status' => $status, 'version' => $task['version'], 'reason' => 'Revisión de trabajo',
            ], $memberToken);
            $this->assertSame(200, $result->status, json_encode($result->payload));
            $task = $result->payload['data'];
            $this->assertSame($status, $task['status']);
            if ($status === 'COMPLETADA') {
                $this->assertSame(100, (int) $task['progress']);
            }
        }
        $comments = Api::get($path . '/comments', bearer: $memberToken);
        $this->assertSame(200, $comments->status);
        $this->assertContains('Registro anterior al cierre', array_column($comments->payload['data'], 'body'));
        $this->assertGreaterThanOrEqual($beforeLogs + 3, count($this->fixtures->auditLogs()));
    }

    public function test_B_version_obsoleta_de_tarea_no_sobrescribe_avance(): void
    {
        [, $token, $member, $memberToken, , $stage] = $this->world();
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/tasks/' . $task['id'];
        $this->assertSame(200, Api::patch($path, ['progress' => 40, 'version' => $task['version']], $memberToken)->status);
        $this->assertSame(409, Api::patch($path, ['progress' => 80, 'version' => $task['version']], $memberToken)->status);
        $this->assertSame(40, (int) Api::get($path, bearer: $token)->payload['data']['progress']);
    }

    public function test_B_no_se_retira_miembro_con_tarea_vigente(): void
    {
        [, $token, $member, , $project, $stage] = $this->world();
        $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $current = Api::get($path, bearer: $token)->payload['data'];
        $result = Api::call('PUT', $path . '/members', ['user_ids' => [], 'version' => $current['version']], bearer: $token);
        $this->assertSame(409, $result->status);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM project_members WHERE project_id = ? AND user_id = ?');
        $statement->execute([$project['id'], $member['id']]);
        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    public function test_B_admin_crea_independientes_para_todos_roles_y_agenda_es_privada(): void
    {
        [, $adminToken] = $this->projects->actor(Role::ADMINISTRADOR);
        foreach (Role::all() as $role) {
            [$assignee, $assigneeToken] = $this->projects->actor($role);
            $title = 'Privada ' . $role;
            $task = ProjectFixtures::created(Api::post('/api/v1/standalone-tasks', ProjectFixtures::taskBody($assignee['id'], [
                'title' => $title,
            ]), $adminToken));
            $this->assertNull($task['stage_id']);
            $agenda = Api::get('/api/v1/agenda', ['from' => '2026-11-01', 'to' => '2026-11-30'], $assigneeToken);
            $this->assertSame(200, $agenda->status);
            $this->assertStringContainsString($title, json_encode($agenda->payload));
            [, $strangerToken] = $this->projects->actor(Role::COLABORADOR);
            $foreign = Api::get('/api/v1/agenda', [
                'from' => '2026-11-01', 'to' => '2026-11-30', 'user_id' => (string) $assignee['id'],
            ], $strangerToken);
            $this->assertContains($foreign->status, [200, 403, 422]);
            $this->assertStringNotContainsString($title, json_encode($foreign->payload));
        }
    }

    public function test_B_lider_y_colaborador_no_crean_independientes(): void
    {
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            [$actor, $token] = $this->projects->actor($role);
            $this->assertSame(403, Api::post('/api/v1/standalone-tasks', ProjectFixtures::taskBody($actor['id']), $token)->status);
        }
    }

    public function test_B_cancelada_obligatoria_bloquea_finalizar_y_excepcion_solo_admin_con_motivo_auditado(): void
    {
        // §8 + D-14: cancelled mandatory work is not completed work.
        [, $token, $member, , $project, $stage] = $this->world();
        [, $adminToken] = $this->projects->actor(Role::ADMINISTRADOR);
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $cancel = Api::post('/api/v1/tasks/' . $task['id'] . '/transitions', [
            'status' => 'CANCELADA', 'version' => $task['version'],
        ], $token);
        $this->assertSame(200, $cancel->status);
        $path = '/api/v1/projects/' . $project['id'];
        foreach (['PLANIFICADO', 'EN_CURSO'] as $status) {
            $current = Api::get($path, bearer: $token)->payload['data'];
            $this->assertSame(200, Api::post($path . '/transitions', [
                'status' => $status, 'version' => $current['version'],
            ], $token)->status);
        }
        $current = Api::get($path, bearer: $token)->payload['data'];
        $body = ['status' => 'FINALIZADO', 'version' => $current['version']];
        $this->assertSame(409, Api::post($path . '/transitions', $body, $token)->status);
        $this->assertSame(409, Api::post($path . '/transitions', [...$body, 'reason' => 'No autoriza líder'], $token)->status);
        $this->assertContains(Api::post($path . '/transitions', $body, $adminToken)->status, [409, 422]);
        $reason = 'Excepción documentada §19.B para tarea obligatoria cancelada';
        $exception = Api::post($path . '/transitions', [...$body, 'reason' => $reason, 'exception' => true], $adminToken);
        $this->assertSame(200, $exception->status, json_encode($exception->payload));
        $this->assertSame('FINALIZADO', $exception->payload['data']['status']);
        $this->assertStringContainsString($reason, json_encode($this->fixtures->auditLogs(), JSON_UNESCAPED_UNICODE));
    }

    public function test_B_avance_ignora_canceladas_y_no_usa_porcentaje_parcial(): void
    {
        [, $token, $member, , $project, $stage] = $this->world();
        $empty = Api::get('/api/v1/projects/' . $project['id'], bearer: $token)->payload['data'];
        $this->assertSame(0, (int) $empty['progress']);
        $this->assertFalse($empty['has_tasks']);
        $complete = $this->projects->task($token, $stage['id'], $member['id']);
        $partial = $this->projects->task($token, $stage['id'], $member['id']);
        $cancel = $this->projects->task($token, $stage['id'], $member['id'], ['is_required' => false]);
        $running = Api::post('/api/v1/tasks/' . $complete['id'] . '/transitions', [
            'status' => 'EN_CURSO', 'version' => $complete['version'],
        ], $token);
        $this->assertSame(200, $running->status);
        $this->assertSame(200, Api::post('/api/v1/tasks/' . $complete['id'] . '/transitions', [
            'status' => 'COMPLETADA', 'version' => $running->payload['data']['version'],
        ], $token)->status);
        $this->assertSame(200, Api::patch('/api/v1/tasks/' . $partial['id'], ['progress' => 90, 'version' => $partial['version']], $token)->status);
        $this->assertSame(200, Api::post('/api/v1/tasks/' . $cancel['id'] . '/transitions', [
            'status' => 'CANCELADA', 'version' => $cancel['version'],
        ], $token)->status);
        $after = Api::get('/api/v1/projects/' . $project['id'], bearer: $token)->payload['data'];
        $this->assertSame(50, (int) $after['progress']);
    }

    public function test_H_comentario_malicioso_es_dato_literal_y_ajeno_no_lo_consulta(): void
    {
        [, $token, $member, $memberToken, , $stage] = $this->world();
        [, $outsiderToken] = $this->projects->actor(Role::COLABORADOR);
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/tasks/' . $task['id'] . '/comments';
        $body = '<script>alert(1)</script>';
        $this->assertSame(201, Api::post($path, ['body' => $body], $memberToken)->status);
        $list = Api::get($path, bearer: $memberToken);
        $this->assertSame(200, $list->status);
        $this->assertContains($body, array_column($list->payload['data'], 'body'));
        $this->assertContains(Api::get($path, bearer: $outsiderToken)->status, [403, 404]);
        // Rendering safety requires the separate browser flow; JSON persistence alone is not XSS proof.
    }

    public function test_B_transicion_de_proyecto_prohibida_no_muta_estado(): void
    {
        [, $token, , , $project] = $this->world();
        $path = '/api/v1/projects/' . $project['id'];
        $before = Api::get($path, bearer: $token)->payload['data'];
        $result = Api::post($path . '/transitions', [
            'status' => 'FINALIZADO', 'version' => $before['version'],
        ], $token);
        $this->assertSame(409, $result->status);
        $this->assertSame('INVALID_TRANSITION', $result->payload['error']['code']);
        $after = Api::get($path, bearer: $token)->payload['data'];
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['version'], $after['version']);
    }

    public function test_B_visible_task_allows_member_comments_without_progress_permission(): void
    {
        [, $token, $member, , $project, $stage] = $this->world();
        [$viewer, $viewerToken] = $this->projects->actor(Role::COLABORADOR);
        $this->projects->members($token, $project['id'], [$member['id'], $viewer['id']]);
        $task = $this->projects->task($token, $stage['id'], $member['id']);
        $path = '/api/v1/tasks/' . $task['id'];
        $comment = Api::post($path . '/comments', ['body' => 'Comentario de otro integrante'], $viewerToken);
        $this->assertSame(201, $comment->status);
        $this->assertSame(403, Api::patch($path, ['progress' => 50, 'version' => $task['version']], $viewerToken)->status);
        $list = Api::get($path . '/comments', bearer: $viewerToken);
        $this->assertContains('Comentario de otro integrante', array_column($list->payload['data'], 'body'));
    }
}
