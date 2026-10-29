<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\ProjectOutputRepository;
use App\Repositories\StudioRepository;

/** §9: se filtra antes del renderer, incluidos metadatos y arrays auxiliares. */
final class ProjectOutputService
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectOutputRepository $outputs,
        private readonly StudioRepository $studio,
        private readonly Authorizer $authorizer,
        private readonly Database $db,
    ) {
    }

    public function data(int $id, bool $ticket = false): array
    {
        // Capturar una vista coherente bajo el mismo mutex que cargos/tareas/cobros;
        // el render pesado ocurre después de liberar la transacción.
        return $this->db->transaction(fn (): array => $this->snapshot($id, $ticket));
    }

    private function snapshot(int $id, bool $ticket): array
    {
        $this->authorizer->requireCapability($ticket ? Capabilities::PROJECTS_TICKET : Capabilities::PROJECTS_EXPORT);
        $row = $this->projects->requireProject($id, false, true);
        $canFinance = $this->authorizer->canViewProjectFinance($row);
        if ($ticket && !$canFinance) {
            throw ApiException::forbidden();
        }
        $project = $this->projects->show($id);
        $people = $this->outputs->people($id);
        $stages = $this->outputs->stages($id);
        $tasks = $this->outputs->tasks($id);
        $this->limit($stages, 1000);
        $this->limit($tasks, 10000);
        foreach ($stages as &$stage) {
            $count = (int) $stage['task_count'];
            $stage['progress'] = $count === 0 ? 0 : intdiv((int) $stage['completed_tasks'] * 100, $count);
            $stage['id'] = (string) $stage['id'];
            unset($stage['task_count'], $stage['completed_tasks']);
        }
        unset($stage);
        foreach ($tasks as &$task) {
            $task['id'] = (string) $task['id'];
            $task['stage_id'] = (string) $task['stage_id'];
            $task['progress'] = (int) $task['progress'];
            $task['is_required'] = (bool) $task['is_required'];
        }
        unset($task);
        $data = [
            'project' => $project, 'can_view_finance' => $canFinance,
            'client' => $people['client_id'] === null ? null : [
                'id' => (string) $people['client_id'], 'name' => $people['client_name'],
                'type' => $people['client_type'], 'contact_name' => $people['contact_name'], 'phone' => $people['phone'],
            ],
            'leader' => ['id' => (string) $people['leader_id'], 'name' => $people['leader_name']],
            'stages' => $stages, 'tasks' => $tasks, 'studio' => $this->studio->ticketData(),
            'user_timezone' => $this->authorizer->requireUser()['timezone'] ?? 'UTC',
        ];
        if ($canFinance) {
            $data['charges'] = $this->outputs->charges($id);
            $data['payments'] = $this->outputs->payments($id);
            $data['refunds'] = $this->outputs->refunds($id);
            foreach (['charges', 'payments', 'refunds'] as $group) {
                $this->limit($data[$group], 10000);
                foreach ($data[$group] as &$item) {
                    $item['id'] = (string) $item['id'];
                    if (array_key_exists('effective_at', $item)) {
                        $item['effective_at'] = UserPresenter::iso($item['effective_at']);
                    }
                }
                unset($item);
            }
        }
        return $data;
    }

    private function limit(array $rows, int $max): void
    {
        if (count($rows) > $max) {
            throw new ApiException(422, 'EXPORT_TOO_LARGE', 'El proyecto excede el límite de la salida. Reduce su alcance antes de exportar.');
        }
    }
}
