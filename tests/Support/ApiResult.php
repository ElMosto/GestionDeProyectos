<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\ApiException;
use App\Core\Response;

/**
 * Resultado normalizado de una petición hecha en proceso contra el router real.
 *
 * Reproduce la misma forma que `Application::run()` y `Response`: éxito con
 * `data`/`meta`, error con `error.code`/`error.message`/`error.fields`.
 */
final class ApiResult
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromResponse(Response $response): self
    {
        $decoded = json_decode($response->body, true);

        return new self(
            $response->status,
            is_array($decoded) ? $decoded : [],
            $response->headers,
            $response->body,
        );
    }

    public static function fromException(ApiException $exception): self
    {
        $error = [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ];

        if ($exception->fields !== []) {
            $error['fields'] = $exception->fields;
        }

        return new self(
            $exception->status,
            ['error' => $error],
            $exception->headers,
            (string) json_encode(['error' => $error]),
        );
    }

    public function data(): mixed
    {
        return $this->payload['data'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->payload['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    public function errorCode(): ?string
    {
        $code = $this->payload['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    public function errorMessage(): ?string
    {
        $message = $this->payload['error']['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        $fields = $this->payload['error']['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $data = $this->data();

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    public function contentType(): ?string
    {
        return $this->headers['Content-Type'] ?? null;
    }

    public function json(): string
    {
        return (string) json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
