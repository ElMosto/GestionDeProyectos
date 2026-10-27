<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\ApiException;
use App\Core\Application;
use App\Core\Container;
use App\Core\Request;
use App\Core\Router;

/**
 * Ejecuta peticiones contra la aplicación real **en proceso**: mismo contenedor,
 * mismo middleware, mismas rutas y mismos controladores que `public/index.php`.
 *
 * Cada petición construye un contenedor nuevo, igual que una petición HTTP real:
 * así no se filtra identidad (`AuthContext`) ni estado de sesión entre casos.
 *
 * Lo que NO cubre: transporte de cookies y cabeceras HTTP. Eso se verifica en
 * `tests/Http` contra `php -S` y en `tests/http/smoke.ps1`.
 */
final class InProcessApi
{
    /**
     * Contenedor nuevo con todos los servicios de la aplicación (fase 1).
     */
    public static function container(): Container
    {
        $application = new Application(APP_ROOT, TestConfig::values(), self::requestId());

        return $application->container();
    }

    /**
     * @return array{0: Container, 1: Router}
     */
    private static function boot(): array
    {
        $container = self::container();
        $router = new Router($container);

        /** @var callable(Router, Container):void $middleware */
        $middleware = require APP_ROOT . '/src/Core/middleware.php';
        $middleware($router, $container);

        /** @var callable(Router, Container):void $routes */
        $routes = require APP_ROOT . '/src/Core/routes.php';
        $routes($router, $container);

        return [$container, $router];
    }

    public static function requestId(): string
    {
        return 'test-' . bin2hex(random_bytes(6));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     */
    public static function call(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $bearer = null,
        array $query = [],
        string $ip = '127.0.0.1',
    ): ApiResult {
        [, $router] = self::boot();

        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        if ($bearer !== null) {
            $normalized['authorization'] = 'Bearer ' . $bearer;
        }

        $request = new Request(
            method: strtoupper($method),
            path: self::normalizePath($path),
            query: $query,
            headers: $normalized,
            body: $body,
            ip: $ip,
            requestId: self::requestId(),
        );

        try {
            return ApiResult::fromResponse($router->handle($request));
        } catch (ApiException $exception) {
            return ApiResult::fromException($exception);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function get(string $path, array $query = [], ?string $bearer = null): ApiResult
    {
        return self::call('GET', $path, [], [], $bearer, $query);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function post(string $path, array $body = [], ?string $bearer = null, array $headers = []): ApiResult
    {
        return self::call('POST', $path, $body, $headers, $bearer);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function patch(string $path, array $body = [], ?string $bearer = null, array $headers = []): ApiResult
    {
        return self::call('PATCH', $path, $body, $headers, $bearer);
    }

    public static function delete(string $path, ?string $bearer = null): ApiResult
    {
        return self::call('DELETE', $path, [], [], $bearer);
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
