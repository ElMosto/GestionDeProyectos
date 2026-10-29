<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public static function data(mixed $data, array $meta = [], int $status = 200): self
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self($status, self::encode($payload), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function created(mixed $data, array $meta = []): self
    {
        return self::data($data, $meta, 201);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function download(string $body, string $filename, string $contentType): self
    {
        if (preg_match('/\A[A-Za-z0-9_.-]+\z/', $filename) !== 1 || preg_match('/[\r\n]/', $contentType) === 1) {
            throw new \InvalidArgumentException('Cabeceras de descarga no válidas.');
        }
        return new self(200, $body, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function error(
        int $status,
        string $code,
        string $message,
        array $fields = [],
        string $requestId = '',
    ): self {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $payload = ['error' => $error];

        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        return new self($status, self::encode($payload), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
