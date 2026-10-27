<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers Encabezados normalizados a minúsculas
     * @param array<string, mixed>  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body,
        public readonly string $ip,
        public readonly string $requestId,
        public readonly array $routeParams = [],
        public readonly string $scheme = 'https',
    ) {
    }

    public static function fromGlobals(string $requestId): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = self::resolvePath();

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            headers: self::collectHeaders(),
            body: self::parseBody(),
            ip: self::resolveIp(),
            requestId: $requestId,
            scheme: isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http',
        );
    }

    public function withRouteParams(array $params): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->headers,
            $this->body,
            $this->ip,
            $this->requestId,
            $params,
            $this->scheme,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function isMutating(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', trim($header), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function param(string $name): string
    {
        return (string) ($this->routeParams[$name] ?? '');
    }

    private static function resolvePath(): string
    {
        if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
            $path = '/' . ltrim($_GET['r'], '/');
        } else {
            $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $path = is_string($path) ? $path : '/';
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        // Algunos Apache no exponen Authorization; también se acepta el encabezado redirigido.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseBody(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (!str_contains($contentType, 'application/json')) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'El cuerpo de la petición no es JSON válido.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function resolveIp(): string
    {
        // §16: no confiar en X-Forwarded-For enviado por el cliente: permitiría
        // eludir el rate limit por IP. Un proxy futuro exige lista explícita de
        // proxies de confianza; mientras no exista, solo vale el peer de Apache.
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }

        return '0.0.0.0';
    }
}
