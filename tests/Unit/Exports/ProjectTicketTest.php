<?php

declare(strict_types=1);

namespace Tests\Unit\Exports;

use App\Core\ApiException;
use App\Exports\ProjectTicket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectTicketTest extends TestCase
{
    private function data(): array
    {
        return ['can_view_finance' => true,
            'project' => ['id' => '7', 'folio' => 'PR-7', 'name' => '<script>evil</script>',
                'description' => str_repeat('Largo <>&" ', 1000), 'type' => 'EXTERNO', 'billable' => true,
                'status' => 'EN_CURSO', 'created_at' => '2026-01-01T02:30:00Z', 'currency' => 'MXN',
                'base_amount' => '1000.00', 'notes' => 'PRIVATE_NOTES',
                'financial_summary' => ['total' => '1500.00', 'charges_total' => '500.00', 'paid_amount' => '300.00',
                    'refunded_amount' => '100.00', 'net_paid' => '200.00', 'balance' => '1300.00', 'payment_status' => 'PARCIAL']],
            'client' => ['name' => 'Banda <Rock>', 'notes' => 'PRIVATE_CLIENT', 'address' => 'PRIVATE_ADDRESS'],
            'leader' => ['name' => 'Líder'], 'studio' => ['name' => 'Estudio'], 'user_timezone' => 'America/Mexico_City',
            'charges' => [
                ['concept' => 'Mezcla', 'quantity' => '1.000', 'unit_price' => '500.00', 'amount' => '500.00', 'status' => 'ACTIVO'],
                ['concept' => 'VOID_CHARGE', 'amount' => '999.00', 'status' => 'ANULADO'],
            ],
            'payments' => [['folio' => 'P-1', 'amount' => '300.00', 'effective_at' => '2026-01-01T02:30:00Z',
                'method' => 'TRANSFERENCIA', 'status' => 'ACTIVO']],
            'refunds' => [['folio' => 'R-1', 'amount' => '100.00', 'effective_at' => '2026-01-02T00:00:00Z', 'status' => 'EFECTIVO']],
            'expenses' => [['concept' => 'PRIVATE_EXPENSE', 'amount' => '999.00']],
        ];
    }

    public function testBothPapersEscapeLongTextAndExposeOnlyExplicitFields(): void
    {
        foreach (['80mm', 'A4'] as $paper) {
            $data = $this->data();
            $html = (new ProjectTicket())->render($data, $paper);
            self::assertStringContainsString('paper-' . $paper, $html);
            self::assertStringContainsString(htmlspecialchars($data['project']['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            self::assertStringContainsString('&lt;script&gt;evil&lt;/script&gt;', $html);
            self::assertStringNotContainsString('<script>evil', $html);
            self::assertStringContainsString('31/12/2025 20:30', $html);
            self::assertStringContainsString('1500.00 MXN', $html);
            self::assertStringContainsString('1300.00 MXN', $html);
            self::assertStringContainsString('TRANSFERENCIA', $html);
            self::assertStringContainsString('R-1', $html);
            self::assertStringContainsString('Comprobante interno; no es factura fiscal', $html);
            self::assertStringContainsString('/api/v1/projects/7/ticket?paper=A4', $html);
            foreach (['PRIVATE_NOTES', 'PRIVATE_CLIENT', 'PRIVATE_ADDRESS', 'PRIVATE_EXPENSE', 'VOID_CHARGE'] as $secret) {
                self::assertStringNotContainsString($secret, $html);
            }
            self::assertSame($html, (new ProjectTicket())->render($data, $paper));
            self::assertSame($this->data(), $data);
        }
    }

    public function testPermissionMustBeStrictTrue(): void
    {
        foreach ([false, null, 1, 'true'] as $permission) {
            $data = $this->data();
            $data['can_view_finance'] = $permission;
            try {
                (new ProjectTicket())->render($data);
                self::fail('Expected forbidden');
            } catch (ApiException $e) {
                self::assertSame(403, $e->status);
            }
        }
    }

    public function testInternalNonBillableIsEstimatedNotAnAccountReceivableOrInventedCollection(): void
    {
        $data = $this->data();
        $data['project']['type'] = 'INTERNO';
        $data['project']['billable'] = false;
        $data['project']['financial_summary']['billable'] = false;
        $data['project']['financial_summary']['value_kind'] = 'VALOR_INTERNO_ESTIMADO';
        $data['payments'] = $data['refunds'] = [];
        $html = (new ProjectTicket())->render($data);
        self::assertStringContainsString('valor interno estimado', $html);
        self::assertStringContainsString('1500.00 MXN', $html);
        self::assertStringContainsString('Sin cuenta por cobrar', $html);
        self::assertStringNotContainsString('Neto cobrado', $html);
        self::assertStringNotContainsString('Pagos válidos', $html);
        self::assertStringNotContainsString('1300.00 MXN', $html);
    }

    public function testInvalidTimezoneFallsBackToUtcAndInvalidIdCannotCreateUrl(): void
    {
        $data = $this->data();
        $data['user_timezone'] = 'Invalid/Timezone';
        $data['project']['id'] = '7" onclick="evil';
        $html = (new ProjectTicket())->render($data);
        self::assertStringContainsString('Zona horaria: UTC', $html);
        self::assertStringContainsString('01/01/2026 02:30', $html);
        self::assertStringNotContainsString('/api/v1/projects/', $html);
    }

    public function testMissingTotalsAreUnavailableNotInventedAndPaperIsValidated(): void
    {
        $data = $this->data();
        unset($data['project']['financial_summary']['total']);
        self::assertStringContainsString('No disponible', (new ProjectTicket())->render($data));
        $this->expectException(InvalidArgumentException::class);
        (new ProjectTicket())->render($data, 'unsafe');
    }
}
