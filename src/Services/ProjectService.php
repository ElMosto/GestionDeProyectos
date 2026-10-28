<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\ProjectRepository;
use App\Repositories\SequenceRepository;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Version;

final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly SequenceRepository $sequences,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function list(array $q): array
    {
        $this->authorizer->requireUser();
        $page = $this->projects->paginate($q, (int) $this->authorizer->id(), (string) $this->authorizer->role());
        return ['data' => array_map($this->present(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function requireProject(int $id, bool $manage = false, bool $lock = false): array
    {
        $this->authorizer->requireUser();
        $project = $this->projects->find($id, (int) $this->authorizer->id(), $lock);
        if ($project === null) {
            throw ApiException::notFound('Proyecto no encontrado.');
        }
        if ($manage && !$this->authorizer->canManageProject($project)) {
            throw ApiException::forbidden();
        }
        if (!$this->authorizer->canViewProject($project)) {
            throw ApiException::notFound('Proyecto no encontrado.');
        }
        return $project;
    }

    public function show(int $id): array
    {
        return $this->present($this->requireProject($id));
    }

    public function requireProjectFinance(int $id): array
    {
        $this->authorizer->requireUser();
        $project = $this->projects->find($id, (int) $this->authorizer->id());
        if ($project === null) {
            throw ApiException::notFound('Proyecto no encontrado.');
        }
        if (!$this->authorizer->canViewProjectFinance($project)) {
            throw ApiException::forbidden();
        }
        return $project;
    }

    public function create(array $input, ?string $key): array
    {
        $this->authorizer->requireCapability(Capabilities::PROJECTS_CREATE);
        return $this->idempotency->run('project.create', $key, $input, function () use ($input): array {
            $data = $input + ['type' => 'INTERNO', 'base_amount' => '0.00', 'leader_id' => $this->authorizer->id(),
                'client_id' => null, 'priority' => 'MEDIA', 'planned_start' => null, 'planned_end' => null,
                'actual_start' => null, 'actual_end' => null, 'notes' => null];
            $data['billable'] = $input['billable'] ?? ($data['type'] === 'EXTERNO');
            $this->validate($data);
            if (!$this->authorizer->isAdmin() && (int) $data['leader_id'] !== $this->authorizer->id()) {
                throw ApiException::forbidden();
            }
            $data['folio'] = $this->sequences->nextProject();
            $data['created_by'] = $this->authorizer->id();
            $id = $this->projects->create($data);
            $this->audit->record('PROJECT_CREATE', 'project', $id, null, $data);
            return $this->show($id);
        }, 201, function (array $response): void {
            $this->authorizeFinancialResponse((int) $response['id']);
        });
    }

    public function update(int $id, array $fields, int $version, ?string $key): array
    {
        $this->requireProject($id, true);
        $work = function () use ($id, $fields, $version): array {
            $old = $this->requireProject($id, true, true);
            Version::assert($old, $version);
            $this->assertWritable($old);
            $merged = array_replace($old, $fields);
            $this->validate($merged, $old, $fields);
            if (!$this->authorizer->isAdmin() && (int) $merged['leader_id'] !== $this->authorizer->id()) {
                throw ApiException::forbidden();
            }
            foreach ($this->projects->descendantDates($id) as $dates) {
                Dates::within($dates['start_date'], $dates['end_date'], $merged['planned_start'], $merged['planned_end']);
            }
            $newTotal = Money::add($merged['base_amount'], $old['charges_total']);
            $this->assertCommercialAdjustment($old, $newTotal,
                count(array_intersect(array_keys($fields), ['base_amount', 'billable', 'client_id', 'type'])) > 0);
            $this->projects->update($id, $fields);
            $this->audit->record('PROJECT_UPDATE', 'project', $id, array_intersect_key($old, $fields), $fields);
            return $this->show($id);
        };
        if (array_intersect(array_keys($fields), ['base_amount', 'billable', 'client_id', 'type']) !== []) {
            return $this->idempotency->run('project.update.' . $id, $key,
                ['fields' => $fields, 'version' => $version], $work, 200,
                function () use ($id): void {
                    $this->authorizeFinancialResponse($id);
                });
        }
        return $this->db->transaction($work);
    }

    private function validate(array $data, ?array $old = null, array $fields = []): void
    {
        Dates::range($data['planned_start'] ?? null, $data['planned_end'] ?? null);
        Dates::range($data['actual_start'] ?? null, $data['actual_end'] ?? null);
        $leader = $this->projects->user((int) $data['leader_id']);
        if ($leader === null || !(bool) $leader['active'] || $leader['role'] !== 'LIDER_PROYECTO') {
            throw ApiException::validation(['leader_id' => 'El líder debe ser LIDER_PROYECTO activo.']);
        }
        if ($data['type'] === 'EXTERNO' && (!$data['billable'] || Money::compare($data['base_amount'], '0.00') <= 0 || $data['client_id'] === null)) {
            throw ApiException::validation(['type' => 'Externo exige cliente, importe positivo y condición cobrable.']);
        }
        if ($data['billable'] && $data['client_id'] === null) {
            throw ApiException::validation(['client_id' => 'Un proyecto cobrable exige cliente.']);
        }
        // Una corrección operativa histórica no vuelve a dar de alta al cliente.
        $needsActive = $old === null || isset($fields['client_id']) || isset($fields['billable']) || isset($fields['type']) || isset($fields['base_amount']);
        if ($data['client_id'] !== null && $needsActive) {
            $client = $this->projects->client((int) $data['client_id']);
            if ($client === null || !(bool) $client['active']) {
                throw ApiException::validation(['client_id' => 'Selecciona un cliente activo.']);
            }
        }
        Money::amount($data['base_amount']);
        if ($old !== null && $old['type'] === 'INTERNO' && $data['type'] === 'EXTERNO' && Money::compare($old['charges_total'], '0.00') > 0) {
            throw ApiException::validation(['type' => 'Resuelve los cargos internos antes de cambiar de tipo.']);
        }
    }

    public function transition(int $id, array $input, int $version): array
    {
        return $this->db->transaction(function () use ($id, $input, $version): array {
            $project = $this->requireProject($id, true, true);
            Version::assert($project, $version);
            $target = $input['status'];
            $reason = $input['reason'] ?? null;
            $terminal = in_array($project['status'], ['FINALIZADO', 'CANCELADO'], true);
            if ($terminal) {
                $this->authorizer->requireAdmin();
                if ($target !== 'EN_CURSO' || $reason === null) {
                    throw new ApiException(409, 'INVALID_TRANSITION', 'Reabrir exige EN_CURSO y motivo.');
                }
            } else {
                $allowed = match ($project['status']) {
                    'BORRADOR' => ['PLANIFICADO', 'CANCELADO'],
                    'PLANIFICADO' => ['EN_CURSO', 'CANCELADO'],
                    'EN_CURSO' => ['PAUSADO', 'FINALIZADO', 'CANCELADO'],
                    'PAUSADO' => ['EN_CURSO', 'FINALIZADO', 'CANCELADO'],
                    default => [],
                };
                if (!in_array($target, $allowed, true)) {
                    throw new ApiException(409, 'INVALID_TRANSITION', 'Transición de proyecto no permitida.');
                }
            }
            if ($target === 'CANCELADO' && $reason === null) {
                throw ApiException::validation(['reason' => 'Cancelar exige un motivo.']);
            }
            if ($target === 'FINALIZADO' && $this->projects->incompleteRequired($id)) {
                if (!($input['exception'] ?? false)) {
                    throw new ApiException(409, 'REQUIRED_TASKS_INCOMPLETE', 'Quedan tareas obligatorias sin completar.');
                }
                $this->authorizer->requireAdmin();
                if ($reason === null) {
                    throw ApiException::validation(['reason' => 'La excepción administrativa exige motivo.']);
                }
            }
            $start = $project['actual_start'];
            $end = $project['actual_end'];
            if ($target === 'EN_CURSO') {
                $start ??= gmdate('Y-m-d');
                $end = null;
            } elseif ($target === 'FINALIZADO') {
                $start ??= gmdate('Y-m-d');
                $end = gmdate('Y-m-d');
            }
            Dates::range($start, $end);
            $this->projects->transition($id, $target, $start, $end);
            $this->audit->record($terminal ? 'PROJECT_REOPEN' : 'PROJECT_TRANSITION', 'project', $id,
                ['status' => $project['status']], $input);
            return $this->show($id);
        });
    }

    public function members(int $id, array $q): array
    {
        $project = $this->requireProject($id);
        $page = $this->projects->members($id, $q);
        $data = array_map(static fn (array $r): array => ['user_id' => (string) $r['user_id'], 'name' => $r['name'],
            'role' => $r['role'], 'active' => (bool) $r['active'], 'created_at' => UserPresenter::iso($r['created_at'])], $page['rows']);
        return ['data' => $data, 'meta' => $page['meta'] + ['project_version' => (string) $project['version']]];
    }

    public function replaceMembers(int $id, array $ids, int $version): array
    {
        return $this->db->transaction(function () use ($id, $ids, $version): array {
            $project = $this->requireProject($id, true, true);
            Version::assert($project, $version);
            $this->assertWritable($project);
            $old = $this->projects->memberIds($id);
            $sorted = $ids;
            sort($sorted, SORT_NUMERIC);
            foreach ($sorted as $userId) {
                $user = $this->projects->user($userId);
                if ($user === null || !(bool) $user['active']) {
                    throw ApiException::validation(['user_ids' => 'Todos los integrantes deben estar activos.']);
                }
            }
            foreach (array_diff($old, $ids) as $userId) {
                if ($this->projects->hasAssignedTasks($id, $userId)) {
                    throw new ApiException(409, 'MEMBER_HAS_TASKS', 'No puedes retirar un integrante con tareas no canceladas.');
                }
            }
            $this->projects->replaceMembers($id, $ids, (int) $this->authorizer->id());
            $this->audit->record('PROJECT_MEMBERS_REPLACE', 'project', $id, ['user_ids' => $old], ['user_ids' => $ids]);
            return $this->members($id, ['limit' => 100, 'offset' => 0]);
        });
    }

    public function payments(int $id, array $q): array
    {
        $project = $this->requireProjectFinance($id);
        $page = $this->projects->payments($id, $q);
        foreach ($page['rows'] as &$row) {
            foreach (['id', 'registered_by', 'version'] as $key) {
                $row[$key] = (string) $row[$key];
            }
            foreach (['effective_at', 'voided_at'] as $key) {
                $row[$key] = UserPresenter::iso($row[$key]);
            }
        }
        return ['data' => $page['rows'], 'meta' => $page['meta'] + ['summary' => $this->financialSummary($project)]];
    }

    public function assertWritable(array $project): void
    {
        if (in_array($project['status'], ['FINALIZADO', 'CANCELADO'], true)) {
            throw new ApiException(409, 'PROJECT_CLOSED', 'Reabre el proyecto antes de modificarlo.');
        }
    }

    public function authorizeFinancialResponse(int $projectId): void
    {
        $project = $this->requireProject($projectId, true, true);
        if (!$this->authorizer->canViewProjectFinance($project)) {
            throw ApiException::forbidden();
        }
    }

    public function bump(int $id): void
    {
        $this->projects->bump($id);
    }

    public function assertCommercialAdjustment(array $project, string $total, bool $changed = true): void
    {
        Money::amount($total);
        $net = Money::sub($project['paid_amount'], $project['refunded_amount']);
        if (Money::compare($total, $net) < 0) {
            throw new ApiException(409, 'TOTAL_BELOW_PAID', 'El total no puede quedar por debajo del neto cobrado.');
        }
        if ($changed && Money::compare($project['paid_amount'], '0.00') > 0) {
            $this->authorizer->requireAdmin();
        }
    }

    public function financialSummary(array $p): array
    {
        $total = Money::add($p['base_amount'], $p['charges_total']);
        $net = Money::sub($p['paid_amount'], $p['refunded_amount']);
        $state = Money::compare($total, '0.00') === 0 ? 'SIN_CARGO'
            : (Money::compare($net, '0.00') === 0 ? 'NO_PAGADO' : (Money::compare($net, $total) >= 0 ? 'PAGADO' : 'PARCIAL'));
        return ['base_amount' => $p['base_amount'], 'charges_total' => Money::add($p['charges_total'], '0'),
            'total' => $total, 'paid_amount' => Money::add($p['paid_amount'], '0'),
            'refunded_amount' => Money::add($p['refunded_amount'], '0'), 'net_paid' => $net,
            'balance' => $p['billable'] ? Money::sub($total, $net) : '0.00', 'payment_status' => $state,
            'billable' => (bool) $p['billable'], 'value_kind' => $p['billable'] ? 'COMERCIAL' : 'VALOR_INTERNO_ESTIMADO'];
    }

    public function present(array $p): array
    {
        $out = [];
        foreach (['id', 'folio', 'type', 'client_id', 'leader_id', 'name', 'description', 'status', 'priority',
            'planned_start', 'planned_end', 'actual_start', 'actual_end', 'currency', 'version'] as $key) {
            $out[$key] = $p[$key] === null ? null : (string) $p[$key];
        }
        $out['billable'] = (bool) $p['billable'];
        $out += self::progress($p);
        $out['created_at'] = UserPresenter::iso($p['created_at']);
        $out['updated_at'] = UserPresenter::iso($p['updated_at']);
        if ($this->authorizer->canViewProjectFinance($p)) {
            $out['notes'] = $p['notes'];
            $out['base_amount'] = $p['base_amount'];
            $out['financial_summary'] = $this->financialSummary($p);
        }
        return $out;
    }

    public static function progress(array $row): array
    {
        $count = (int) $row['task_count'];
        $completed = (int) $row['completed_tasks'];
        return ['task_count' => $count, 'completed_tasks' => $completed,
            'has_tasks' => $count > 0, 'progress' => $count === 0 ? 0 : intdiv($completed * 100, $count)];
    }
}
