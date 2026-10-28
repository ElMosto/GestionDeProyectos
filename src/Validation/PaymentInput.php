<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Core\Request;
use App\Support\Version;
use DateTimeImmutable;
use DateTimeZone;

final class PaymentInput
{
    public static function fields(Request $request, string $operation): array
    {
        $schema = match ($operation) {
            'payment' => ['amount' => 'money', 'method' => 'EFECTIVO|TRANSFERENCIA|TARJETA|OTRO',
                'reference' => '?text120', 'notes' => '?text10000', 'effective_at' => 'text40'],
            'mark-paid' => ['method' => 'EFECTIVO|TRANSFERENCIA|TARJETA|OTRO',
                'reference' => '?text120', 'notes' => '?text10000', 'effective_at' => 'text40'],
            'void' => ['reason' => 'text255'],
            'refund' => ['amount' => 'money', 'effective_at' => 'text40', 'reason' => 'text255'],
            default => throw new \InvalidArgumentException('Operación de pago desconocida.'),
        };
        $required = array_values(array_diff(array_keys($schema), ['reference', 'notes']));
        $fields = ProjectInput::fields($request->body, $schema, $required, true);
        if (isset($fields['amount']) && bccomp($fields['amount'], '0.00', 2) <= 0) {
            throw ApiException::validation(['amount' => 'El importe debe ser positivo.']);
        }
        if (isset($fields['effective_at'])) {
            $fields['effective_at'] = self::effectiveAt($fields['effective_at']);
        }
        if (in_array($operation, ['payment', 'mark-paid'], true)) {
            $fields['reference'] ??= null;
            $fields['notes'] ??= null;
        }
        $fields['expected_version'] = array_key_exists('version', $request->body) || array_key_exists('expected_version', $request->body)
            || $request->header('if-match') !== null ? Version::fromRequest($request) : null;
        return $fields;
    }

    private static function effectiveAt(string $value): string
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value) !== 1
            || (int) substr($value, 0, 4) < 1000) {
            throw ApiException::validation(['effective_at' => 'Envía fecha ISO 8601 con segundos y zona horaria.']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || (preg_match('/[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00)\z/', $value) !== 1
                && !str_ends_with($value, 'Z'))) {
            throw ApiException::validation(['effective_at' => 'La fecha no es válida.']);
        }
        $utc = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ((int) substr($utc, 0, 4) < 1000 || (int) substr($utc, 0, 4) > 9999) {
            throw ApiException::validation(['effective_at' => 'La fecha queda fuera del rango admitido.']);
        }
        return $utc;
    }
}
