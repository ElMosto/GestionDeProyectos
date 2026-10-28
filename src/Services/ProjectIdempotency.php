<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\ProjectIdempotencyRepository;

final class ProjectIdempotency
{
    public function __construct(
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectIdempotencyRepository $requests,
    ) {
    }

    public function run(
        string $operation,
        ?string $key,
        array $input,
        callable $work,
        int $status = 200,
        ?callable $authorizeResponse = null,
    ): array {
        if ($key === null || preg_match('/\A[!-~]{1,100}\z/', $key) !== 1) {
            throw ApiException::validation(['idempotency_key' => 'Envía Idempotency-Key de1 a100 caracteres ASCII sin espacios.']);
        }
        $hash = hash('sha256', json_encode(self::canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->db->transaction(function () use ($operation, $key, $hash, $work, $status, $authorizeResponse): array {
            $row = $this->requests->acquire((int) $this->authorizer->id(), $operation, $key, $hash);
            if (!hash_equals($row['request_hash'], $hash)) {
                throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSED', 'La clave ya se utilizó con otro contenido.');
            }
            if ($row['response_json'] !== null) {
                $response = json_decode($row['response_json'], true, 64, JSON_THROW_ON_ERROR);
                // Una clave recordada nunca sustituye la autorización actual del
                // objeto. El callback toma el mismo lock que la escritura.
                if ($authorizeResponse !== null) {
                    $authorizeResponse($response);
                }
                return $response;
            }
            $response = $work();
            if ($authorizeResponse !== null) {
                $authorizeResponse($response);
            }
            $this->requests->complete((int) $row['id'], $response, $status);
            return $response;
        });
    }

    private static function canonical(array $input): array
    {
        if (!array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        return $input;
    }
}
