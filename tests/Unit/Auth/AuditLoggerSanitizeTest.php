<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuditLogger;
use App\Auth\AuthContext;
use App\Auth\Repositories\AuditLogRepository;
use App\Core\Database;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

/**
 * Saneado de la bitácora (§16).
 *
 * No abre conexión: solo se ejerce `sanitize()`, que es puro. El repositorio se
 * construye con una conexión perezosa que nunca se usa.
 */
final class AuditLoggerSanitizeTest extends TestCase
{
    private function logger(): AuditLogger
    {
        return new AuditLogger(
            new AuditLogRepository(new Database(TestConfig::config())),
            new AuthContext(),
        );
    }

    public function test_A_secrets_are_replaced_before_persisting(): void
    {
        // §16 — nunca se guardan contraseñas, hashes, tokens ni datos de tarjeta.
        $clean = $this->logger()->sanitize([
            'password' => 'ClaveEnClaro123!',
            'new_password_confirmation' => 'ClaveEnClaro123!',
            'password_hash' => '$2y$12$abcdefghijklmnopqrstuv',
            'token' => 'token-en-claro',
            'token_hash' => str_repeat('a', 64),
            'csrf_token' => 'csrf',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'authorization' => 'Bearer xyz',
            'email' => 'visible@test.local',
        ]);

        foreach ([
            'password',
            'new_password_confirmation',
            'password_hash',
            'token',
            'token_hash',
            'csrf_token',
            'card_number',
            'cvv',
            'authorization',
        ] as $field) {
            $this->assertSame('[omitido]', $clean[$field], 'Debe omitirse ' . $field);
        }

        // Los datos no sensibles se conservan para que la auditoría sea útil.
        $this->assertSame('visible@test.local', $clean['email']);
    }

    public function test_A_flags_are_kept_because_they_are_traceability(): void
    {
        // §16 — `must_change_password` y `tokens_revoked` son indicadores, no credenciales.
        $clean = $this->logger()->sanitize([
            'must_change_password' => true,
            'tokens_revoked' => 2,
            'active' => false,
        ]);

        $this->assertTrue($clean['must_change_password']);
        $this->assertSame(2, $clean['tokens_revoked']);
        $this->assertFalse($clean['active']);
    }

    public function test_A_nested_structures_are_sanitized_recursively(): void
    {
        // §16 — un secreto anidado tampoco se filtra.
        $clean = $this->logger()->sanitize([
            'user' => [
                'email' => 'nested@test.local',
                'password' => 'Anidada123!',
                'tokens' => [
                    ['token' => 'abc', 'name' => 'móvil'],
                ],
            ],
        ]);

        $this->assertSame('nested@test.local', $clean['user']['email']);
        $this->assertSame('[omitido]', $clean['user']['password']);
        $this->assertSame('[omitido]', $clean['user']['tokens'][0]['token']);
        $this->assertSame('móvil', $clean['user']['tokens'][0]['name']);
    }

    public function test_A_very_long_strings_and_deep_structures_are_truncated(): void
    {
        // §16 — la bitácora no se convierte en un volcado del payload.
        $clean = $this->logger()->sanitize([
            'notes' => str_repeat('x', 5000),
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => 'demasiado']]]]],
        ]);

        $this->assertSame(2000, strlen($clean['notes']));
        $this->assertSame('[omitido]', $clean['deep']['a']['b']['c']['d']);
    }

    public function test_A_scalar_and_object_values_never_leak_objects(): void
    {
        // §16 — un objeto no serializable se omite en lugar de romper la auditoría.
        $clean = $this->logger()->sanitize([
            'when' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            'ok' => 5,
        ]);

        $this->assertSame('[omitido]', $clean['when']);
        $this->assertSame(5, $clean['ok']);
    }
}
