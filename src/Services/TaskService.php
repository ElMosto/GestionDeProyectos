<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\ProjectRepository;
use App\Repositories\StageRepository;
use App\Repositories\TaskRepository;
use App\Support\Dates;
use App\Support\Version;

final class TaskService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projectRows,
        private readonly StageRepository $stages,
        private readonly ProjectService $projects,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function list(array $q): array
    {
        $this->authorizer->requireUser();
        if (isset($q['stage_id'])) {
            $this->stage((int) $q['stage_id']);
        }
        if (isset($q['project_id'])) {
            $this->projects->requireProject((int) $q['project_id']);
        }
        $page = $this->tasks->paginate($q, (int) $this->authorizer->id(), (string) $this->authorizer->role());
        return ['data' => array_map($this->present(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function stageList(int $stageId, array $q): array
    {
        $this->stage($stageId);
        return $this->list(array_replace($q, ['stage_id' => $stageId]));
    }

    public function agenda(array $q): array
    {
        $this->authorizer->requireCapability(Capabilities::AGENDA_VIEW_OWN);
        if (!isset($q['from'], $q['to'])) {
            throw ApiException::validation(['from' => 'Envía from y to como fechas DATE.']);
        }
        Dates::range($q['from'], $q['to']);
        $days = (new \DateTimeImmutable($q['from']))->diff(new \DateTimeImmutable($q['to']))->days;
        if ($days > 366) {
            throw ApiException::validation(['to' => 'La agenda admite como máximo366 días.']);
        }
        $q['assignee_id'] = (int) $this->authorizer->id();
        $page = $this->list($q);
        foreach ($page['data'] as &$task) {
            $task['all_day'] = true;
            $task['kind'] = 'TASK';
        }
        $page['meta']['projection'] = 'TASKS_PHASE_2';
        return $page;
    }

    public function show(int $id): array
    {
        [$task] = $this->context($id);
        return $this->present($task);
    }

    public function create(?int $stageId, array $fields): array
    {
        $hint = $stageId === null ? null : $this->stages->find($stageId);
        return $this->db->transaction(function () use ($stageId, $fields, $hint): array {
            $project = null;
            $stage = null;
            if ($stageId === null) {
                $this->authorizer->requireCapability(Capabilities::TASKS_CREATE_STANDALONE);
            } else {
                // El identificador de etapa es inmutable; se revalida tras lock padre.
                if ($hint === null) {
                    throw ApiException::notFound();
                }
                $project = $this->projects->requireProject((int) $hint['project_id'], true, true);
                $this->projects->assertWritable($project);
                $stage = $this->stages->find($stageId, true);
                if ($stage === null || (int) $stage['project_id'] !== (int) $project['id']) {
                    throw ApiException::notFound();
                }
                if (in_array($stage['status'], ['COMPLETADA', 'CANCELADA'], true)) {
                    throw new ApiException(409, 'STAGE_CLOSED', 'Reabre la etapa antes de añadir tareas.');
                }
            }
            $data = $fields + ['start_date' => null, 'end_date' => null, 'actual_start' => null, 'actual_end' => null];
            $this->validate($data, $stage, $project);
            $id = $this->tasks->create($stageId, $fields, (int) $this->authorizer->id());
            if ($project !== null) {
                $this->projects->bump((int) $project['id']);
            }
            $this->audit->record('TASK_CREATE', 'task', $id, null, $fields);
            return $this->show($id);
        });
    }

    public function update(int $id, array $fields, int $version): array
    {
        $hint = $this->tasks->find($id);
        return $this->db->transaction(function () use ($id, $fields, $version, $hint): array {
            [$task, $project, $stage] = $this->context($id, true, $hint);
            Version::assert($task, $version);
            $scope = array_diff(array_keys($fields), ['progress']);
            if (($scope !== [] && !$this->authorizer->canManageTask($task, $project))
                || (isset($fields['progress']) && !$this->authorizer->canUpdateTaskProgress($task, $project))) {
                throw ApiException::forbidden();
            }
            $this->writable($task, $project);
            $merged = array_replace($task, $fields);
            $this->validate($merged, $stage, $project);
            if ($merged['status'] === 'COMPLETADA' && (int) $merged['progress'] !== 100) {
                throw ApiException::validation(['progress' => 'Una tarea completada conserva avance100.']);
            }
            $this->tasks->update($id, $fields);
            if ($project !== null) {
                $this->projects->bump((int) $project['id']);
            }
            $this->audit->record('TASK_UPDATE', 'task', $id, array_intersect_key($task, $fields), $fields);
            return $this->show($id);
        });
    }

    public function transition(int $id, array $input, int $version): array
    {
        $hint = $this->tasks->find($id);
        return $this->db->transaction(function () use ($id, $input, $version, $hint): array {
            [$task, $project] = $this->context($id, true, $hint);
            Version::assert($task, $version);
            if (!$this->authorizer->canUpdateTaskProgress($task, $project)) {
                throw ApiException::forbidden();
            }
            if ($project !== null) {
                $this->projects->assertWritable($project);
            }
            $target = $input['status'];
            $terminal = in_array($task['status'], ['COMPLETADA', 'CANCELADA'], true);
            if ($target === $task['status'] || ($terminal && ($target !== 'EN_CURSO' || !isset($input['reason'])))) {
                throw new ApiException(409, 'INVALID_TRANSITION', 'La reapertura exige EN_CURSO y motivo; no se repite el estado actual.');
            }
            $progress = (int) $task['progress'];
            $start = $task['actual_start'];
            $end = $task['actual_end'];
            if ($target === 'COMPLETADA') {
                $progress = 100;
                $start ??= gmdate('Y-m-d');
                $end = gmdate('Y-m-d');
            } elseif ($target === 'EN_CURSO') {
                $start ??= gmdate('Y-m-d');
                $end = null;
                if ($terminal) {
                    $progress = 0;
                }
            } elseif ($target === 'PENDIENTE') {
                $progress = 0;
                $end = null;
            }
            Dates::range($start, $end);
            $this->tasks->transition($id, $target, $progress, $start, $end);
            if ($project !== null) {
                $this->projects->bump((int) $project['id']);
            }
            $this->audit->record($terminal ? 'TASK_REOPEN' : 'TASK_TRANSITION', 'task', $id,
                ['status' => $task['status'], 'progress' => $task['progress']], $input + ['progress' => $progress]);
            return $this->show($id);
        });
    }

    public function comments(int $id, array $q): array
    {
        $this->context($id);
        $page = $this->tasks->comments($id, $q);
        return ['data' => array_map($this->presentComment(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function comment(int $id, string $body): array
    {
        $hint = $this->tasks->find($id);
        return $this->db->transaction(function () use ($id, $body, $hint): array {
            $this->context($id, true, $hint);
            $comment = $this->tasks->comment($id, (int) $this->authorizer->id(), $body);
            $this->audit->record('TASK_COMMENT_CREATE', 'task_comment', (int) $comment['id'], null, ['task_id' => (string) $id]);
            return $this->presentComment($comment);
        });
    }

    private function context(int $id, bool $lock = false, ?array $hint = null): array
    {
        $this->authorizer->requireUser();
        $task = $hint ?? $this->tasks->find($id);
        if ($task === null) {
            throw ApiException::notFound('Tarea no encontrada.');
        }
        $project = null;
        $stage = null;
        if ($task['stage_id'] !== null) {
            $project = $this->projects->requireProject((int) $task['project_id'], false, $lock);
            $stage = $this->stages->find((int) $task['stage_id'], $lock);
            if ($stage === null || (int) $stage['project_id'] !== (int) $project['id']) {
                throw ApiException::notFound();
            }
        }
        if ($lock) {
            $locked = $this->tasks->find($id, true);
            if ($locked === null || $locked['stage_id'] !== $task['stage_id']) {
                throw ApiException::notFound();
            }
            $task = $locked;
        }
        if (!$this->authorizer->canViewTask($task, $project)) {
            throw ApiException::notFound('Tarea no encontrada.');
        }
        return [$task, $project, $stage];
    }

    private function stage(int $id): array
    {
        $stage = $this->stages->find($id);
        if ($stage === null) {
            throw ApiException::notFound('Etapa no encontrada.');
        }
        $this->projects->requireProject((int) $stage['project_id']);
        return $stage;
    }

    private function writable(array $task, ?array $project): void
    {
        if ($project !== null) {
            $this->projects->assertWritable($project);
        }
        if (in_array($task['status'], ['COMPLETADA', 'CANCELADA'], true)) {
            throw new ApiException(409, 'TASK_CLOSED', 'Reabre la tarea antes de editarla.');
        }
    }

    private function validate(array $data, ?array $stage, ?array $project): void
    {
        $user = $this->projectRows->user((int) $data['assignee_id']);
        if ($user === null || !(bool) $user['active']) {
            throw ApiException::validation(['assignee_id' => 'Selecciona un responsable activo.']);
        }
        if ($project !== null && ($user['role'] !== 'COLABORADOR'
            || !$this->projectRows->isMember((int) $project['id'], (int) $user['id']))) {
            throw ApiException::validation(['assignee_id' => 'El responsable debe ser colaborador miembro activo.']);
        }
        Dates::range($data['start_date'], $data['end_date']);
        Dates::range($data['actual_start'], $data['actual_end']);
        if ($stage !== null) {
            Dates::within($data['start_date'], $data['end_date'], $stage['start_date'], $stage['end_date']);
            Dates::within($data['start_date'], $data['end_date'], $project['planned_start'], $project['planned_end']);
        }
    }

    public function present(array $task): array
    {
        $out = [];
        foreach (['id', 'stage_id', 'project_id', 'title', 'description', 'assignee_id', 'assignee_name', 'priority', 'status',
            'start_date', 'end_date', 'actual_start', 'actual_end', 'created_by', 'version'] as $key) {
            $out[$key] = $task[$key] === null ? null : (string) $task[$key];
        }
        $out['progress'] = (int) $task['progress'];
        $out['is_required'] = (bool) $task['is_required'];
        $out['created_at'] = UserPresenter::iso($task['created_at']);
        $out['updated_at'] = UserPresenter::iso($task['updated_at']);
        return $out;
    }

    private function presentComment(array $row): array
    {
        foreach (['id', 'task_id', 'author_id'] as $key) {
            $row[$key] = (string) $row[$key];
        }
        $row['created_at'] = UserPresenter::iso($row['created_at']);
        return $row;
    }
}
