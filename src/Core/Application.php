<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Núcleo mínimo del monolito: arranque, enrutamiento y respuesta JSON.
 * Las rutas se irán registrando aquí (o en src/Core/routes.php) a medida que avancen las fases.
 */
final class Application
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly string $requestId,
    ) {
    }

    public function run(): void
    {
        try {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $path = $this->resolvePath();
            [$status, $payload] = $this->dispatch($method, $path);

            http_response_code($status);
            $this->sendJson($payload);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[%s] %s: %s en %s:%d',
                $this->requestId,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            http_response_code(500);
            $this->sendJson([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Ocurrió un error inesperado.',
                ],
                'request_id' => $this->requestId,
            ]);
        }
    }

    /**
     * @return array{0:int, 1:array<string, mixed>}
     */
    private function dispatch(string $method, string $path): array
    {
        if ($method === 'GET' && ($path === '/' || $path === '/api/v1')) {
            return [200, [
                'data' => [
                    'app' => $this->config['app']['name'] ?? 'Gestión',
                    'version' => $this->config['app']['version'] ?? '0.0.0',
                    'status' => 'ok',
                ],
            ]];
        }

        if ($method === 'GET' && $path === '/api/v1/health') {
            return [200, [
                'data' => [
                    'status' => 'ok',
                    'time' => gmdate('c'),
                ],
                'meta' => ['request_id' => $this->requestId],
            ]];
        }

        return [404, [
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'Ruta no encontrada.',
            ],
            'request_id' => $this->requestId,
        ]];
    }

    /**
     * Soporta mod_rewrite y, en su defecto, index.php?r=/api/v1/...
     */
    private function resolvePath(): string
    {
        if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
            $path = '/' . ltrim($_GET['r'], '/');
        } else {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $path = is_string($path) ? $path : '/';
        }

        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
