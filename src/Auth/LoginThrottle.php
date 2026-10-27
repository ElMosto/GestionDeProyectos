<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\LoginAttemptRepository;
use App\Core\ApiException;
use App\Core\Config;

/**
 * Rate limit de login por cuenta **y** por IP (§16).
 *
 * Se cuentan fallos dentro de la ventana configurada. Al alcanzar el máximo, el
 * siguiente intento devuelve `429 RATE_LIMITED` sin consultar la contraseña, de
 * modo que el atacante no obtiene información sobre si la cuenta existe.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly Config $config,
    ) {
    }

    /**
     * @throws ApiException 429 si la cuenta o la IP están bloqueadas
     */
    public function assertAllowed(string $email, string $ip): void
    {
        $max = $this->maxAttempts();
        $window = $this->window();

        if (
            $this->attempts->countFailuresForEmail($email, $window) >= $max
            || $this->attempts->countFailuresForIp($ip, $window) >= $max
        ) {
            throw new ApiException(
                429,
                'RATE_LIMITED',
                'Demasiados intentos de acceso. Espera unos minutos antes de volver a intentarlo.',
                [],
                ['Retry-After' => (string) $window],
            );
        }
    }

    public function record(string $email, string $ip, ?string $userAgent, bool $successful): void
    {
        $this->attempts->record($email, $ip, $userAgent, $successful);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('security.login_max_attempts', 5));
    }

    public function window(): int
    {
        return max(60, (int) $this->config->get('security.login_window', 900));
    }
}
