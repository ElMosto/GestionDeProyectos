<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures;

final class PaymentsContractTest extends DatabaseTestCase
{
    public function test_D_document_payment_and_refund_versions_remain_strings_across_api_reads(): void
    {
        $fixtures = new SalesFixtures($this->fixtures);
        [, $adminToken] = $fixtures->actor();
        $sale = $fixtures->issued($adminToken);
        $paid = SalesFixtures::pay($adminToken, $sale['id']);
        $this->assertIsString($paid['document']['version']);
        $this->assertIsString($paid['payment']['version']);
        $refunded = SalesFixtures::ok(Api::post(
            '/api/v1/payments/' . $paid['payment']['id'] . '/refunds',
            SalesFixtures::refundBody('10.00'), $adminToken, SalesFixtures::key(),
        ), 201);
        $this->assertIsString($refunded['document']['version']);
        $this->assertIsString($refunded['refund']['version']);
        $history = Api::get('/api/v1/sales/' . $sale['id'] . '/payments', bearer: $adminToken);
        $this->assertSame(200, $history->status);
        $this->assertIsString($history->payload['data'][0]['version']);
        $refundHistory = Api::get('/api/v1/payments/' . $paid['payment']['id'] . '/refunds', bearer: $adminToken);
        $this->assertSame(200, $refundHistory->status);
        $this->assertIsString($refundHistory->payload['data'][0]['version']);
    }
}
