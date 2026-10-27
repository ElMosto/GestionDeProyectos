<?php

declare(strict_types=1);

use App\Auth\AuditLogger;
use App\Auth\Authenticator;
use App\Auth\Csrf;
use App\Core\ApiException;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Cadena de middleware (una sola capa, en este orden):
 *
 *   1. resolver identidad: Bearer primero; si no, cookie + sesión;
 *   2. exigir cuenta activa (se recarga en cada petición, no solo al login);
 *   3. bloquear la navegación si `must_change_password = 1`;
 *   4. validar CSRF en toda operación mutable sin Bearer (incluido login);
 *   5. pasar al router.
 *
 * Deny by default: cualquier ruta que no esté en la lista pública exige identidad.
 */
return static function (Router $router, Container $container): void {
    /** Rutas que no exigen sesión ni token. */
    $publicRoutes = [
        'GET /',
        'GET /api/v1/health',
        'GET /api/v1/auth/csrf',
        'POST /api/v1/auth/login',
    ];

    /** Único recorrido permitido con contraseña temporal sin cambiar (§6). */
    $passwordChangeRoutes = [
        'GET /api/v1/auth/me',
        'POST /api/v1/auth/logout',
        'POST /api/v1/me/password',
        'GET /api/v1/auth/csrf',
        'GET /api/v1/me/avatar',
    ];

    $router->middleware(static function (Request $request, callable $next) use (
        $container,
        $publicRoutes,
        $passwordChangeRoutes
    ): Response {
        /** @var Authenticator $auth */
        $auth = $container->get(Authenticator::class);
        /** @var AuditLogger $audit */
        $audit = $container->get(AuditLogger::class);
        /** @var Csrf $csrf */
        $csrf = $container->get(Csrf::class);

        // Correlación de la bitácora con esta petición.
        $audit->bindRequest($request->requestId, $request->ip);

        $auth->resolve($request);

        $route = $request->method . ' ' . $request->path;

        if (!$auth->isAuthenticated()) {
            if (in_array($route, $publicRoutes, true)) {
                // El login necesita sesión para el token CSRF; se arranca al validarlo.
                if ($request->isMutating() && $request->bearerToken() === null) {
                    $csrf->validate($request);
                }

                return $next($request);
            }

            throw ApiException::unauthenticated();
        }

        // Las rutas públicas siguen accesibles con contraseña temporal pendiente: quien
        // está bloqueado debe poder volver a entrar, pedir CSRF o cerrar sesión.
        if (
            $auth->mustChangePassword()
            && !in_array($route, $passwordChangeRoutes, true)
            && !in_array($route, $publicRoutes, true)
        ) {
            throw new ApiException(
                403,
                'PASSWORD_CHANGE_REQUIRED',
                'Debes cambiar tu contraseña antes de continuar.',
            );
        }

        // Con Bearer no hay CSRF: no hay cookie que un tercero pueda reutilizar (D-08).
        if ($request->isMutating() && $request->bearerToken() === null) {
            $csrf->validate($request);
        }

        return $next($request);
    });
};
