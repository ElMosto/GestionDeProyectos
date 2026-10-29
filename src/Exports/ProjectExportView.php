<?php

declare(strict_types=1);

namespace App\Exports;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Explicit presentation whitelist shared by project outputs; never computes money (§9). */
final class ProjectExportView
{
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function timezone(array $data): DateTimeZone
    {
        try {
            return new DateTimeZone(self::text($data['user_timezone'] ?? $data['studio']['timezone'] ?? 'UTC'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    public static function date(mixed $value, DateTimeZone $timezone): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'Fecha no disponible';
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('d/m/Y H:i');
        } catch (Throwable) {
            return 'Fecha no disponible';
        }
    }

    public static function money(mixed $value, string $currency): string
    {
        // Exact server strings only: neither coercion to float nor totals reconstructed here.
        return is_string($value) && preg_match('/^-?\d+\.\d{2}$/D', $value) === 1
            ? $value . ' ' . $currency : 'No disponible';
    }

    public static function estimated(array $project): bool
    {
        $summary = $project['financial_summary'] ?? [];
        return ($summary['value_kind'] ?? '') === 'VALOR_INTERNO_ESTIMADO'
            || (($project['type'] ?? '') === 'INTERNO'
                && ($summary['billable'] ?? $project['billable'] ?? false) !== true);
    }

    /** All financial fields come from server-calculated values, never from expenses/notes. */
    public static function financialRows(array $project): array
    {
        $summary = $project['financial_summary'] ?? [];
        $currency = self::text($project['currency'] ?? 'MXN');
        $estimated = self::estimated($project);
        $rows = [
            ['Concepto base', self::money($summary['base_amount'] ?? $project['base_amount'] ?? null, $currency)],
            ['Cargos adicionales activos', self::money($summary['charges_total'] ?? null, $currency)],
            [$estimated ? 'valor interno estimado' : 'Total', self::money($summary['total'] ?? null, $currency)],
        ];
        if (!$estimated) {
            foreach (['paid_amount' => 'Pagos válidos', 'refunded_amount' => 'Reembolsado',
                'net_paid' => 'Neto cobrado', 'balance' => 'Saldo'] as $key => $label) {
                $rows[] = [$label, self::money($summary[$key] ?? null, $currency)];
            }
            $rows[] = ['Estado de pago', self::text($summary['payment_status'] ?? 'No disponible')];
        } else {
            $rows[] = ['Saldo', 'Sin cuenta por cobrar'];
        }
        return $rows;
    }

    public static function projectRows(array $data): array
    {
        $p = $data['project'];
        $rows = [];
        foreach (['folio' => 'Folio', 'name' => 'Proyecto', 'description' => 'Descripción',
            'type' => 'Tipo', 'status' => 'Estado', 'progress' => 'Avance (%)'] as $key => $label) {
            $rows[] = [$label, self::text($p[$key] ?? '')];
        }
        $rows[] = ['Cliente', self::text($data['client']['name'] ?? 'Sin cliente')];
        $rows[] = ['Líder', self::text($data['leader']['name'] ?? 'No disponible')];
        foreach (['planned_start' => 'Inicio previsto', 'planned_end' => 'Fin previsto',
            'actual_start' => 'Inicio real', 'actual_end' => 'Fin real'] as $key => $label) {
            $rows[] = [$label, self::text($p[$key] ?? 'sin programar')];
        }
        return $rows;
    }
}
