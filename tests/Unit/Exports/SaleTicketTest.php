<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exports;

use App\Exports\SaleTicket;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SaleTicketTest extends TestCase
{
    private function data(): array
    {
        return [
            'id' => 42, 'folio' => 'V-000042', 'type' => 'ORDINARIA', 'status' => 'EMITIDA',
            'currency' => 'MXN', 'issued_at' => '2026-10-28 12:00:00', 'created_at' => '2026-10-27 12:00:00',
            'client' => ['name' => 'Cliente', 'type' => 'BANDA', 'contact_name' => 'Contacto',
                'representative_name' => 'Representante', 'phone' => '555555'],
            'responsible' => ['id' => 8, 'name' => 'Responsable'],
            'items' => [['id' => 1, 'name_snapshot' => 'Producto', 'quantity' => '2.000', 'unit_price' => '50.00',
                'line_total' => '120.00', 'extras' => [['name_snapshot' => 'Chocolate',
                    'quantity_per_unit' => '1.000', 'unit_price' => '10.00']]]],
            'financial_summary' => ['total' => '120.00', 'paid_amount' => '30.00', 'refunded_amount' => '10.00',
                'net_paid' => '20.00', 'balance' => '100.00', 'payment_status' => 'PARCIAL'],
            'payments' => [['folio' => 'P-01', 'amount' => '30.00', 'effective_at' => '2026-10-28 12:10:00',
                'method' => 'TRANSFERENCIA', 'status' => 'REGISTRADO', 'refunded_amount' => '10.00', 'reference' => 'Ref']],
            'refunds' => [['folio' => 'R-01', 'amount' => '10.00', 'effective_at' => '2026-10-28 12:20:00',
                'reason' => 'Devolución', 'status' => 'REGISTRADO']],
            'studio' => ['name' => 'Mi estudio', 'timezone' => 'UTC', 'address' => 'Calle 1', 'phone' => '555'],
        ];
    }

    public function testCompleteTicketAndLiteralMoney(): void
    {
        $data = $this->data();
        // Deliberately inconsistent totals prove that presentation does not recalculate money.
        $data['financial_summary']['total'] = '9999999999.99';
        $html = (new SaleTicket())->render($data);
        foreach (['Comprobante interno; no es factura fiscal', 'V-000042', 'Responsable', 'Chocolate',
            '1.000', '2.000', '50.00 MXN', '10.00 MXN', '120.00 MXN', '9999999999.99 MXN',
            '100.00 MXN', 'TRANSFERENCIA', 'P-01', 'R-01', 'PARCIAL'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testAllUntrustedDisplayStringsAreEscaped(): void
    {
        $payload = '<img src=x onerror="alert(1)">&\'"';
        $replace = static function (array $data) use (&$replace, $payload): array {
            foreach ($data as $key => $value) {
                $data[$key] = is_array($value) ? $replace($value) : $payload;
            }
            return $data;
        };
        $data = $replace($this->data());
        $data['reservation'] = ['band_name' => $payload, 'space_name' => $payload];
        $html = (new SaleTicket())->render($data);
        self::assertStringNotContainsString($payload, $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString(htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        self::assertStringNotContainsString('/api/v1/sales/', $html);
    }

    public function testSensitiveFieldsAreNotSerializedAtAnyDepth(): void
    {
        $data = $this->data();
        $data['expenses'] = ['internal-secret-expense'];
        $data['token'] = 'secret-token';
        $data['responsible']['password_hash'] = 'secret-hash';
        $data['client']['notes'] = 'secret-private-note';
        $data['payments'][0]['registered_by'] = ['token' => 'secret-registrar'];
        $html = (new SaleTicket())->render($data);
        foreach (['internal-secret-expense', 'secret-token', 'secret-hash', 'secret-private-note', 'secret-registrar'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testBothPapersHaveValidAccessibleMarkupAndSafeLinks(): void
    {
        foreach (['80mm', 'A4'] as $paper) {
            $html = (new SaleTicket())->render($this->data(), $paper);
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            self::assertSame($paper, $document->getElementsByTagName('body')->item(0)->getAttribute('data-paper'));
            self::assertSame(1, $xpath->query('//h1')->length);
            self::assertSame(2, $xpath->query('//th[@scope="col"]')->length);
            self::assertSame(1, $xpath->query('//th[@scope="row"]')->length);
            self::assertSame(1, $xpath->query('//a[@aria-current="page"]')->length);
            self::assertStringContainsString('/api/v1/sales/42/ticket?paper=A4', $html);
            self::assertStringNotContainsString('<style', $html);
            self::assertSame(0, $xpath->query('//*[@onclick or @onload or @style]')->length);
            self::assertStringContainsString('type="module" src="/assets/js/ticket.js"', $html);
        }
    }

    public function testLongTextIsNotTruncated(): void
    {
        $data = $this->data();
        $long = str_repeat('Texto largo á ', 1000);
        $data['items'][0]['name_snapshot'] = $long;
        self::assertStringContainsString($long, (new SaleTicket())->render($data));
    }

    public function testDraftDoesNotInventEmissionFromCreationDate(): void
    {
        $data = $this->data();
        $data['status'] = 'BORRADOR';
        $data['issued_at'] = null;
        $html = (new SaleTicket())->render($data);
        self::assertStringContainsString('Fecha de emisión: sin emitir', $html);
        self::assertStringContainsString('status-warning', $html);
        self::assertStringNotContainsString('27/10/2026', $html);
    }

    public function testCancelledDocumentIsProminentlyLabelled(): void
    {
        $data = $this->data();
        $data['status'] = 'CANCELADA';
        self::assertStringContainsString('status-warning">Estado: CANCELADA', (new SaleTicket())->render($data));
    }

    public function testRenderIsRepeatableAndDoesNotMutateInputOrWriteOutput(): void
    {
        $data = $this->data();
        $before = $data;
        $renderer = new SaleTicket();
        ob_start();
        $first = $renderer->render($data);
        $second = $renderer->render($data);
        $output = ob_get_clean();
        self::assertSame('', $output);
        self::assertSame($first, $second);
        self::assertSame($before, $data);
    }

    public function testConfiguredTimezoneAndOptionalReservation(): void
    {
        $data = $this->data();
        $data['user_timezone'] = 'America/Mexico_City';
        $data['reservation'] = ['band_name' => 'Banda Uno', 'space_name' => 'Sala Dos',
            'start_at' => '2026-10-28 15:00:00', 'end_at' => '2026-10-28 16:00:00'];
        $html = (new SaleTicket())->render($data);
        self::assertStringContainsString('28/10/2026 06:00', $html);
        self::assertStringContainsString('Inicio: 28/10/2026 09:00', $html);
        self::assertStringContainsString('Banda Uno', $html);
        self::assertStringContainsString('Sala Dos', $html);
    }

    public function testNoInventedStudioDataOrReservation(): void
    {
        $data = $this->data();
        $data['studio'] = ['name' => '', 'timezone' => 'invalid-zone'];
        $data['client'] = null;
        $html = (new SaleTicket())->render($data);
        self::assertStringContainsString('Estudio musical', $html);
        self::assertStringContainsString('Sin cliente', $html);
        self::assertStringContainsString('Zona horaria: UTC', $html);
        self::assertStringNotContainsString('reservation-heading', $html);
        self::assertStringNotContainsString('Calle 1', $html);
    }

    public function testRejectsUnsupportedPaper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SaleTicket())->render($this->data(), '../other-template');
    }
}
