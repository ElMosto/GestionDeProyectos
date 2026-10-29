<?php

declare(strict_types=1);

namespace Tests\Unit\Exports;

use App\Core\ApiException;
use App\Exports\ProjectGantt;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** §9 / §19C: structural checks do not replace actual Excel/LibreOffice acceptance. */
final class ProjectGanttTest extends TestCase
{
    private function data(): array
    {
        return [
            'project' => ['id' => '7', 'folio' => '=PR-7', 'name' => '=HYPERLINK("evil")',
                'description' => '+literal descripción', 'type' => 'EXTERNO', 'status' => 'EN_CURSO',
                'progress' => '33', 'planned_start' => '2026-01-01', 'planned_end' => '2026-01-05',
                'actual_start' => '2026-01-02', 'actual_end' => null, 'currency' => 'MXN',
                'created_at' => '2026-01-01T00:00:00Z', 'base_amount' => '1000.00',
                'notes' => 'NEVER_NOTES_SECRET', 'financial_summary' => ['total' => '1500.00',
                    'charges_total' => '500.00', 'paid_amount' => '300.00', 'refunded_amount' => '0.00',
                    'net_paid' => '300.00', 'balance' => '1200.00', 'payment_status' => 'PARCIAL', 'billable' => true]],
            'can_view_finance' => false, 'client' => ['name' => '@Cliente', 'notes' => 'NEVER_CLIENT_SECRET'],
            'leader' => ['name' => '-Líder'], 'studio' => ['name' => 'Estudio', 'timezone' => 'UTC'],
            'stages' => [['id' => '2', 'name' => '=Etapa', 'description' => 'Resumen operativo',
                'start_date' => '2026-01-01', 'end_date' => '2026-01-05', 'status' => 'EN_CURSO', 'progress' => 33]],
            'tasks' => [
                ['id' => 1, 'stage_id' => '2', 'stage_name' => '=Etapa', 'title' => '=TASK_SECRET()',
                    'assignee_name' => '@Persona', 'start_date' => '2026-01-01', 'end_date' => '2026-01-05', 'status' => 'PENDIENTE', 'progress' => '0'],
                ['id' => 2, 'stage_id' => '2', 'title' => 'Mismo día', 'start_date' => '2026-01-03', 'end_date' => '2026-01-03'],
                ['id' => 3, 'stage_id' => null, 'title' => 'Sin fechas', 'start_date' => null, 'end_date' => null],
            ],
            'charges' => [['concept' => 'FINANCE_SECRET', 'amount' => '500.00', 'status' => 'ACTIVO']],
            'payments' => [['folio' => 'PAYMENT_SECRET', 'amount' => '300.00', 'status' => 'ACTIVO', 'method' => 'EFECTIVO']],
            'refunds' => [['folio' => 'REFUND_SECRET', 'amount' => '100.00']],
            'expenses' => [['concept' => 'NEVER_EXPENSE_SECRET', 'amount' => '999.00']],
        ];
    }

    private function unpack(string $binary): array
    {
        self::assertStringStartsWith('PK', $binary);
        $file = tempnam(sys_get_temp_dir(), 'gantt-test-');
        file_put_contents($file, $binary);
        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($file));
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
            $zip->close();
            return [IOFactory::load($file), $entries];
        } finally {
            unlink($file);
        }
    }

    public function testRealWorkbookDatesDurationsAndEditableConditionalBars(): void
    {
        [$book, $entries] = $this->unpack((new ProjectGantt())->render($this->data()));
        try {
            self::assertSame(['Resumen', 'Gantt'], $book->getSheetNames());
            $sheet = $book->getSheetByName('Gantt');
            self::assertSame('I5', $sheet->getFreezePane());
            self::assertSame('A4:M8', $sheet->getAutoFilter()->getRange());
            self::assertSame('landscape', $sheet->getPageSetup()->getOrientation());
            self::assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            self::assertSame([1, 4], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            self::assertSame(Date::PHPToExcel(new \DateTimeImmutable('2026-01-01')), $sheet->getCell('D6')->getValue());
            self::assertEquals(4, $sheet->getCell('F6')->getValue());
            self::assertEquals(1, $sheet->getCell('F7')->getValue());
            self::assertSame('sin programar', $sheet->getCell('D8')->getValue());
            self::assertNull($sheet->getCell('F8')->getValue());
            self::assertSame('=TASK_SECRET()', $sheet->getCell('B6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D6')->getDataType());
            $xml = $entries['xl/worksheets/sheet2.xml'];
            self::assertStringContainsString('<conditionalFormatting sqref="I5:M8">', $xml);
            self::assertStringContainsString('type="expression"', $xml);
            self::assertStringContainsString('ISNUMBER($D5)', $xml);
            self::assertStringNotContainsString('TASK_SECRET', $xml);
            self::assertSame('none', $sheet->getStyle('I6')->getFill()->getFillType());
            // No cell formulas at all: all user text, including leading =,+,-,@, is literal.
            foreach ($book->getAllSheets() as $tab) {
                foreach ($tab->getCellCollection()->getCoordinates() as $coord) {
                    self::assertNotSame(DataType::TYPE_FORMULA, $tab->getCell($coord)->getDataType());
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testFinanceIsAbsentFromEveryZipEntryUnlessStrictBooleanTrue(): void
    {
        foreach ([false, null, 1, 'true'] as $permission) {
            $data = $this->data();
            $data['can_view_finance'] = $permission;
            [$book, $entries] = $this->unpack((new ProjectGantt())->render($data));
            try {
                foreach ($entries as $entry) {
                    foreach (['1000.00', '1500.00', 'FINANCE_SECRET', 'PAYMENT_SECRET', 'REFUND_SECRET',
                        'NEVER_NOTES_SECRET', 'NEVER_CLIENT_SECRET', 'NEVER_EXPENSE_SECRET'] as $secret) {
                        self::assertStringNotContainsString($secret, $entry);
                    }
                }
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }

    public function testAuthorizedFinancialStringsRemainExactWithoutExpensesOrNotes(): void
    {
        $data = $this->data();
        $data['can_view_finance'] = true;
        [$book, $entries] = $this->unpack((new ProjectGantt())->render($data));
        try {
            $xml = implode('', $entries);
            self::assertStringContainsString('1500.00 MXN', $xml);
            self::assertStringContainsString('1200.00 MXN', $xml);
            self::assertStringContainsString('FINANCE_SECRET', $xml);
            self::assertStringNotContainsString('NEVER_EXPENSE_SECRET', $xml);
            self::assertStringNotContainsString('NEVER_NOTES_SECRET', $xml);
            self::assertStringNotContainsString('1500.00', $entries['docProps/core.xml']);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testWeeklyScaleAndExplicitFilteringPreserveUnscheduledTasks(): void
    {
        $data = $this->data();
        $data['project']['planned_end'] = '2027-01-01';
        [$book] = $this->unpack((new ProjectGantt())->render($data));
        try {
            $sheet = $book->getSheetByName('Gantt');
            self::assertEquals(7, $sheet->getCell('J4')->getValue() - $sheet->getCell('I4')->getValue());
            self::assertStringContainsString('semanal', $sheet->getCell('A2')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
        [$book] = $this->unpack((new ProjectGantt())->render($data, '2026-02-01', '2026-02-03'));
        try {
            $sheet = $book->getSheetByName('Gantt');
            self::assertStringContainsString('fuera del rango: 2', $sheet->getCell('A3')->getValue());
            self::assertSame('Sin fechas', $sheet->getCell('B6')->getValue());
            self::assertSame('sin programar', $sheet->getCell('D6')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testEmptyDatesDoNotProduceFakeCalendar(): void
    {
        $data = $this->data();
        $data['project']['planned_start'] = $data['project']['planned_end'] = null;
        $data['stages'] = [];
        $data['tasks'] = [$data['tasks'][2]];
        [$book, $entries] = $this->unpack((new ProjectGantt())->render($data));
        try {
            self::assertSame('H', $book->getSheetByName('Gantt')->getHighestColumn());
            self::assertStringNotContainsString('<conditionalFormatting', $entries['xl/worksheets/sheet2.xml']);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testOversizedRangeAndProjectedCellsAndItemCountsAreRejectedExplicitly(): void
    {
        foreach (['range', 'cells', 'tasks', 'stages', 'text'] as $case) {
            $data = $this->data();
            if ($case === 'range') {
                $data['project']['planned_end'] = '2032-01-01';
            } elseif ($case === 'cells') {
                $data['tasks'] = array_fill(0, 2000, $data['tasks'][0]);
                $data['project']['planned_end'] = '2026-06-01';
            } elseif ($case === 'tasks') {
                $data['tasks'] = array_fill(0, 10001, $data['tasks'][0]);
            } elseif ($case === 'stages') {
                $data['stages'] = array_fill(0, 1001, $data['stages'][0]);
            } else {
                $data['project']['description'] = str_repeat('a', 32768);
            }
            try {
                (new ProjectGantt())->render($data);
                self::fail('Expected bounded export failure: ' . $case);
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
                self::assertSame(in_array($case, ['range', 'text'], true) ? 'EXPORT_RANGE_TOO_LARGE' : 'EXPORT_TOO_LARGE', $e->errorCode);
            }
        }
    }

    public function testInvalidDatesAreRejected(): void
    {
        foreach (['2026-02-30', 'nonsense'] as $invalid) {
            try {
                (new ProjectGantt())->render($this->data(), $invalid);
                self::fail('Expected date validation');
            } catch (ApiException $e) {
                self::assertSame('EXPORT_INVALID_DATES', $e->errorCode);
            }
        }
        $this->expectException(ApiException::class);
        (new ProjectGantt())->render($this->data(), '2026-02-01', '2026-01-01');
    }
}
