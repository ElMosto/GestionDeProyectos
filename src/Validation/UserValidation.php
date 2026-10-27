<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Policies\Role;
use App\Support\Pagination;

/**
 * Validación de forma y listas blancas de `/api/v1/users` (§16).
 *
 * - Un campo desconocido se ignora.
 * - Un campo sensible no permitido se rechaza con 422, nunca se descarta en silencio.
 * - La resolución del rol contra la base de datos es del servicio; aquí solo se
 *   comprueba el tipo y el catálogo cerrado de roles.
 */
final class UserValidation
{
    /** Campos que jamás se aceptan en el alta. */
    private const CREATE_FORBIDDEN = [
        'id',
        'active',
        'must_change_password',
        'password_hash',
        'created_by',
        'version',
        'avatar_path',
        'last_login_at',
        'password_changed_at',
        'created_at',
        'updated_at',
    ];

    /** Campos que jamás se aceptan en la edición administrativa. */
    private const UPDATE_FORBIDDEN = [
        'id',
        'active',
        'must_change_password',
        'password',
        'password_hash',
        'email',
        'created_by',
        'avatar_path',
        'last_login_at',
        'password_changed_at',
        'created_at',
        'updated_at',
    ];

    private const SORTABLE = ['name', 'created_at', 'last_login_at'];
    private const DEFAULT_TIMEZONE = 'America/Mexico_City';

    /**
     * @param array<string, mixed> $query
     * @return array{q: ?string, role: ?string, active: ?int, limit: int, offset: int, sort: string, direction: string}
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

        $role = $query['role'] ?? null;

        if ($role !== null && (!is_string($role) || !Role::isValid($role))) {
            throw ApiException::validation(['role' => 'El rol indicado no existe.']);
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
            'role' => is_string($role) ? $role : null,
            'active' => $active,
            'limit' => Pagination::limit($query['limit'] ?? null),
            'offset' => Pagination::offset($query['offset'] ?? null),
            'sort' => Pagination::sort($query['sort'] ?? null, self::SORTABLE, 'name'),
            'direction' => Pagination::direction($query['direction'] ?? null, 'asc'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{name: string, email: string, role_id: ?int, role_code: ?string, password: string, phone: ?string, job_title: ?string, timezone: string}
     */
    public static function create(array $body, int $minPasswordLength): array
    {
        $errors = [];

        foreach (self::CREATE_FORBIDDEN as $field) {
            if (array_key_exists($field, $body)) {
                $errors[$field] = 'Este campo no se puede asignar al crear un usuario.';
            }
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : null;

        if ($name === null || $name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'El nombre es obligatorio y admite hasta 120 caracteres.';
        }

        $email = isset($body['email']) && is_string($body['email'])
            ? mb_strtolower(trim($body['email']))
            : null;

        if (
            $email === null
            || $email === ''
            || mb_strlen($email) > 180
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'El correo es obligatorio y debe ser una dirección válida.';
        }

        [$roleId, $roleCode, $roleError] = self::parseRole($body, true);

        if ($roleError !== null) {
            $errors['role_id'] = $roleError;
        }

        $password = $body['password'] ?? null;

        if (!is_string($password) || mb_strlen($password) < $minPasswordLength) {
            $errors['password'] = sprintf(
                'La contraseña inicial debe tener al menos %d caracteres.',
                $minPasswordLength
            );
        } elseif (mb_strlen($password) > 200) {
            $errors['password'] = 'La contraseña admite hasta 200 caracteres.';
        }

        $phone = self::parsePhone($body, $errors);
        $jobTitle = self::parseJobTitle($body, $errors);
        $timezone = self::parseTimezone($body, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [
            'name' => (string) $name,
            'email' => (string) $email,
            'role_id' => $roleId,
            'role_code' => $roleCode,
            'password' => (string) $password,
            'phone' => $phone,
            'job_title' => $jobTitle,
            'timezone' => $timezone,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{fields: array<string, mixed>, role_id: ?int, role_code: ?string, expected_version: int}
     */
    public static function update(array $body): array
    {
        $errors = [];

        foreach (self::UPDATE_FORBIDDEN as $field) {
            if (array_key_exists($field, $body)) {
                $errors[$field] = 'Este campo no se puede modificar desde la administración de usuarios.';
            }
        }

        $expectedVersion = self::parseVersion($body['version'] ?? null);

        if ($expectedVersion === null) {
            $errors['version'] = 'La versión es obligatoria y debe ser un entero positivo.';
        }

        $fields = [];

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : null;

            if ($name === null || $name === '' || mb_strlen($name) > 120) {
                $errors['name'] = 'El nombre es obligatorio y admite hasta 120 caracteres.';
            } else {
                $fields['name'] = $name;
            }
        }

        if (array_key_exists('phone', $body)) {
            $phone = self::validatePhone($body['phone'], $errors);
            $fields['phone'] = $phone;
        }

        if (array_key_exists('job_title', $body)) {
            $jobTitle = self::validateJobTitle($body['job_title'], $errors);
            $fields['job_title'] = $jobTitle;
        }

        if (array_key_exists('timezone', $body)) {
            $timezone = $body['timezone'];

            if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
                $errors['timezone'] = 'La zona horaria no es válida.';
            } else {
                $fields['timezone'] = $timezone;
            }
        }

        [$roleId, $roleCode, $roleError] = self::parseRole($body, false);

        if ($roleError !== null) {
            $errors['role_id'] = $roleError;
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [
            'fields' => $fields,
            'role_id' => $roleId,
            'role_code' => $roleCode,
            'expected_version' => (int) $expectedVersion,
        ];
    }

    public static function id(string $raw): int
    {
        $id = ctype_digit($raw) ? (int) $raw : 0;

        if ($id < 1) {
            throw ApiException::notFound('Usuario no encontrado.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    private static function parseRole(array $body, bool $required): array
    {
        $roleId = null;
        $roleCode = null;

        if (array_key_exists('role_id', $body)) {
            $roleId = self::parsePositiveInt($body['role_id']);

            if ($roleId === null) {
                return [null, null, 'El rol indicado no es válido.'];
            }
        }

        if (array_key_exists('role', $body)) {
            $role = $body['role'];

            if (!is_string($role) || !Role::isValid($role)) {
                return [null, null, 'El rol indicado no es válido.'];
            }

            $roleCode = $role;
        }

        if ($required && $roleId === null && $roleCode === null) {
            return [null, null, 'Indica el rol del usuario.'];
        }

        return [$roleId, $roleCode, null];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function parsePhone(array $body, array &$errors): ?string
    {
        if (!array_key_exists('phone', $body)) {
            return null;
        }

        return self::validatePhone($body['phone'], $errors);
    }

    /**
     * @param array<string, string> $errors
     */
    private static function validatePhone(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            !is_string($value)
            || mb_strlen(trim($value)) > 32
            || preg_match('/[\x00-\x1F]/', $value) === 1
        ) {
            $errors['phone'] = 'El teléfono admite hasta 32 caracteres.';

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function parseJobTitle(array $body, array &$errors): ?string
    {
        if (!array_key_exists('job_title', $body)) {
            return null;
        }

        return self::validateJobTitle($body['job_title'], $errors);
    }

    /**
     * @param array<string, string> $errors
     */
    private static function validateJobTitle(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || mb_strlen(trim($value)) > 120) {
            $errors['job_title'] = 'El cargo admite hasta 120 caracteres.';

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function parseTimezone(array $body, array &$errors): string
    {
        if (!array_key_exists('timezone', $body)) {
            return self::DEFAULT_TIMEZONE;
        }

        $timezone = $body['timezone'];

        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'La zona horaria no es válida.';

            return self::DEFAULT_TIMEZONE;
        }

        return $timezone;
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

    private static function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $number = (int) $value;

            return $number > 0 ? $number : null;
        }

        return null;
    }
}
