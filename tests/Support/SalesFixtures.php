<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Policies\Role;
use PHPUnit\Framework\Assert;

/** §19.C/D — identities by fixture; all sales and money effects use the real API. */
final class SalesFixtures
{
    public function __construct(private readonly Fixtures $fixtures)
    {
    }

    public function actor(string $role = Role::ADMINISTRADOR): array
    {
        return (new ProjectFixtures($this->fixtures))->actor($role);
    }

    public static function key(): array
    {
        return ['Idempotency-Key' => 'financial-test-' . Fixtures::uniqueSuffix()];
    }

    public static function ok(ApiResult $result, int $status = 200): array
    {
        Assert::assertSame($status, $result->status, $result->json());
        Assert::assertIsArray($result->data());
        return $result->data();
    }

    public function product(string $token, string $price = '50.00', array $overrides = []): array
    {
        return self::ok(InProcessApi::post('/api/v1/products', array_replace([
            'name' => 'Producto ' . Fixtures::uniqueSuffix(), 'price' => $price,
            'type' => 'PRODUCTO', 'unit' => 'pieza',
        ], $overrides), $token), 201);
    }

    public function extra(string $token, string $price = '10.00'): array
    {
        return self::ok(InProcessApi::post('/api/v1/extras', [
            'name' => 'Chocolate ' . Fixtures::uniqueSuffix(), 'price' => $price,
        ], $token), 201);
    }

    public function link(string $token, array $product, array $extras): void
    {
        self::ok(InProcessApi::call('PUT', '/api/v1/products/' . $product['id'] . '/extras', [
            'version' => $product['version'], 'extra_ids' => array_column($extras, 'id'),
        ], bearer: $token));
    }

    public static function item(array $product, string $quantity = '1.000', array $extras = []): array
    {
        return ['product_id' => $product['id'], 'quantity' => $quantity, 'extras' => $extras];
    }

    public function draft(string $token, array $items, array $overrides = []): array
    {
        return self::ok(InProcessApi::post('/api/v1/sales', array_replace([
            'items' => $items,
        ], $overrides), $token, self::key()), 201);
    }

    public function issue(string $token, array $draft): array
    {
        return self::ok(InProcessApi::post('/api/v1/sales/' . $draft['id'] . '/issue', [
            'version' => $draft['version'],
        ], $token, self::key()));
    }

    public function issued(string $adminToken, string $price = '1000.00', ?string $ownerToken = null): array
    {
        $product = $this->product($adminToken, $price);
        $token = $ownerToken ?? $adminToken;
        return $this->issue($token, $this->draft($token, [self::item($product)]));
    }

    public static function paymentBody(string $amount = '300.00', array $overrides = []): array
    {
        return array_replace([
            'amount' => $amount, 'effective_at' => '2026-10-15T12:00:00-06:00',
            'method' => 'EFECTIVO', 'reference' => 'Referencia de aceptación',
        ], $overrides);
    }

    public static function pay(string $token, string $saleId, string $amount = '300.00'): array
    {
        return self::ok(InProcessApi::post('/api/v1/sales/' . $saleId . '/payments',
            self::paymentBody($amount), $token, self::key()), 201);
    }

    public static function refundBody(string $amount): array
    {
        return ['amount' => $amount, 'effective_at' => '2026-10-16T18:00:00+00:00', 'reason' => 'Devolución real'];
    }
}
