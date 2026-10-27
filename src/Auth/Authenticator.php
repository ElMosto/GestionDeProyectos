<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\UserRepository;
use App\Core\Request;

/**
 * Resuelve la identidad de cada petición (§5, §16).
 *
 * Orden: `Authorization: Bearer` primero; si no, cookie + sesión. En ambos casos
 * se recarga el usuario desde la base **en cada petición**, de modo que una cuenta
 * desactivada pierde el acceso al instante, incluidas las sesiones ya abiertas.
 */
final class Authenticator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthContext $context,
        private readonly SessionManager $session,
        private readonly TokenAuthenticator $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    public function resolve(Request $request): void
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $result = $this->tokens->authenticate($bearer);

            if ($result !== null) {
                $this->context->setUser($result['user'], AuthContext::METHOD_TOKEN, $result['token_id']);
            }

            // Con un Bearer inválido no se recurre a la cookie: evita confundir
            // dos mecanismos y deja la petición como anónima (401 si es privada).
            return;
        }

        if (!$this->session->start(false)) {
            return;
        }

        $userId = $this->session->userId();

        if ($userId === null) {
            return;
        }

        if (!$this->session->withinTimeouts()) {
            $this->session->logout();
            $this->context->clear();

            return;
        }

        $user = $this->users->findForAuth($userId);

        if ($user === null) {
            $this->session->logout();
            $this->context->clear();

            return;
        }

        if ((int) $user['active'] !== 1) {
            // Cuenta desactivada con sesión abierta: se corta y se deja rastro.
            $this->audit->log(
                AuditLogger::ACCOUNT_INACTIVE_ACCESS,
                'user',
                $userId,
                null,
                ['reason' => 'inactive_session'],
                $userId,
            );
            $this->session->logout();
            $this->context->clear();

            return;
        }

        $roleId = (int) $user['role_id'];

        if ($this->session->roleId() !== $roleId) {
            // Cambio de privilegios: se rota el ID de sesión (§16).
            $this->session->refreshAuthorization($roleId);
        }

        $this->context->setUser($user, AuthContext::METHOD_SESSION);
    }

    public function isAuthenticated(): bool
    {
        return $this->context->isAuthenticated();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->context->user();
    }

    public function id(): ?int
    {
        return $this->context->id();
    }

    public function roleCode(): ?string
    {
        return $this->context->roleCode();
    }

    public function authMethod(): ?string
    {
        return $this->context->authMethod();
    }

    public function mustChangePassword(): bool
    {
        return $this->context->mustChangePassword();
    }

    public function context(): AuthContext
    {
        return $this->context;
    }
}
