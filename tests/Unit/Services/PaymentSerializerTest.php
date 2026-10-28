<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\PaymentSerializer;
use PHPUnit\Framework\TestCase;

/** §15, D-09: IDs/versiones strings y nunca claves privadas en JSON móvil. */
final class PaymentSerializerTest extends TestCase
{
    public function test_D_payment_contract_preserves_exact_money_and_string_versions(): void
    {
        $payment = PaymentSerializer::payment([
            'id' => 12, 'version' => 3, 'sale_id' => 42, 'project_id' => null,
            'amount' => '9007199254.99', 'registered_by' => 8, 'voided_by' => null,
            'effective_at' => '2026-10-28 12:30:00',
            'idempotency_key' => 'never-return-this-key',
        ]);
        $this->assertSame('12', $payment['id']);
        $this->assertSame('3', $payment['version']);
        $this->assertSame('42', $payment['sale_id']);
        $this->assertSame('8', $payment['registered_by']);
        $this->assertNull($payment['project_id']);
        $this->assertNull($payment['voided_by']);
        $this->assertSame('9007199254.99', $payment['amount']);
        $this->assertSame('2026-10-28T12:30:00Z', $payment['effective_at']);
        $this->assertArrayNotHasKey('idempotency_key', $payment);
    }

    public function test_D_refund_uses_the_same_safe_string_contract(): void
    {
        $refund = PaymentSerializer::refund([
            'id' => 13, 'version' => 1, 'payment_id' => 12, 'registered_by' => 8,
            'amount' => '0.10', 'effective_at' => '2026-10-28 12:30:00',
            'idempotency_key' => 'private-ledger-key', 'token' => 'private-token',
        ]);
        foreach (['id' => '13', 'version' => '1', 'payment_id' => '12', 'registered_by' => '8'] as $key => $value) {
            $this->assertSame($value, $refund[$key]);
        }
        $this->assertSame('0.10', $refund['amount']);
        $this->assertArrayNotHasKey('idempotency_key', $refund);
        $this->assertArrayNotHasKey('token', $refund);
    }
}
