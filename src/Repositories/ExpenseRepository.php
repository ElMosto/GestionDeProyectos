<?php

declare(strict_types=1);

namespace App\Repositories;

final class ExpenseRepository extends ProjectStorage
{
    private const FIELDS = ['project_id','concept','amount','expense_date','responsible_id','status','paid_at','notes'];

    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT id,folio,project_id,concept,amount,expense_date,responsible_id,status,paid_at,notes,created_by,version,created_at,updated_at FROM expenses WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function create(array $fields): int
    {
        return $this->insertRow('expenses', $fields, [...self::FIELDS,'folio','created_by']);
    }

    public function editPending(int $id, array $fields): void
    {
        $this->changeRow('expenses', $id, $fields, self::FIELDS);
    }

    public function payExpense(int $id, array $fields): void
    {
        $this->changeRow('expenses', $id, $fields, self::FIELDS);
    }

    public function cancelExpense(int $id, array $fields): void
    {
        $this->changeRow('expenses', $id, $fields, self::FIELDS);
    }

    public function referencesValid(array $fields): bool
    {
        if ($this->one('SELECT id FROM users WHERE id=? AND active=1 LOCK IN SHARE MODE', [$fields['responsible_id']]) === null) {
            return false;
        }
        return $fields['project_id'] === null || $this->one('SELECT id FROM projects WHERE id=? LOCK IN SHARE MODE', [$fields['project_id']]) !== null;
    }

    public function listing(array $q): array
    {
        $where = 'expense_date>=? AND expense_date<=?';
        $params = [$q['from'],$q['to']];
        foreach (['project_id','responsible_id','status'] as $key) {
            if (isset($q[$key])) {
                $where .= ' AND ' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        return $this->page('id,folio,project_id,concept,amount,expense_date,responsible_id,status,paid_at,notes,created_by,version,created_at,updated_at',
            'FROM expenses', $where, $params, 'expense_date DESC,id DESC', $q);
    }
}
