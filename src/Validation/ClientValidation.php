<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Pagination;

/**
 * Validación de forma y listas blancas de `/api/v1/clients` (§7, §16).
 *
 * La regla BANDA (representante + teléfono) se comprueba en el alta; en la
 * edición se valida sobre el estado fusionado dentro del servicio, porque un
 * PATCH puede cambiar solo uno de los dos campos.
 */
final class ClientValidation
{
    private const TYPES = ['NORMAL', 'BANDA'];

    private const CREATE_FORBIDDEN = [
        'id',
        'active',
        'created_by',
        'version',
        'created_at',
        'updated_at',
    ];

    private const UPDATE_FORBIDDEN = [
        'id',
        'active',
        'created_by',
        'created_at',
        'updated_at',
    ];

    private const SORTABLE = ['name', 'created_at', 'updated_at'];

    /**
     * @param array<string, mixed> $query
     * @return array{q: ?string, type: ?string, active: ?int, limit: int, offset: int, sort: string, direction: string}
     */
    public static function listQuery(array $query): array
    {
        $q = $query['q'] ?? null;
        $q = is_string($q) ? trim($q) : null;

        if ($q === '') {
            $q = null;
        }

        if ($q !== null && mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }

        $type = $query['type'] ?? null;

        if ($type !== null && (!is_string($type) || !in_array($type, self::TYPES, true))) {
            throw ApiException::validation(['type' => 'El tipo debe ser NORMAL o BANDA.']);
        }

        $active = null;

        if (array_key_exists('active', $query)) {
            $raw = $query['active'];

            if ($raw === '0' || $raw === 0) {
                $active = 0;
            } elseif ($raw === '1' || $raw === 1) {
                $active = 1;
            } else {
                throw ApiException::validation(['active' => 'El filtro active debe ser 0 o 1.']);
            }
        }

        return [
            'q' => $q,
            'type' => is_string($type) ? $type : null,
            'active' => $active,
            'limit' => Pagination::limit($query['limit'] ?? null),
            'offset' => Pagination::offset($query['offset'] ?? null),
            'sort' => Pagination::sort($query['sort'] ?? null, self::SORTABLE, 'name'),
            'direction' => Pagination::direction($query['direction'] ?? null, 'asc'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(array $body): array
    {
        $errors = [];

        foreach (self::CREATE_FORBIDDEN as $field) {
            if (array_key_exists($field, $body)) {
                $errors[$field] = 'Este campo no se puede asignar al crear un cliente.';
            }
        }

        $type = $body['type'] ?? 'NORMAL';

        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $errors['type'] = 'El tipo debe ser NORMAL o BANDA.';
            $type = 'NORMAL';
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : null;

        if ($name === null || $name === '' || mb_strlen($name) > 160) {
            $errors['name'] = 'El nombre es obligatorio y admite hasta 160 caracteres.';
        }

        $contactName = self::nullableString($body, 'contact_name', 120, $errors);
        $representativeName = self::nullableString($body, 'representative_name', 120, $errors);
        $phone = self::nullableString($body, 'phone', 32, $errors);
        $address = self::nullableString($body, 'address', 255, $errors);
        $notes = self::nullableString($body, 'notes', 5000, $errors, true);
        $email = self::nullableEmail($body, $errors);

        if ($type === 'BANDA') {
            if ($representativeName === null || $representativeName === '') {
                $errors['representative_name'] = 'Una banda exige el nombre del representante.';
            }

            if ($phone === null || $phone === '') {
                $errors['phone'] = 'Una banda exige un teléfono de contacto.';
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [
            'type' => $type,
            'name' => (string) $name,
            'contact_name' => $contactName,
            'representative_name' => $representativeName,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{fields: array<string, mixed>, expected_version: int}
     */
    public static function update(array $body): array
    {
        $errors = [];

        foreach (self::UPDATE_FORBIDDEN as $field) {
            if (array_key_exists($field, $body)) {
                $errors[$field] = 'Este campo no se puede modificar desde este endpoint.';
            }
        }

        $expectedVersion = self::parseVersion($body['version'] ?? null);

        if ($expectedVersion === null) {
            $errors['version'] = 'La versión es obligatoria y debe ser un entero positivo.';
        }

        $fields = [];

        if (array_key_exists('type', $body)) {
            $type = $body['type'];

            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                $errors['type'] = 'El tipo debe ser NORMAL o BANDA.';
            } else {
                $fields['type'] = $type;
            }
        }

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : null;

            if ($name === null || $name === '' || mb_strlen($name) > 160) {
                $errors['name'] = 'El nombre es obligatorio y admite hasta 160 caracteres.';
            } else {
                $fields['name'] = $name;
            }
        }

        foreach (['contact_name' => 120, 'representative_name' => 120, 'phone' => 32, 'address' => 255] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $fields[$field] = self::nullableString($body, $field, $max, $errors);
            }
        }

        if (array_key_exists('notes', $body)) {
            $fields['notes'] = self::nullableString($body, 'notes', 5000, $errors, true);
        }

        if (array_key_exists('email', $body)) {
            $fields['email'] = self::nullableEmail($body, $errors);
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [
            'fields' => $fields,
            'expected_version' => (int) $expectedVersion,
        ];
    }

    public static function id(string $raw): int
    {
        $id = ctype_digit($raw) ? (int) $raw : 0;

        if ($id < 1) {
            throw ApiException::notFound('Cliente no encontrado.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function nullableString(array $body, string $field, int $max, array &$errors, bool $multiline = false): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }

        $value = $body[$field];

        if ($value === null || $value === '') {
            return null;
        }

        $controlPattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F]/' : '/[\x00-\x1F]/';

        if (
            !is_string($value)
            || mb_strlen(trim($value)) > $max
            || preg_match($controlPattern, $value) === 1
        ) {
            $errors[$field] = sprintf('El campo admite hasta %d caracteres.', $max);

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function nullableEmail(array $body, array &$errors): ?string
    {
        if (!array_key_exists('email', $body)) {
            return null;
        }

        $email = $body['email'];

        if ($email === null || $email === '') {
            return null;
        }

        if (!is_string($email)) {
            $errors['email'] = 'El correo no es válido.';

            return null;
        }

        $email = mb_strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'El correo no es válido.';

            return null;
        }

        return $email;
    }

    private static function parseVersion(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $version = (int) $value;

            return $version > 0 ? $version : null;
        }

        return null;
    }
}
