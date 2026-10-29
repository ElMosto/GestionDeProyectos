<?php

declare(strict_types=1);

namespace App\Repositories;

final class FinanceRepository extends ProjectStorage
{
    /** Cross-check the complete source, never only rows visible through the history join. */
    public function historyComplete(): bool
    {
        $row = $this->one("SELECT
            (SELECT COUNT(*) FROM projects p WHERE NOT EXISTS (SELECT 1 FROM commercial_document_events e WHERE e.project_id=p.id AND e.kind='ISSUE')) +
            (SELECT COUNT(*) FROM sales s WHERE s.issued_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM commercial_document_events e WHERE e.sale_id=s.id AND e.kind='ISSUE')) +
            (SELECT COUNT(*) FROM expenses x WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type='expense' AND a.entity_id=x.id AND a.action='EXPENSE_CREATE')) AS missing");
        return (int) $row['missing'] === 0;
    }

    public function cutCovered(array $q): bool
    {
        $row = $this->one("SELECT COUNT(*) AS missing FROM payments p
            WHERE (p.effective_at<? OR p.voided_at<? OR EXISTS (SELECT 1 FROM refunds r WHERE r.payment_id=p.id AND r.effective_at<?))
            AND NOT EXISTS (SELECT 1 FROM commercial_document_events e
                WHERE (e.project_id=p.project_id OR e.sale_id=p.sale_id) AND e.issued_at IS NOT NULL AND e.occurred_at<?)",
            [$q['cut_end'],$q['cut_end'],$q['cut_end'],$q['cut_end']]);
        return (int) $row['missing'] === 0;
    }

    private function cte(): string
    {
        return "WITH bounds AS (SELECT CAST(? AS DATETIME) AS cut_end), ranked AS (
            SELECT e.*,ROW_NUMBER() OVER (PARTITION BY e.project_id,e.sale_id ORDER BY e.occurred_at DESC,e.id DESC) AS rn
            FROM commercial_document_events e WHERE e.occurred_at < (SELECT cut_end FROM bounds)
        ), ledger AS (
            SELECT CONCAT('PAYMENT:',p.id) AS id,'PAYMENT' AS kind,p.project_id,p.sale_id,p.folio,p.effective_at,p.amount FROM payments p
                WHERE p.voided_at IS NULL OR p.voided_at>=p.effective_at
            UNION ALL SELECT CONCAT('PAYMENT_VOID:',p.id),'PAYMENT_VOID',p.project_id,p.sale_id,p.folio,p.voided_at,-p.amount FROM payments p
                WHERE p.voided_at IS NOT NULL AND p.voided_at>=p.effective_at
            UNION ALL SELECT CONCAT('REFUND:',r.id),'REFUND',p.project_id,p.sale_id,r.folio,r.effective_at,-r.amount
                FROM refunds r JOIN payments p ON p.id=r.payment_id
                WHERE p.effective_at<=r.effective_at AND (p.voided_at IS NULL OR p.voided_at>r.effective_at)
            UNION ALL SELECT CONCAT('REFUND_VOID:',r.id),'REFUND_VOID',p.project_id,p.sale_id,r.folio,r.voided_at,r.amount
                FROM refunds r JOIN payments p ON p.id=r.payment_id WHERE r.voided_at IS NOT NULL
        ), valid_at_cut AS (
            SELECT p.project_id,p.sale_id,p.amount FROM payments p
                WHERE p.effective_at<(SELECT cut_end FROM bounds) AND (p.voided_at IS NULL OR p.voided_at>=(SELECT cut_end FROM bounds))
            UNION ALL SELECT p.project_id,p.sale_id,-r.amount FROM refunds r JOIN payments p ON p.id=r.payment_id
                WHERE r.effective_at<(SELECT cut_end FROM bounds) AND (r.voided_at IS NULL OR r.voided_at>=(SELECT cut_end FROM bounds))
                AND p.effective_at<(SELECT cut_end FROM bounds) AND (p.voided_at IS NULL OR p.voided_at>=(SELECT cut_end FROM bounds))
        ), balances AS (
            SELECT project_id,sale_id,SUM(amount) AS net_paid FROM valid_at_cut GROUP BY project_id,sale_id
        ), documents AS (
            SELECT e.*,COALESCE(p.folio,s.folio) AS folio,COALESCE(b.net_paid,0.00) AS net_paid,
                e.total-COALESCE(b.net_paid,0.00) AS balance,
                CASE WHEN e.total=0 THEN 'SIN_CARGO' WHEN COALESCE(b.net_paid,0)=0 THEN 'NO_PAGADO'
                    WHEN COALESCE(b.net_paid,0)<e.total THEN 'PARCIAL' ELSE 'PAGADO' END AS payment_status
            FROM ranked e LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN sales s ON s.id=e.sale_id
            LEFT JOIN balances b ON (b.project_id=e.project_id OR b.sale_id=e.sale_id)
            WHERE e.rn=1 AND e.issued_at IS NOT NULL AND e.issued_at < (SELECT cut_end FROM bounds)
        ) ";
    }

    private function params(array $q): array
    {
        return [$q['cut_end']];
    }

    private function filters(array $q, string $alias = 'd'): array
    {
        $where = ['1=1'];
        $params = [];
        foreach (['client_id','responsible_id','source_type'] as $key) {
            if (isset($q[$key])) {
                $where[] = $alias . '.' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        if (isset($q['status'])) {
            $where[] = $alias . '.payment_status=?';
            $params[] = $q['status'];
        }
        return [implode(' AND ', $where),$params];
    }

    public function commercial(array $q): array
    {
        [$where,$params] = $this->filters($q);
        return $this->one($this->cte() . "SELECT
            COALESCE(SUM(CASE WHEN d.billable=1 AND d.cancelled=0 AND d.issued_at>=? AND d.issued_at<? THEN d.total ELSE 0 END),0.00) AS commercial_total,
            COALESCE(SUM(CASE WHEN d.sale_id IS NOT NULL AND d.cancelled=0 AND d.issued_at>=? AND d.issued_at<? THEN d.total ELSE 0 END),0.00) AS commercial_sales_total,
            COALESCE(SUM(CASE WHEN d.project_id IS NOT NULL AND d.billable=1 AND d.cancelled=0 AND d.issued_at>=? AND d.issued_at<? THEN d.total ELSE 0 END),0.00) AS commercial_projects_total,
            COALESCE(SUM(CASE WHEN d.billable=0 AND d.source_type='INTERNO' AND d.cancelled=0 AND d.issued_at>=? AND d.issued_at<? THEN d.total ELSE 0 END),0.00) AS internal_estimated_value,
            COALESCE(SUM(CASE WHEN d.billable=1 AND d.cancelled=0 AND d.balance>0 THEN d.balance ELSE 0 END),0.00) AS receivables_total
            FROM documents d WHERE " . $where,
            [...$this->params($q),$q['start'],$q['end'],$q['start'],$q['end'],$q['start'],$q['end'],$q['start'],$q['end'],...$params]);
    }

    public function documents(array $q): array
    {
        [$where,$params] = $this->filters($q);
        $where .= " AND d.cancelled=0 AND d.issued_at>=? AND d.issued_at<? AND (d.billable=1 OR d.source_type='INTERNO')";
        $args = [...$this->params($q),...$params,$q['start'],$q['end']];
        $count = $this->one($this->cte() . 'SELECT COUNT(*) AS n FROM documents d WHERE ' . $where, $args);
        return ['rows' => $this->rows($this->cte() . 'SELECT d.* FROM documents d WHERE ' . $where
            . ' ORDER BY d.issued_at,d.id LIMIT ? OFFSET ?', [...$args,$q['limit'],$q['offset']]),
            'meta' => ['total' => (int) $count['n'],'limit' => $q['limit'],'offset' => $q['offset']]];
    }

    public function cash(array $q, bool $monthly = false): array
    {
        [$where,$params] = $this->filters($q);
        $group = $monthly ? "DATE_FORMAT(l.effective_at,'%Y-%m')" : "CASE WHEN d.project_id IS NOT NULL THEN 'PROYECTO' ELSE d.source_type END";
        return $this->rows($this->cte() . 'SELECT ' . $group . " AS bucket,SUM(l.amount) AS amount
            FROM ledger l JOIN documents d ON (d.project_id=l.project_id OR d.sale_id=l.sale_id)
            WHERE l.effective_at>=? AND l.effective_at<? AND " . $where . ' GROUP BY bucket ORDER BY bucket',
            [...$this->params($q),$q['start'],$q['end'],...$params]);
    }

    public function receivables(array $q): array
    {
        [$where,$params] = $this->filters($q);
        $where .= ' AND d.billable=1 AND d.cancelled=0 AND d.balance>0';
        $args = [...$this->params($q),...$params];
        $count = $this->one($this->cte() . 'SELECT COUNT(*) AS n FROM documents d WHERE ' . $where, $args);
        return ['rows' => $this->rows($this->cte() . 'SELECT d.* FROM documents d WHERE ' . $where
            . ' ORDER BY d.issued_at,d.id LIMIT ? OFFSET ?', [...$args,$q['limit'],$q['offset']]),
            'meta' => ['total' => (int) $count['n'],'limit' => $q['limit'],'offset' => $q['offset']]];
    }

    public function transactions(array $q): array
    {
        [$where,$params] = $this->filters($q);
        $join = ' FROM ledger l JOIN documents d ON (d.project_id=l.project_id OR d.sale_id=l.sale_id) WHERE l.effective_at>=? AND l.effective_at<? AND ' . $where;
        $args = [...$this->params($q),$q['start'],$q['end'],...$params];
        $count = $this->one($this->cte() . 'SELECT COUNT(*) AS n' . $join, $args);
        return ['rows' => $this->rows($this->cte() . 'SELECT l.*,d.source_type,d.client_id,d.responsible_id' . $join
            . ' ORDER BY l.effective_at,l.id LIMIT ? OFFSET ?', [...$args,$q['limit'],$q['offset']]),
            'meta' => ['total' => (int) $count['n'],'limit' => $q['limit'],'offset' => $q['offset']]];
    }

    public function expenses(array $q, bool $monthly = false): array
    {
        [$where,$params] = $this->expenseFilters($q);
        if ($monthly) {
            return $this->rows("SELECT DATE_FORMAT(e.paid_at,'%Y-%m') AS bucket,SUM(e.amount) AS amount FROM expenses e
                WHERE e.status='PAGADO' AND e.paid_at>=? AND e.paid_at<? AND " . $where . ' GROUP BY bucket ORDER BY bucket',
                [$q['start'],$q['end'],...$params]);
        }
        $paid = $this->one("SELECT
            COALESCE(SUM(CASE WHEN e.status='PAGADO' AND e.paid_at>=? AND e.paid_at<? THEN e.amount ELSE 0 END),0.00) AS paid_expenses,
            0 AS ignored FROM expenses e WHERE " . $where, [$q['start'],$q['end'],...$params]);
        $pendingWhere = '1=1';
        $pendingParams = [];
        if (isset($q['responsible_id'])) {
            $pendingWhere .= " AND JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.responsible_id'))=?";
            $pendingParams[] = $q['responsible_id'];
        }
        if (isset($q['client_id']) || isset($q['source_type']) || isset($q['status'])) {
            $pendingWhere .= ' AND 1=0';
        }
        // Full expense snapshots are audited atomically. Later edits/cancellation do not
        // replace the amount/state evidenced at an earlier cut. Paid cash remains paid_at.
        $pending = $this->one("WITH expense_history AS (
            SELECT a.*,ROW_NUMBER() OVER (PARTITION BY a.entity_id ORDER BY a.created_at DESC,a.id DESC) AS rn
            FROM audit_logs a WHERE a.entity_type='expense' AND a.action IN ('EXPENSE_CREATE','EXPENSE_UPDATE','EXPENSE_PAY','EXPENSE_CANCEL') AND a.created_at<?
        ) SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.amount')) AS DECIMAL(12,2))),0.00) AS pending_expenses
            FROM expense_history a JOIN expenses e ON e.id=a.entity_id
            WHERE a.rn=1 AND JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.status'))<>'CANCELADO'
            AND JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.expense_date'))<=?
            AND (e.paid_at IS NULL OR e.paid_at>=?) AND " . $pendingWhere,
            [$q['cut_end'],$q['cut_off'],$q['cut_end'],...$pendingParams]);
        return ['paid_expenses' => $paid['paid_expenses'],'pending_expenses' => $pending['pending_expenses']];
    }

    private function expenseFilters(array $q): array
    {
        $where = '1=1';
        $params = [];
        if (isset($q['responsible_id'])) {
            $where .= ' AND e.responsible_id=?';
            $params[] = $q['responsible_id'];
        }
        // An expense has no commercial client/origin/payment status allocation.
        if (isset($q['client_id']) || isset($q['source_type']) || isset($q['status'])) {
            $where .= ' AND 1=0';
        }
        return [$where,$params];
    }

    public function expenseTransactions(array $q): array
    {
        [$where,$params] = $this->expenseFilters($q);
        $where .= " AND e.status='PAGADO' AND e.paid_at>=? AND e.paid_at<?";
        $args = [...$params,$q['start'],$q['end']];
        return $this->page('e.id,e.folio,e.project_id,e.concept,e.amount,e.paid_at,e.responsible_id',
            'FROM expenses e', $where, $args, 'e.paid_at,e.id', $q);
    }
}
