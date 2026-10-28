<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\StageRepository;
use App\Support\Dates;
use App\Support\Version;

final class StageService
{
    public function __construct(
        private readonly StageRepository $stages,
        private readonly ProjectService $projects,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function list(int $projectId, array $q): array
    {
        $p = $this->projects->requireProject($projectId);
        $page = $this->stages->paginate($projectId, $q);
        return ['data' => array_map($this->present(...), $page['rows']),
            'meta' => $page['meta'] + ['project_version' => (string) $p['version']]];
    }

    public function create(int $projectId, array $fields): array
    {
        return $this->db->transaction(function () use ($projectId, $fields): array {
            $p = $this->projects->requireProject($projectId, true, true);
            $this->projects->assertWritable($p);
            Dates::within($fields['start_date'] ?? null, $fields['end_date'] ?? null, $p['planned_start'], $p['planned_end']);
            $id = $this->stages->create($projectId, $fields, (int) $this->authorizer->id());
            $this->projects->bump($projectId);
            $this->audit->record('STAGE_CREATE', 'stage', $id, null, $fields);
            return $this->present($this->stages->find($id));
        });
    }

    public function update(int $id, array $fields, int $version): array
    {
        $hint = $this->stages->find($id);
        if ($hint === null) {
            throw ApiException::notFound('Etapa no encontrada.');
        }
        return $this->db->transaction(function () use ($id, $fields, $version, $hint): array {
            $p = $this->projects->requireProject((int) $hint['project_id'], true, true);
            $this->projects->assertWritable($p);
            $stage = $this->stages->find($id, true);
            if ($stage === null || (int) $stage['project_id'] !== (int) $p['id']) {
                throw ApiException::notFound();
            }
            Version::assert($stage, $version);
            $merged = array_replace($stage, $fields);
            Dates::within($merged['start_date'], $merged['end_date'], $p['planned_start'], $p['planned_end']);
            foreach ($this->stages->taskDates($id) as $dates) {
                Dates::within($dates['start_date'], $dates['end_date'], $merged['start_date'], $merged['end_date']);
                Dates::within($dates['start_date'], $dates['end_date'], $p['planned_start'], $p['planned_end']);
            }
            if (($fields['status'] ?? null) === 'COMPLETADA' && $this->stages->incomplete($id)) {
                throw new ApiException(409, 'REQUIRED_TASKS_INCOMPLETE', 'La etapa conserva tareas obligatorias sin completar.');
            }
            $this->stages->update($id, $fields);
            $this->projects->bump((int) $p['id']);
            $this->audit->record('STAGE_UPDATE', 'stage', $id, array_intersect_key($stage, $fields), $fields);
            return $this->present($this->stages->find($id));
        });
    }

    public function reorder(int $projectId, array $ids, int $version): array
    {
        return $this->db->transaction(function () use ($projectId, $ids, $version): array {
            $p = $this->projects->requireProject($projectId, true, true);
            Version::assert($p, $version);
            $this->projects->assertWritable($p);
            $existing = $this->stages->ids($projectId);
            $sorted = $ids;
            sort($sorted, SORT_NUMERIC);
            if ($sorted !== $existing) {
                throw ApiException::validation(['stage_ids' => 'Envía exactamente todas las etapas del proyecto sin duplicados.']);
            }
            $this->stages->reorder($ids);
            $this->projects->bump($projectId);
            $this->audit->record('STAGES_REORDER', 'project', $projectId, null, ['stage_ids' => $ids]);
            return $this->list($projectId, ['limit' => 100, 'offset' => 0]);
        });
    }

    public function present(array $stage): array
    {
        $out = [];
        foreach (['id', 'project_id', 'name', 'description', 'start_date', 'end_date', 'status', 'version'] as $key) {
            $out[$key] = $stage[$key] === null ? null : (string) $stage[$key];
        }
        $out['sort_order'] = (int) $stage['sort_order'];
        return $out + ProjectService::progress($stage);
    }
}
