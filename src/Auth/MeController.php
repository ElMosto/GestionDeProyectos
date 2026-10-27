<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\PasswordResetRepository;
use App\Auth\Repositories\UserRepository;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\Authorizer;
use App\Policies\Capabilities;

/**
 * «Mi usuario» (§6, §15).
 *
 * El perfil es una lista blanca: rol, estado, permisos, correo y contraseña no se
 * cambian por aquí. Un campo sensible enviado se **rechaza con 422**, no se ignora
 * en silencio (§16: listas blancas contra asignación masiva).
 */
final class MeController
{
    /** Campos que nunca se aceptan en el PATCH de perfil. */
    private const REJECTED_FIELDS = [
        'id',
        'role',
        'role_id',
        'active',
        'email',
        'password',
        'password_hash',
        'password_changed_at',
        'must_change_password',
        'avatar_path',
        'created_by',
        'last_login_at',
        'created_at',
        'updated_at',
    ];

    /** Campos editables desde el perfil. */
    private const EDITABLE_FIELDS = ['name', 'phone', 'job_title', 'timezone', 'notification_prefs'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuthContext $context,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly UserPresenter $presenter,
        private readonly SessionManager $session,
        private readonly TokenService $tokens,
        private readonly PasswordResetRepository $passwordResets,
        private readonly AvatarStorage $avatars,
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /**
     * `GET /api/v1/me`
     */
    public function show(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_EDIT);

        return Response::data($this->presenter->sessionPayload($this->authorizer->requireUser()));
    }

    /**
     * `PATCH /api/v1/me`
     */
    public function update(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_EDIT);
        $user = $this->authorizer->requireUser();
        $userId = (int) $user['id'];
        $body = $request->body;

        $errors = [];
        $changes = [];

        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $errors[$field] = 'Este campo no se puede modificar desde el perfil.';
            }
        }

        foreach (array_keys($body) as $field) {
            if (in_array($field, self::REJECTED_FIELDS, true)) {
                continue;
            }

            if (!in_array($field, self::EDITABLE_FIELDS, true) && $field !== 'version') {
                $errors[$field] = 'Campo no editable desde el perfil.';
            }
        }

        $expectedVersion = null;

        if (array_key_exists('version', $body)) {
            $version = $body['version'];

            if (is_int($version) || (is_string($version) && ctype_digit($version))) {
                $expectedVersion = (int) $version;
            } else {
                $errors['version'] = 'La versión debe ser un número entero.';
            }
        }

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : null;

            if ($name === null || $name === '' || mb_strlen($name) > 120) {
                $errors['name'] = 'El nombre es obligatorio y admite hasta 120 caracteres.';
            } else {
                $changes['name'] = $name;
            }
        }

        if (array_key_exists('phone', $body)) {
            $phone = $body['phone'];

            if ($phone === null || $phone === '') {
                $changes['phone'] = null;
            } elseif (!is_string($phone) || mb_strlen(trim($phone)) > 32 || preg_match('/[\x00-\x1F]/', $phone) === 1) {
                $errors['phone'] = 'El teléfono admite hasta 32 caracteres.';
            } else {
                $changes['phone'] = trim($phone);
            }
        }

        if (array_key_exists('job_title', $body)) {
            $jobTitle = $body['job_title'];

            if ($jobTitle === null || $jobTitle === '') {
                $changes['job_title'] = null;
            } elseif (!is_string($jobTitle) || mb_strlen(trim($jobTitle)) > 120) {
                $errors['job_title'] = 'El cargo admite hasta 120 caracteres.';
            } else {
                $changes['job_title'] = trim($jobTitle);
            }
        }

        if (array_key_exists('timezone', $body)) {
            $timezone = $body['timezone'];

            if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
                $errors['timezone'] = 'La zona horaria no es válida.';
            } else {
                $changes['timezone'] = $timezone;
            }
        }

        if (array_key_exists('notification_prefs', $body)) {
            $prefs = $body['notification_prefs'];

            if ($prefs === null) {
                $changes['notification_prefs'] = null;
            } elseif (is_array($prefs) && self::validNotificationPrefs($prefs)) {
                $changes['notification_prefs'] = $prefs === []
                    ? null
                    : json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } else {
                $errors['notification_prefs'] = 'Las preferencias deben ser un objeto de valores booleanos.';
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $before = [];

        foreach (array_keys($changes) as $field) {
            $before[$field] = $user[$field] ?? null;
        }

        $updated = $this->users->updateProfile($userId, $changes, $expectedVersion);

        if ($updated === null) {
            throw new ApiException(
                409,
                'VERSION_CONFLICT',
                'El perfil cambió mientras lo editabas. Vuelve a cargarlo e inténtalo de nuevo.',
                ['version' => 'La versión enviada ya no es la actual.'],
            );
        }

        if ($changes !== []) {
            $this->audit->log(AuditLogger::PROFILE_UPDATE, 'user', $userId, $before, $changes, $userId);
        }

        $this->context->setUser($updated, $this->context->authMethod() ?? AuthContext::METHOD_SESSION);

        return Response::data($this->presenter->sessionPayload($updated));
    }

    /**
     * `POST /api/v1/me/password`
     *
     * Exige la contraseña actual, revoca todos los tokens móviles y rota la sesión.
     */
    public function changePassword(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_EDIT);
        $user = $this->authorizer->requireUser();
        $userId = (int) $user['id'];

        $current = $request->input('current_password');
        $new = $request->input('new_password');
        $confirmation = $request->input('new_password_confirmation');

        $errors = [];

        if (!is_string($current) || $current === '') {
            $errors['current_password'] = 'Escribe tu contraseña actual.';
        }

        if (!is_string($new) || $new === '') {
            $errors['new_password'] = 'Escribe la nueva contraseña.';
        }

        if (is_string($confirmation) && is_string($new) && $confirmation !== $new) {
            $errors['new_password_confirmation'] = 'La confirmación no coincide.';
        }

        $minLength = max(8, (int) $this->config->get('security.password_min_length', 12));

        if (is_string($new) && $new !== '' && mb_strlen($new) < $minLength) {
            $errors['new_password'] = sprintf('La contraseña debe tener al menos %d caracteres.', $minLength);
        }

        $hash = $this->users->passwordHash($userId);

        if ($hash === null) {
            throw ApiException::unauthenticated();
        }

        if (is_string($current) && $current !== '' && !password_verify($current, $hash)) {
            $errors['current_password'] = 'La contraseña actual no es correcta.';
        }

        if (is_string($new) && $new !== '' && password_verify($new, $hash)) {
            $errors['new_password'] = 'La nueva contraseña debe ser distinta de la actual.';
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $now = gmdate('Y-m-d H:i:s');
        $newHash = password_hash((string) $new, PASSWORD_DEFAULT);

        $this->db->transaction(function () use ($userId, $newHash, $now): void {
            $this->users->updatePassword($userId, $newHash, false, $now);
            // Una clave temporal es de un solo uso: se consume al cambiarla.
            $this->passwordResets->markAllUsed($userId, $now);
        });

        $revoked = $this->tokens->revokeAll($userId, 'password_change');

        $this->audit->log(
            AuditLogger::PASSWORD_CHANGE,
            'user',
            $userId,
            ['must_change_password' => (int) $user['must_change_password'] === 1],
            ['must_change_password' => false, 'tokens_revoked' => $revoked],
            $userId,
        );

        // Cambio de credenciales = cambio de privilegios: se rota el ID de sesión.
        $this->session->regenerate();

        $fresh = $this->users->findForAuth($userId);

        if ($fresh !== null) {
            $this->context->setUser($fresh, $this->context->authMethod() ?? AuthContext::METHOD_SESSION);
        }

        $payload = $this->presenter->sessionPayload($fresh ?? $user);
        $payload['tokens_revoked'] = $revoked;

        return Response::data($payload);
    }

    /**
     * `POST /api/v1/me/avatar` (multipart/form-data, campo `avatar`).
     */
    public function uploadAvatar(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_AVATAR);
        $user = $this->authorizer->requireUser();
        $userId = (int) $user['id'];

        $file = $_FILES['avatar'] ?? null;

        if (!is_array($file)) {
            foreach ($_FILES as $candidate) {
                if (is_array($candidate)) {
                    $file = $candidate;
                    break;
                }
            }
        }

        if (!is_array($file)) {
            throw ApiException::validation(['avatar' => 'Adjunta un archivo de imagen en el campo "avatar".']);
        }

        $relative = $this->avatars->store($file);
        $previous = $user['avatar_path'] ?? null;

        $this->users->setAvatarPath($userId, $relative);
        $this->avatars->delete($previous);

        $this->audit->log(
            AuditLogger::AVATAR_UPDATE,
            'user',
            $userId,
            ['avatar_path' => is_string($previous) ? $previous : null],
            ['avatar_path' => $relative],
            $userId,
        );

        $fresh = $this->users->findForAuth($userId) ?? $user;
        $this->context->setUser($fresh, $this->context->authMethod() ?? AuthContext::METHOD_SESSION);

        return Response::data($this->presenter->sessionPayload($fresh));
    }

    /**
     * `GET /api/v1/me/avatar` — sirve solo el avatar del usuario autenticado.
     *
     * El contrato del núcleo (`Response`) solo serializa JSON, así que el binario
     * se emite aquí con los encabezados correctos. `Cache-Control: private` evita
     * que un proxy comparta la imagen entre usuarios.
     */
    public function avatar(Request $request): Response
    {
        $user = $this->authorizer->requireUser();
        $relative = $user['avatar_path'] ?? null;
        $absolute = $this->avatars->absolutePath($relative);

        if ($absolute === null) {
            throw ApiException::notFound('No tienes avatar.');
        }

        $size = filesize($absolute);

        header('Content-Type: ' . $this->avatars->contentType((string) $relative));
        header('Content-Length: ' . (string) ($size === false ? 0 : $size));
        header('Cache-Control: private, max-age=60, no-transform');
        header('Content-Disposition: inline; filename="' . basename($absolute) . '"');

        readfile($absolute);

        exit;
    }

    /**
     * @param array<string, mixed> $prefs
     */
    private static function validNotificationPrefs(array $prefs): bool
    {
        if (count($prefs) > 20) {
            return false;
        }

        foreach ($prefs as $key => $value) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 40) {
                return false;
            }

            if (!is_bool($value)) {
                return false;
            }
        }

        return true;
    }
}
