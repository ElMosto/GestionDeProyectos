<?php

declare(strict_types=1);

namespace App\Services;

/** Explicit allowlists keep idempotency keys and future internal fields private. */
final class PaymentSerializer
{
    public static function payment(array $row): array
    {
        return self::serialize($row, ['id', 'folio', 'project_id', 'sale_id', 'amount', 'effective_at',
            'method', 'reference', 'notes', 'status', 'registered_by', 'voided_at', 'voided_by',
            'void_reason', 'version', 'created_at', 'updated_at']);
    }

    public static function refund(array $row): array
    {
        return self::serialize($row, ['id', 'folio', 'payment_id', 'amount', 'effective_at', 'reason',
            'status', 'registered_by', 'voided_at', 'voided_by', 'version', 'created_at', 'updated_at']);
    }

    private static function serialize(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null) {
                $value = match (true) {
                    $key === 'version' => (string) $value,
                    $key === 'id', str_ends_with($key, '_id'), in_array($key, ['registered_by', 'voided_by'], true) => (string) $value,
                    str_ends_with($key, '_at') => str_replace(' ', 'T', (string) $value) . 'Z',
                    default => $value,
                };
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
