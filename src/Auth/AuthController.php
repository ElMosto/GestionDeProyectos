<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\UserRepository;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

/**
 * Sesión web: login, logout, `me` y entrega del token CSRF (§15, §16).
 *
 * Los errores de credenciales son siempre genéricos: no se revela si el correo
 * existe, si la contraseña es incorrecta o si la cuenta está desactivada.
 */
final class AuthController
{
    /**
     * Hash de relleno para que `password_verify` consuma el mismo tiempo cuando el
     * correo no existe (evita enumeración de cuentas por temporización).
     */
    private const DUMMY_HASH = '$2y$12$SCNxw3.snJb38kNjv3GQAe.70ir31C7V3GE6BUnvFl2ZiLxGFfJuO';

    private const GENERIC_CREDENTIALS_MESSAGE = 'El correo o la contraseña no son correctos.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly AuthContext $context,
        private readonly UserPresenter $presenter,
        private readonly TokenService $tokens,
    ) {
    }

    /**
     * `GET /api/v1/auth/csrf` — arranca sesión si no existe y devuelve el token.
     */
    public function csrf(Request $request): Response
    {
        return Response::data(['token' => $this->csrf->token()]);
    }

    /**
     * `POST /api/v1/auth/login`
     */
    public function login(Request $request): Response
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $errors = [];

        if (!is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Escribe un correo válido.';
        }

        if (!is_string($password) || $password === '') {
            $errors['password'] = 'Escribe tu contraseña.';
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $email = mb_strtolower(trim((string) $email));
        $password = (string) $password;
        $userAgent = $request->header('user-agent');

        // 429 antes de tocar la base: el bloqueo no depende de que la cuenta exista.
        try {
            $this->throttle->assertAllowed($email, $request->ip);
        } catch (ApiException $e) {
            $this->throttle->record($email, $request->ip, $userAgent, false);
            $this->audit->log(AuditLogger::LOGIN_BLOCKED, 'user');

            throw $e;
        }

        $user = $this->users->findWithPasswordByEmail($email);
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);

        if (!is_array($user) || !$passwordOk) {
            $this->throttle->record($email, $request->ip, $userAgent, false);
            $this->audit->log(AuditLogger::LOGIN_FAILED, 'user');

            throw new ApiException(401, 'INVALID_CREDENTIALS', self::GENERIC_CREDENTIALS_MESSAGE);
        }

        if ((int) $user['active'] !== 1) {
            $this->throttle->record($email, $request->ip, $userAgent, false);
            $this->audit->log(
                AuditLogger::LOGIN_INACTIVE_ACCOUNT,
                'user',
                (int) $user['id'],
                null,
                ['reason' => 'inactive_account'],
                (int) $user['id'],
            );

            // Mismo mensaje y mismo código que una contraseña incorrecta.
            throw new ApiException(401, 'INVALID_CREDENTIALS', self::GENERIC_CREDENTIALS_MESSAGE);
        }

        unset($user['password_hash']);

        $this->throttle->record($email, $request->ip, $userAgent, true);
        $this->users->touchLastLogin((int) $user['id'], gmdate('Y-m-d H:i:s'));
        $user['last_login_at'] = gmdate('Y-m-d H:i:s');

        $this->session->login((int) $user['id'], (int) $user['role_id']);
        $this->context->setUser($user, AuthContext::METHOD_SESSION);

        $this->audit->log(AuditLogger::LOGIN_SUCCESS, 'user', (int) $user['id'], null, null, (int) $user['id']);

        return Response::data($this->presenter->sessionPayload($user));
    }

    /**
     * `POST /api/v1/auth/logout` — destruye la sesión y caduca la cookie.
     * Con Bearer revoca además el token usado, para que el móvil pueda cerrar sesión.
     */
    public function logout(Request $request): Response
    {
        $user = $this->context->user();

        if ($user !== null) {
            $this->audit->log(AuditLogger::LOGOUT, 'user', (int) $user['id'], null, null, (int) $user['id']);

            if ($this->context->authMethod() === AuthContext::METHOD_TOKEN) {
                $tokenId = $this->context->tokenId();

                if ($tokenId !== null) {
                    $this->tokens->revoke((int) $user['id'], $tokenId);
                }
            }
        }

        $this->session->logout();
        $this->context->clear();

        return Response::noContent();
    }

    /**
     * `GET /api/v1/auth/me`
     */
    public function me(Request $request): Response
    {
        $user = $this->context->user();

        if ($user === null) {
            throw ApiException::unauthenticated();
        }

        return Response::data($this->presenter->sessionPayload($user));
    }
}
