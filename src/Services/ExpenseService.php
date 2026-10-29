<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\ExpenseRepository;
use App\Repositories\SequenceRepository;
use App\Support\Version;

final class ExpenseService
{
    public function __construct(
        private readonly ExpenseRepository $repository,
        private readonly SequenceRepository $sequences,
        private readonly Authorizer $authorizer,
        private readonly ProjectIdempotency $idempotency,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function listing(array $q): array
    {
        $this->authorizer->requireAdmin();
        $result = $this->repository->listing($q);
        return ['data' => array_map(self::serialize(...), $result['rows']),'meta' => $result['meta']];
    }

    public function show(int $id): array
    {
        $this->authorizer->requireAdmin();
        return self::serialize($this->repository->find($id) ?? throw ApiException::notFound());
    }

    public function createExpense(array $fields, ?string $key): array
    {
        $this->authorizer->requireAdmin();
        return $this->idempotency->run('expenses.create', $key, $fields, function () use ($fields): array {
            $fields += ['project_id' => null,'notes' => null,'paid_at' => null];
            if ($fields['status'] === 'CANCELADO') {
                throw ApiException::validation(['status' => 'No se crea un gasto cancelado.']);
            }
            $this->validateState($fields);
            $id = $this->repository->create($fields + ['folio' => $this->sequences->nextExpense(),'created_by' => $this->authorizer->id()]);
            $after = $this->repository->find($id);
            $this->audit->record('EXPENSE_CREATE', 'expense', $id, null, $after);
            return self::serialize($after);
        }, 201, fn () => $this->authorizer->requireAdmin());
    }

    public function updateExpense(int $id, array $fields, ?string $key): array
    {
        $this->authorizer->requireAdmin();
        return $this->idempotency->run('expenses.update.' . $id, $key, $fields, function () use ($id, $fields): array {
            $old = $this->repository->find($id, true) ?? throw ApiException::notFound();
            Version::assert($old, $fields['expected_version']);
            unset($fields['expected_version']);
            if ($old['status'] !== 'PENDIENTE') {
                throw new ApiException(409, 'EXPENSE_IMMUTABLE', 'Un gasto pagado o cancelado conserva su historia y no se puede editar.');
            }
            if ($fields === []) {
                throw ApiException::validation(['fields' => 'Envía al menos un campo editable.']);
            }
            $after = array_replace($old, $fields);
            $this->validateState($after);
            $action = match ($after['status']) {
                'PAGADO' => 'EXPENSE_PAY', 'CANCELADO' => 'EXPENSE_CANCEL', default => 'EXPENSE_UPDATE',
            };
            match ($after['status']) {
                'PAGADO' => $this->repository->payExpense($id, $fields),
                'CANCELADO' => $this->repository->cancelExpense($id, $fields),
                default => $this->repository->editPending($id, $fields),
            };
            $after = $this->repository->find($id);
            $this->audit->record($action, 'expense', $id, $old, $after);
            return self::serialize($after);
        }, 200, fn () => $this->authorizer->requireAdmin());
    }

    private function validateState(array $fields): void
    {
        if (($fields['status'] === 'PAGADO') !== ($fields['paid_at'] !== null)) {
            throw ApiException::validation(['paid_at' => 'Solo PAGADO requiere fecha real de pago; los demás estados no admiten paid_at.']);
        }
        if ($fields['paid_at'] !== null && $fields['paid_at'] > gmdate('Y-m-d H:i:s')) {
            throw ApiException::validation(['paid_at' => 'Registra la fecha real del pago, no una fecha futura.']);
        }
        if (!$this->repository->referencesValid($fields)) {
            throw ApiException::validation(['responsible_id' => 'El responsable debe estar activo y el proyecto debe existir.']);
        }
    }

    private static function serialize(array $row): array
    {
        $out = array_intersect_key($row, array_flip(['id','folio','project_id','concept','amount','expense_date','responsible_id','status','paid_at','notes','created_by','version','created_at','updated_at']));
        foreach (['id','project_id','responsible_id','created_by','version'] as $field) {
            $out[$field] = $out[$field] === null ? null : (string) $out[$field];
        }
        foreach (['paid_at','created_at','updated_at'] as $field) {
            $out[$field] = $out[$field] === null ? null : str_replace(' ', 'T', $out[$field]) . 'Z';
        }
        return $out;
    }
}
