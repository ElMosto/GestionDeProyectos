<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use RuntimeException;

/**
 * Sesión PHP en servidor (§16).
 *
 * - Cookie `HttpOnly`, `Secure` según configuración, `SameSite=Lax`, `path=/`.
 * - `session.use_strict_mode` y `use_only_cookies`: un ID inventado nunca se adopta.
 * - Regeneración de ID al autenticar, al cambiar privilegios y al cambiar contraseña.
 * - Caducidad por inactividad y absoluta, guardadas dentro de la propia sesión.
 * - Nunca se arranca una sesión para peticiones con `Authorization: Bearer` (D-08):
 *   el móvil no necesita cookies.
 */
final class SessionManager
{
    private const KEY_USER = '_auth_user_id';
    private const KEY_ROLE = '_auth_role_id';
    private const KEY_STARTED = '_auth_started_at';
    private const KEY_SEEN = '_auth_last_seen';

    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return (string) $this->config->get('session.name', 'gestion_session');
    }

    /**
     * @param bool $create Si es false, solo se inicia cuando ya existe cookie de sesión
     */
    public function start(bool $create = true): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return true;
        }

        if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
            return false;
        }

        $cookieName = $this->name();
        $hasCookie = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] !== '';

        if (!$hasCookie && !$create) {
            return false;
        }

        $this->configure($cookieName);

        if (session_start() !== true) {
            return false;
        }

        $this->started = true;

        if (!isset($_SESSION[self::KEY_STARTED])) {
            $_SESSION[self::KEY_STARTED] = time();
        }

        if (!isset($_SESSION[self::KEY_SEEN])) {
            $_SESSION[self::KEY_SEEN] = time();
        }

        return true;
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function userId(): ?int
    {
        $value = $_SESSION[self::KEY_USER] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    public function roleId(): ?int
    {
        $value = $_SESSION[self::KEY_ROLE] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /**
     * Fija la identidad tras verificar credenciales. Regenera el ID para impedir
     * fijación de sesión y reinicia el contador absoluto.
     */
    public function login(int $userId, int $roleId): void
    {
        if (!$this->start(true)) {
            throw new RuntimeException('No se pudo iniciar la sesión del usuario.');
        }

        session_regenerate_id(true);

        $_SESSION[self::KEY_USER] = $userId;
        $_SESSION[self::KEY_ROLE] = $roleId;
        $_SESSION[self::KEY_STARTED] = time();
        $_SESSION[self::KEY_SEEN] = time();
    }

    /**
     * Cambio de privilegios (rol distinto al de la sesión): se rota el ID y se
     * actualiza la instantánea del rol.
     */
    public function refreshAuthorization(int $roleId): void
    {
        if (!$this->isActive()) {
            return;
        }

        session_regenerate_id(true);

        $_SESSION[self::KEY_ROLE] = $roleId;
        $_SESSION[self::KEY_SEEN] = time();
    }

    /**
     * Rotación sin tocar la identidad (cambio de contraseña, elevación puntual).
     */
    public function regenerate(): void
    {
        if (!$this->isActive()) {
            return;
        }

        session_regenerate_id(true);

        $_SESSION[self::KEY_SEEN] = time();
    }

    /**
     * Comprueba inactividad y caducidad absoluta. Si la sesión ya no vale, la
     * destruye y devuelve false: el llamador la trata como anónima.
     */
    public function withinTimeouts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $now = time();
        $startedAt = (int) ($_SESSION[self::KEY_STARTED] ?? $now);
        $lastSeen = (int) ($_SESSION[self::KEY_SEEN] ?? $now);

        $idle = (int) $this->config->get('session.idle_timeout', 1800);
        $absolute = (int) $this->config->get('session.absolute_timeout', 43200);

        if ($idle > 0 && $now - $lastSeen > $idle) {
            return false;
        }

        if ($absolute > 0 && $now - $startedAt > $absolute) {
            return false;
        }

        $_SESSION[self::KEY_SEEN] = $now;

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Destruye la sesión y caduca la cookie en el cliente.
     */
    public function logout(): void
    {
        if (!$this->isActive()) {
            $this->expireCookie();

            return;
        }

        $params = session_get_cookie_params();

        $_SESSION = [];

        if (ini_get('session.use_cookies') !== '0') {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] !== '' ? $params['path'] : '/',
                'domain' => (string) $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => (string) $this->config->get('session.cookie_samesite', 'Lax'),
            ]);
        }

        session_destroy();
        $this->started = false;
    }

    private function expireCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->name(), '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => (bool) $this->config->get('session.cookie_secure', true),
            'httponly' => true,
            'samesite' => (string) $this->config->get('session.cookie_samesite', 'Lax'),
        ]);
    }

    private function configure(string $cookieName): void
    {
        $secure = (bool) $this->config->get('session.cookie_secure', true);
        $sameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        $absolute = max(1800, (int) $this->config->get('session.absolute_timeout', 43200));

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $absolute);

        session_name($cookieName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
}
