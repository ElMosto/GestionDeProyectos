<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\CatalogRepository;
use App\Support\Money;
use App\Support\Version;
use PDOException;

final class CatalogService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function list(string $kind, array $q): array
    {
        $this->authorizer->requireUser();
        $page = $this->catalog->paginate($kind, $q, $this->authorizer->isAdmin());
        return ['data' => array_map($this->present(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function show(string $kind, int $id): array
    {
        $this->authorizer->requireUser();
        $row = $this->catalog->find($kind, $id);
        if ($row === null || (!$row['active'] && !$this->authorizer->isAdmin())) {
            throw ApiException::notFound('Concepto no encontrado.');
        }
        $out = $this->present($row);
        if ($kind === 'products') {
            $out['extra_ids'] = array_map(strval(...), $this->catalog->extraIds($id, !$this->authorizer->isAdmin()));
        }
        return $out;
    }

    public function create(string $kind, array $fields): array
    {
        $this->authorizer->requireAdmin();
        return $this->write(function () use ($kind, $fields): array {
            $this->authorizer->requireAdmin();
            Money::amount($fields['price']);
            $fields['created_by'] = $this->authorizer->id();
            $id = $this->catalog->create($kind, $fields);
            $this->audit->record('CATALOG_CREATE', $kind, $id, null, $fields);
            return $this->show($kind, $id);
        });
    }

    public function update(string $kind, int $id, array $fields, int $version): array
    {
        $this->authorizer->requireAdmin();
        return $this->write(function () use ($kind, $id, $fields, $version): array {
            $this->authorizer->requireAdmin();
            $old = $this->catalog->find($kind, $id, true);
            if ($old === null) {
                throw ApiException::notFound();
            }
            Version::assert($old, $version);
            if (isset($fields['price'])) {
                Money::amount($fields['price']);
            }
            $this->catalog->update($kind, $id, $fields);
            $this->audit->record('CATALOG_UPDATE', $kind, $id, array_intersect_key($old, $fields), $fields);
            return $this->show($kind, $id);
        });
    }

    public function replaceExtras(int $id, array $ids, int $version): array
    {
        $this->authorizer->requireAdmin();
        return $this->db->transaction(function () use ($id, $ids, $version): array {
            $this->authorizer->requireAdmin();
            $product = $this->catalog->find('products', $id, true);
            if ($product === null) {
                throw ApiException::notFound();
            }
            Version::assert($product, $version);
            $sorted = $ids;
            sort($sorted, SORT_NUMERIC);
            foreach ($sorted as $extraId) {
                $extra = $this->catalog->find('extras', $extraId, true);
                if ($extra === null || !$extra['active']) {
                    throw ApiException::validation(['extra_ids' => 'Selecciona extras activos.']);
                }
            }
            $old = $this->catalog->extraIds($id);
            $this->catalog->replaceExtras($id, $sorted);
            $this->audit->record('PRODUCT_EXTRAS_REPLACE', 'product', $id, ['extra_ids' => $old], ['extra_ids' => $sorted]);
            return $this->show('products', $id);
        });
    }

    private function write(callable $work): array
    {
        try {
            return $this->db->transaction($work);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException(409, 'SKU_CONFLICT', 'El SKU ya está registrado.');
            }
            throw $e;
        }
    }

    private function present(array $row): array
    {
        $out = array_intersect_key($row, array_flip(['id', 'sku', 'name', 'description', 'type', 'unit', 'price', 'active', 'version', 'created_at', 'updated_at']));
        foreach (['id', 'version'] as $key) {
            $out[$key] = (string) $out[$key];
        }
        $out['active'] = (bool) $out['active'];
        foreach (['created_at', 'updated_at'] as $key) {
            $out[$key] = UserPresenter::iso($out[$key]);
        }
        return $out;
    }
}
