<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\FinanceFixtures as H;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures as P;
use App\Tests\Support\SalesFixtures as F;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;

/** §9/§19.C/H — authorized API outputs. ZIP/reader checks are NOT Excel/LibreOffice manual proof. */
final class ProjectOutputsApiTest extends DatabaseTestCase
{
    public function test_C_member_gantt_all_zip_parts_exclude_financial_and_private_markers(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [$member, $memberToken] = $p->actor(Role::COLABORADOR);
        [$admin, $adminToken] = $p->actor(Role::ADMINISTRADOR);
        $client = $this->fixtures->createClient($leader['id'], ['notes' => 'CLIENT_PRIVATE_ZX', 'address' => 'CLIENT_ADDRESS_ZX']);
        $project = $p->project($token, $leader['id'], ['type' => 'INTERNO', 'billable' => true,
            'client_id' => (string) $client['id'], 'base_amount' => '98765.43']);
        $p->members($token, $project['id'], [$member['id']]);
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/charges', [
            'concept' => 'FINANCIAL_CHARGE_SECRET_ZX', 'quantity' => '1.000', 'unit_price' => '1234.56', 'charge_date' => gmdate('Y-m-d'),
        ], $token, F::key()), 201);
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/payments', F::paymentBody('314.15', [
            'reference' => 'PAYMENT_REFERENCE_SECRET_ZX',
        ]), $token, F::key()), 201);
        F::ok(Api::post('/api/v1/expenses', H::expenseBody($admin['id'], [
            'project_id' => $project['id'], 'concept' => 'EXPENSE_PRIVATE_ZX', 'amount' => '876.54',
        ]), $adminToken, F::key()), 201);
        $response = Api::get('/api/v1/projects/' . $project['id'] . '/gantt.xlsx', bearer: $memberToken);
        self::assertSame(200, $response->status, $response->json());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->contentType());
        self::assertStringStartsWith('PK', $response->rawBody);
        self::assertStringContainsString('proyecto_' . $project['folio'] . '_gantt.xlsx', $response->headers['Content-Disposition']);
        $entries = $this->zipParts($response->rawBody);
        self::assertArrayHasKey('xl/workbook.xml', $entries);
        foreach ($entries as $name => $part) {
            foreach (['98765.43', '1234.56', '99999.99', '314.15', '99685.84', '876.54',
                'FINANCIAL_CHARGE_SECRET_ZX', 'PAYMENT_REFERENCE_SECRET_ZX', 'EXPENSE_PRIVATE_ZX',
                'CLIENT_PRIVATE_ZX', 'CLIENT_ADDRESS_ZX', 'password_hash', 'financial_summary'] as $secret) {
                self::assertStringNotContainsString($secret, $part, '§9 financial/private leak in ZIP entry ' . $name);
            }
        }
        $leaderFile = Api::get('/api/v1/projects/' . $project['id'] . '/gantt.xlsx', bearer: $token);
        self::assertSame(200, $leaderFile->status);
        $authorized = implode("\n", $this->zipParts($leaderFile->rawBody));
        self::assertStringContainsString('98765.43', $authorized);
        self::assertStringContainsString('FINANCIAL_CHARGE_SECRET_ZX', $authorized);
        self::assertStringNotContainsString('EXPENSE_PRIVATE_ZX', $authorized);
        self::assertStringNotContainsString('CLIENT_PRIVATE_ZX', $authorized);
    }

    public function test_C_real_api_gantt_has_dates_duration_conditional_formatting_and_literal_hostile_title(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [$member] = $p->actor(Role::COLABORADOR);
        $project = $p->project($token, $leader['id']);
        $p->members($token, $project['id'], [$member['id']]);
        $stage = $p->stage($token, $project['id']);
        $hostile = '=HYPERLINK("https://example.invalid","NO_EJECUTAR")';
        $p->task($token, $stage['id'], $member['id'], ['title' => $hostile,
            'start_date' => '2026-11-03', 'end_date' => '2026-11-03']);
        $p->task($token, $stage['id'], $member['id'], ['title' => 'Sin fecha QA', 'start_date' => null, 'end_date' => null]);
        $response = Api::get('/api/v1/projects/' . $project['id'] . '/gantt.xlsx', bearer: $token);
        self::assertSame(200, $response->status);
        $parts = $this->zipParts($response->rawBody);
        self::assertStringContainsString('conditionalFormatting', $parts['xl/worksheets/sheet2.xml']);
        $file = $this->temporaryFile($response->rawBody);
        $book = null;
        try {
            $book = IOFactory::load($file);
            self::assertSame(['Resumen', 'Gantt'], $book->getSheetNames());
            $sheet = $book->getSheetByName('Gantt');
            $found = [];
            for ($row = 5; $row <= $sheet->getHighestDataRow(); $row++) {
                $title = $sheet->getCell('B' . $row)->getValue();
                if ($title === $hostile) {
                    $found[] = 'dated';
                    self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B' . $row)->getDataType());
                    self::assertSame(1, $sheet->getCell('F' . $row)->getValue());
                    self::assertSame('2026-11-03', Date::excelToDateTimeObject($sheet->getCell('D' . $row)->getValue())->format('Y-m-d'));
                    self::assertSame($sheet->getCell('D' . $row)->getValue(), $sheet->getCell('E' . $row)->getValue());
                }
                if ($title === 'Sin fecha QA') {
                    $found[] = 'undated';
                    self::assertSame('sin programar', $sheet->getCell('D' . $row)->getValue());
                    self::assertNull($sheet->getCell('F' . $row)->getValue());
                }
            }
            sort($found);
            self::assertSame(['dated', 'undated'], $found);
            self::assertSame('I5', $sheet->getFreezePane());
            self::assertNotSame('', $sheet->getAutoFilter()->getRange());
        } finally {
            $book?->disconnectWorksheets();
            unlink($file);
        }
    }

    public function test_A_C_ticket_member_forbidden_and_foreign_leader_cannot_read_outputs(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [$member, $memberToken] = $p->actor(Role::COLABORADOR);
        [, $foreign] = $p->actor();
        $project = $p->project($token, $leader['id'], ['base_amount' => '98765.43']);
        $p->members($token, $project['id'], [$member['id']]);
        self::assertSame(403, Api::get('/api/v1/projects/' . $project['id'] . '/ticket', bearer: $memberToken)->status);
        foreach (['ticket', 'gantt.xlsx'] as $suffix) {
            $result = Api::get('/api/v1/projects/' . $project['id'] . '/' . $suffix, bearer: $foreign);
            self::assertSame(404, $result->status);
            self::assertStringNotContainsString('98765.43', $result->rawBody);
        }
    }

    public function test_C_ticket_both_papers_match_exact_api_totals_escape_xss_and_never_print_expenses(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [$admin, $adminToken] = $p->actor(Role::ADMINISTRADOR);
        $client = $this->fixtures->createClient($leader['id']);
        $project = $p->project($token, $leader['id'], ['base_amount' => '1000.00', 'billable' => true,
            'client_id' => (string) $client['id'],
            'name' => '<script>alert(1)</script>', 'description' => 'Descripción <b>literal</b> & segura']);
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/payments', F::paymentBody(), $token, F::key()), 201);
        F::ok(Api::post('/api/v1/expenses', H::expenseBody($admin['id'], [
            'project_id' => $project['id'], 'concept' => 'NO_IMPRIMIR_GASTO_ZX',
        ]), $adminToken, F::key()), 201);
        $summary = F::ok(Api::get('/api/v1/projects/' . $project['id'], bearer: $token))['financial_summary'];
        foreach (['80mm', 'A4'] as $paper) {
            $result = Api::get('/api/v1/projects/' . $project['id'] . '/ticket', ['paper' => $paper], $token);
            self::assertSame(200, $result->status);
            self::assertStringContainsString('text/html', $result->contentType());
            foreach (['total', 'net_paid', 'balance'] as $field) {
                self::assertStringContainsString($summary[$field] . ' MXN', $result->rawBody);
            }
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result->rawBody);
            self::assertStringNotContainsString('<script>alert(1)</script>', $result->rawBody);
            self::assertStringNotContainsString('NO_IMPRIMIR_GASTO_ZX', $result->rawBody);
            self::assertStringContainsString('Comprobante interno; no es factura fiscal', $result->rawBody);
        }
        self::assertSame(422, Api::get('/api/v1/projects/' . $project['id'] . '/ticket', ['paper' => 'A3'], $token)->status);
    }

    public function test_B_C_nonbillable_ticket_is_estimated_value_not_revenue_or_receivable(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        $project = $p->project($token, $leader['id'], ['base_amount' => '1500.00']);
        $current = F::ok(Api::get('/api/v1/projects/' . $project['id'], bearer: $token));
        self::assertSame('0.00', $current['financial_summary']['net_paid']);
        self::assertSame('0.00', $current['financial_summary']['balance']);
        $ticket = Api::get('/api/v1/projects/' . $project['id'] . '/ticket', bearer: $token);
        self::assertSame(200, $ticket->status);
        self::assertStringContainsString('valor interno estimado', $ticket->rawBody);
        self::assertStringContainsString('1500.00 MXN', $ticket->rawBody);
        self::assertStringContainsString('Sin cuenta por cobrar', $ticket->rawBody);
        self::assertStringNotContainsString('Neto cobrado', $ticket->rawBody);
    }

    public function test_C_reprinting_is_readonly_for_all_business_rows_and_commercial_evidence(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        $client = $this->fixtures->createClient($leader['id']);
        $project = $p->project($token, $leader['id'], ['base_amount' => '1000.00', 'billable' => true, 'client_id' => (string) $client['id']]);
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/payments', F::paymentBody(), $token, F::key()), 201);
        $h = new H($this->pdo);
        $before = $h->snapshot();
        foreach (['ticket', 'ticket', 'gantt.xlsx', 'gantt.xlsx'] as $suffix) {
            self::assertSame(200, Api::get('/api/v1/projects/' . $project['id'] . '/' . $suffix, bearer: $token)->status);
        }
        self::assertSame($before, $h->snapshot());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    /** Scan every ZIP entry, including properties, hidden sheets, relationships and comments. */
    private function zipParts(string $binary): array
    {
        $file = $this->temporaryFile($binary);
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($file));
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $value = $zip->getFromIndex($i);
                self::assertIsString($name);
                self::assertIsString($value);
                $parts[$name] = $value;
            }
            return $parts;
        } finally {
            $zip->close();
            unlink($file);
        }
    }

    private function temporaryFile(string $binary): string
    {
        $file = tempnam(sys_get_temp_dir(), 'qa-project-output-');
        self::assertIsString($file);
        self::assertSame(strlen($binary), file_put_contents($file, $binary));
        return $file;
    }
}
