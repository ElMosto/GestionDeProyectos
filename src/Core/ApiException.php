<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Error controlado que se traduce a una respuesta JSON.
 * `code` es estable y en inglés; `message` es para el usuario final, en español.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields, string $message = 'Revisa los datos enviados.'): self
    {
        return new self(422, 'VALIDATION_FAILED', $message, $fields);
    }

    public static function unauthenticated(string $message = 'Necesitas iniciar sesión.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = 'No tienes permiso para esta acción.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $message = 'No encontrado.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
