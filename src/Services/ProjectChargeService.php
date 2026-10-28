<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\ProjectChargeRepository;
use App\Repositories\ProjectRepository;
use App\Support\Money;
use App\Support\Version;

final class ProjectChargeService
{
    public function __construct(
        private readonly ProjectChargeRepository $charges,
        private readonly ProjectRepository $projectRows,
        private readonly ProjectService $projects,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function list(int $projectId, array $q): array
    {
        $p = $this->projects->requireProjectFinance($projectId);
        $page = $this->charges->paginate($projectId, $q);
        return ['data' => array_map($this->present(...), $page['rows']),
            'meta' => $page['meta'] + ['summary' => $this->projects->financialSummary($p), 'project_version' => (string) $p['version']]];
    }

    public function create(int $projectId, array $fields, ?string $key): array
    {
        $this->projects->requireProject($projectId, true);
        return $this->idempotency->run('project.charge.create.' . $projectId, $key, $fields, function () use ($projectId, $fields): array {
            $project = $this->projects->requireProject($projectId, true, true);
            $this->validateProject($project);
            $data = $fields + ['quantity' => '1.000'];
            $data['amount'] = Money::amount(Money::mul($data['quantity'], $data['unit_price']));
            $newTotal = Money::add(Money::add($project['base_amount'], $project['charges_total']), $data['amount']);
            $this->projects->assertCommercialAdjustment($project, $newTotal);
            $id = $this->charges->create($projectId, $data, (int) $this->authorizer->id());
            $this->projects->bump($projectId);
            $this->audit->record('PROJECT_CHARGE_CREATE', 'project_charge', $id, null, $data);
            return $this->present($this->charges->find($id));
        }, 201, function () use ($projectId): void {
            $this->projects->authorizeFinancialResponse($projectId);
        });
    }

    public function update(int $id, array $fields, int $version, ?string $key): array
    {
        $hint = $this->charges->find($id);
        if ($hint === null) {
            throw ApiException::notFound('Cargo no encontrado.');
        }
        $this->projects->requireProject((int) $hint['project_id'], true);
        return $this->idempotency->run('project.charge.update.' . $id, $key, ['fields' => $fields, 'version' => $version], function () use ($id, $hint, $fields, $version): array {
            $project = $this->projects->requireProject((int) $hint['project_id'], true, true);
            $this->validateProject($project);
            $charge = $this->charges->find($id, true);
            if ($charge === null || (int) $charge['project_id'] !== (int) $project['id']) {
                throw ApiException::notFound();
            }
            Version::assert($charge, $version);
            $merged = array_replace($charge, $fields);
            $amount = Money::amount(Money::mul($merged['quantity'], $merged['unit_price']));
            $total = Money::add($project['base_amount'], $project['charges_total']);
            if ($charge['status'] === 'ACTIVO') {
                $total = Money::sub($total, $charge['amount']);
            }
            if ($merged['status'] === 'ACTIVO') {
                $total = Money::add($total, $amount);
            }
            $this->projects->assertCommercialAdjustment($project, $total);
            $fields['amount'] = $amount;
            $this->charges->update($id, $fields);
            $this->projects->bump((int) $project['id']);
            $this->audit->record('PROJECT_CHARGE_UPDATE', 'project_charge', $id, array_intersect_key($charge, $fields), $fields);
            return $this->present($this->charges->find($id));
        }, 200, function () use ($hint): void {
            $this->projects->authorizeFinancialResponse((int) $hint['project_id']);
        });
    }

    private function validateProject(array $p): void
    {
        $this->projects->assertWritable($p);
        if ($p['type'] !== 'INTERNO') {
            throw ApiException::validation(['project_id' => 'Solo proyectos internos admiten cargos adicionales.']);
        }
        if ($p['client_id'] !== null) {
            $client = $this->projectRows->client((int) $p['client_id']);
            if ($client === null || !(bool) $client['active']) {
                throw ApiException::validation(['client_id' => 'El cliente debe estar activo para una operación comercial nueva.']);
            }
        }
    }

    private function present(array $row): array
    {
        $out = [];
        foreach (['id', 'project_id', 'concept', 'quantity', 'unit_price', 'amount', 'charge_date', 'status', 'author_id', 'created_by', 'version'] as $key) {
            $out[$key] = $row[$key] === null ? null : (string) $row[$key];
        }
        $out['created_at'] = UserPresenter::iso($row['created_at']);
        $out['updated_at'] = UserPresenter::iso($row['updated_at']);
        return $out;
    }
}
