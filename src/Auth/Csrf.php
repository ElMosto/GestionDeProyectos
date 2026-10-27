<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\ApiException;
use App\Core\Request;

/**
 * Token CSRF por sesión (§16). Se exige en toda operación mutable autenticada
 * por cookie, **incluido el login**; con `Authorization: Bearer` no aplica (D-08).
 *
 * Se compara con `hash_equals` para no filtrar el token por tiempo de respuesta.
 */
final class Csrf
{
    public const HEADER = 'x-csrf-token';
    public const SESSION_KEY = '_csrf_token';

    /** 32 bytes => 64 caracteres hexadecimales */
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly SessionManager $session)
    {
    }

    /**
     * Devuelve el token de la sesión actual y arranca la sesión si aún no existe.
     */
    public function token(): string
    {
        if (!$this->session->start(true)) {
            throw new ApiException(503, 'SESSION_UNAVAILABLE', 'No se pudo iniciar la sesión. Revisa la configuración de PHP.');
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || strlen($token) !== self::TOKEN_BYTES * 2) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * Valida `X-CSRF-Token` (o `_csrf` en el cuerpo, para formularios sin JS).
     */
    public function validate(Request $request): void
    {
        // §16: verificar el origen cuando el navegador lo declara. Sin Origin
        // siguen siendo obligatorios cookie + token (curl y clientes no web).
        $origin = $request->header('origin');
        $host = $request->header('host');
        if (
            $request->header('sec-fetch-site') === 'cross-site'
            || ($origin !== null && ($host === null
                || strtolower($origin) !== strtolower($request->scheme . '://' . $host)))
        ) {
            throw new ApiException(403, 'CSRF_INVALID', 'El origen de la petición no está permitido.');
        }

        $expected = $this->session->isActive() ? ($_SESSION[self::SESSION_KEY] ?? null) : null;
        $provided = $request->header(self::HEADER);

        if ($provided === null || $provided === '') {
            $fallback = $request->input('_csrf');
            $provided = is_string($fallback) ? $fallback : null;
        }

        if (!is_string($expected) || $expected === '' || $provided === null || !hash_equals($expected, $provided)) {
            throw new ApiException(
                403,
                'CSRF_INVALID',
                'El token CSRF no es válido o ha caducado. Recarga la página e inténtalo de nuevo.'
            );
        }
    }
}
