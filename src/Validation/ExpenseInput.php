<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Core\Request;
use App\Support\Version;

final class ExpenseInput
{
    public static function fields(Request $request, bool $create): array
    {
        $fields = ProjectInput::fields($request->body, [
            'project_id' => '?id','concept' => 'text200','amount' => 'money',
            'expense_date' => 'required_date','responsible_id' => 'id',
            'status' => 'PENDIENTE|PAGADO|CANCELADO','paid_at' => '?text40','notes' => '?text10000',
        ], $create ? ['concept','amount','expense_date','responsible_id','status'] : [], !$create);
        if (isset($fields['amount']) && bccomp($fields['amount'], '0', 2) <= 0) {
            throw ApiException::validation(['amount' => 'El importe debe ser positivo.']);
        }
        if (isset($fields['paid_at'])) {
            $value = $fields['paid_at'];
            if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00))\z/', $value) !== 1) {
                throw ApiException::validation(['paid_at' => 'Envía fecha ISO 8601 con segundos y zona.']);
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw ApiException::validation(['paid_at' => 'Fecha inválida.']);
            }
            $fields['paid_at'] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($fields['paid_at'] < '1000-01-01 00:00:00' || $fields['paid_at'] > '9999-12-31 23:59:59') {
                throw ApiException::validation(['paid_at' => 'La fecha debe estar dentro del rango admitido.']);
            }
        }
        if (!$create) {
            $fields['expected_version'] = Version::fromRequest($request);
        }
        return $fields;
    }
}
