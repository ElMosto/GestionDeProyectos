<?php

declare(strict_types=1);

namespace App\Tests\Support;

use CurlHandle;
use RuntimeException;

/** Cliente HTTP con cookies en memoria, nunca credenciales persistidas. */
final class HttpClient
{
    private CurlHandle $curl;

    public function __construct(private readonly string $baseUrl)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_TIMEOUT => 10,
        ]);
    }

    public function call(string $method, string $path, ?array $body = null, array $headers = [], bool $multipart = false): ApiResult
    {
        $responseHeaders = [];
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $multipart ? $body : ($body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)),
            CURLOPT_HTTPHEADER => array_merge(
                $multipart ? ['Accept: application/json'] : ['Accept: application/json', 'Content-Type: application/json'],
                $headers,
            ),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($this->curl);
        if ($raw === false) {
            throw new RuntimeException(curl_error($this->curl));
        }
        return new ApiResult(
            (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE),
            json_decode($raw, true) ?: [], $responseHeaders, $raw,
        );
    }

    public function csrf(): string
    {
        return $this->call('GET', '/api/v1/auth/csrf')->payload['data']['token'];
    }
}
