<?php

declare(strict_types=1);

namespace App\Exports;

use App\Core\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** §9 / §19C: editable XLSX, no SQL, no business mutations or monetary arithmetic. */
final class ProjectGantt
{
    public function __construct(private readonly ?string $tempDir = null)
    {
    }

    public function render(array $data, ?string $from = null, ?string $to = null): string
    {
        $tasks = $data['tasks'] ?? [];
        $stages = $data['stages'] ?? [];
        if (count($tasks) > 10000 || count($stages) > 1000) {
            throw new ApiException(422, 'EXPORT_TOO_LARGE', 'Máximo 10000 tareas y 1000 etapas. Reduce el proyecto antes de exportar.');
        }
        [$start, $end] = $this->range($data, $from, $to);
        $days = $start === null ? 0 : (int) $start->diff($end)->days;
        if ($start !== null && $end > $start->modify('+5 years')) {
            $this->tooLarge('El rango supera 5 años. Selecciona periodos de hasta 5 años con from/to y descarga cada periodo por separado.');
        }
        $step = $days <= 180 ? 1 : 7;
        $calendar = [];
        if ($start !== null) {
            for ($day = $start; $day <= $end; $day = $day->modify('+' . $step . ' days')) {
                $calendar[] = $day;
            }
        }
        $omitted = 0;
        $visible = [];
        foreach ($tasks as $task) {
            $a = $this->day($task['start_date'] ?? null);
            $b = $this->day($task['end_date'] ?? null);
            if ($start !== null && $a !== null && $b !== null && ($b < $start || $a > $end)) {
                $omitted++;
                continue;
            }
            $visible[] = $task;
        }
        // Bound memory as well as Excel's column limit; never quietly drop cells or tasks.
        // Proposed hosting safeguard: projected calendar grid plus summary, before allocation.
        $summaryCells = 100;
        if (($data['can_view_finance'] ?? false) === true) {
            $summaryCells += 5 * (count($data['charges'] ?? []) + count($data['payments'] ?? []) + count($data['refunds'] ?? []));
        }
        if ((count($visible) + count($stages) + 5) * (8 + count($calendar)) + $summaryCells > 200000) {
            throw new ApiException(422, 'EXPORT_TOO_LARGE', 'La exportación supera 200000 celdas proyectadas. Reduce el rango con from/to y descarga por periodos.');
        }
        $book = new Spreadsheet();
        try {
            // Static properties only: not even authorized financial values belong in metadata.
            $book->getProperties()->setCreator('Estudio musical')->setTitle('Gantt de proyecto');
            $book->getDefaultStyle()->getAlignment()->setWrapText(true)->setVertical('top');
            $summary = $book->getActiveSheet()->setTitle('Resumen');
            $gantt = $book->createSheet()->setTitle('Gantt');
            $this->summary($summary, $data);
            $this->gantt($gantt, $stages, $visible, $calendar, $step, $start, $end, $omitted);
            foreach ([$summary, $gantt] as $sheet) {
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                    ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.25)->setRight(0.25);
            }
            $book->setActiveSheetIndex(0);
            $file = tempnam($this->tempDir ?? sys_get_temp_dir(), 'project-gantt-');
            if ($file === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal privado.');
            }
            try {
                $writer = new Xlsx($book);
                $writer->setPreCalculateFormulas(false);
                $writer->save($file);
                $binary = file_get_contents($file);
                if ($binary === false) {
                    throw new RuntimeException('No se pudo leer el archivo Excel.');
                }
                return $binary;
            } finally {
                unlink($file);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function summary(Worksheet $sheet, array $data): void
    {
        $this->text($sheet, 'A1', 'Resumen del proyecto');
        $row = 2;
        foreach (ProjectExportView::projectRows($data) as [$label, $value]) {
            $this->text($sheet, 'A' . $row, $label);
            $date = in_array($label, ['Inicio previsto', 'Fin previsto', 'Inicio real', 'Fin real'], true)
                && $value !== 'sin programar' ? $this->day($value) : null;
            if ($date !== null) {
                $sheet->setCellValueExplicit('B' . $row, Date::PHPToExcel($date), DataType::TYPE_NUMERIC);
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } else {
                $this->text($sheet, 'B' . $row, $value);
            }
            $row++;
        }
        $this->text($sheet, 'A' . $row, 'Fecha de registro');
        $this->text($sheet, 'B' . $row++, ProjectExportView::date(
            $data['project']['created_at'] ?? null, ProjectExportView::timezone($data),
        ));
        if (($data['can_view_finance'] ?? false) === true) {
            foreach (ProjectExportView::financialRows($data['project']) as [$label, $value]) {
                $this->text($sheet, 'A' . $row, $label);
                $this->text($sheet, 'B' . $row++, $value);
            }
            $currency = ProjectExportView::text($data['project']['currency'] ?? 'MXN');
            foreach ($data['charges'] ?? [] as $charge) {
                if (($charge['status'] ?? '') !== 'ACTIVO') {
                    continue;
                }
                $this->text($sheet, 'A' . $row, 'Cargo: ' . ProjectExportView::text($charge['concept'] ?? ''));
                $this->text($sheet, 'B' . $row++, ProjectExportView::money($charge['amount'] ?? null, $currency));
            }
            foreach (['payments' => 'Pago', 'refunds' => 'Reembolso'] as $key => $label) {
                foreach ($data[$key] ?? [] as $entry) {
                    $this->text($sheet, 'A' . $row, $label . ' ' . ProjectExportView::text($entry['folio'] ?? ''));
                    $this->text($sheet, 'B' . $row, ProjectExportView::money($entry['amount'] ?? null, $currency));
                    $this->text($sheet, 'C' . $row, ProjectExportView::text($entry['status'] ?? ''));
                    $this->text($sheet, 'D' . $row, ProjectExportView::date($entry['effective_at'] ?? null, ProjectExportView::timezone($data)));
                    $this->text($sheet, 'E' . $row++, ProjectExportView::text($entry['method'] ?? ''));
                }
            }
        }
        $this->text($sheet, 'A' . $row++, 'La edición del archivo no sincroniza cambios con el sistema.');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(65);
        foreach (['C', 'D', 'E'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(23);
        }
        $sheet->freezePane('B2');
        $sheet->setAutoFilter('A1:E' . ($row - 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
    }

    private function gantt(Worksheet $sheet, array $stages, array $tasks, array $calendar, int $step,
        ?DateTimeImmutable $start, ?DateTimeImmutable $end, int $omitted): void
    {
        $this->text($sheet, 'A1', 'Leyenda: azul = periodo programado; sin programar = sin barra. Duración: días calendario (fin − inicio; mismo día = 1).');
        $this->text($sheet, 'A2', $start === null ? 'Sin fechas programadas.'
            : 'Rango: ' . $start->format('Y-m-d') . ' a ' . $end->format('Y-m-d')
                . '; escala ' . ($step === 1 ? 'diaria' : 'semanal (intervalos de 7 días desde el inicio)') . '.');
        $this->text($sheet, 'A3', 'Tareas omitidas por estar fuera del rango: ' . $omitted
            . '. Las tareas sin fechas se incluyen siempre. Fechas completas conservadas; barras limitadas al rango visible.');
        foreach (['Etapa', 'Tarea / resumen de etapa', 'Responsable', 'Inicio', 'Fin', 'Duración (días)', 'Estado', 'Avance (%)'] as $i => $title) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1) . '4', $title);
        }
        foreach ($calendar as $i => $day) {
            $column = Coordinate::stringFromColumnIndex($i + 9);
            $sheet->setCellValueExplicit($column . '4', Date::PHPToExcel($day), DataType::TYPE_NUMERIC);
            $sheet->getStyle($column . '4')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->getColumnDimension($column)->setWidth($step === 1 ? 12 : 14);
        }
        $row = 5;
        $groups = [];
        foreach ($tasks as $task) {
            $groups[(string) ($task['stage_id'] ?? '')][] = $task;
        }
        foreach ($stages as $stage) {
            $this->taskRow($sheet, $row++, $stage, true);
            $key = (string) $stage['id'];
            foreach ($groups[$key] ?? [] as $task) {
                $this->taskRow($sheet, $row++, $task, false);
            }
            unset($groups[$key]);
        }
        foreach ($groups as $group) {
            foreach ($group as $task) {
                $this->taskRow($sheet, $row++, $task, false);
            }
        }
        $lastColumn = Coordinate::stringFromColumnIndex(8 + count($calendar));
        if ($row > 5 && $calendar !== []) {
            $condition = new Conditional();
            $condition->setConditionType(Conditional::CONDITION_EXPRESSION);
            // Only static references/operators. No user-controlled text can become a formula.
            $condition->addCondition('AND(ISNUMBER($D5),ISNUMBER($E5),$D5<=I$4+' . ($step - 1) . ',$E5>=I$4)');
            $condition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
            $sheet->getStyle('I5:' . $lastColumn . ($row - 1))->setConditionalStyles([$condition]);
        }
        $sheet->getStyle('A4:' . $lastColumn . '4')->getFont()->setBold(true);
        foreach (['A' => 26, 'B' => 48, 'C' => 25, 'D' => 18, 'E' => 18, 'F' => 17, 'G' => 22, 'H' => 15] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        // Long legends wrap without being clipped into the first narrow column.
        foreach ([1, 2, 3] as $headerRow) {
            $sheet->mergeCells('A' . $headerRow . ':H' . $headerRow);
            $sheet->getRowDimension($headerRow)->setRowHeight(32);
        }
        $sheet->freezePane('I5');
        $sheet->setAutoFilter('A4:' . $lastColumn . max(4, $row - 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4)
            ->setColumnsToRepeatAtLeftByStartAndEnd('A', 'H');
    }

    private function taskRow(Worksheet $sheet, int $row, array $item, bool $stage): void
    {
        $this->text($sheet, 'A' . $row, $stage ? ($item['name'] ?? '') : ($item['stage_name'] ?? 'Sin etapa'));
        $this->text($sheet, 'B' . $row, $stage ? 'Etapa: ' . ProjectExportView::text($item['description'] ?? $item['name'] ?? '') : ($item['title'] ?? ''));
        $this->text($sheet, 'C' . $row, $stage ? '' : ($item['assignee_name'] ?? 'Sin responsable'));
        $a = $this->day($item['start_date'] ?? null);
        $b = $this->day($item['end_date'] ?? null);
        if ($a !== null && $b !== null && $a > $b) {
            throw new ApiException(422, 'EXPORT_INVALID_DATES', 'La fecha final no puede ser anterior al inicio.');
        }
        foreach (['D' => $a, 'E' => $b] as $column => $day) {
            if ($day === null) {
                $this->text($sheet, $column . $row, 'sin programar');
            } else {
                $sheet->setCellValueExplicit($column . $row, Date::PHPToExcel($day), DataType::TYPE_NUMERIC);
                $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
        }
        if ($a !== null && $b !== null) {
            $sheet->setCellValueExplicit('F' . $row, max(1, (int) $a->diff($b)->days), DataType::TYPE_NUMERIC);
        }
        $this->text($sheet, 'G' . $row, $item['status'] ?? '');
        $this->text($sheet, 'H' . $row, $item['progress'] ?? '');
        if ($stage) {
            $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
        }
    }

    private function text(Worksheet $sheet, string $cell, mixed $value): void
    {
        $text = ProjectExportView::text($value);
        if (mb_strlen($text, 'UTF-8') > 32767) {
            $this->tooLarge('Un texto supera el límite de 32767 caracteres por celda de Excel. Reduce el texto antes de exportar.');
        }
        $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
    }

    private function day(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || $date->format('Y') < '1900') {
            throw new ApiException(422, 'EXPORT_INVALID_DATES', 'Usa fechas válidas YYYY-MM-DD desde 1900.');
        }
        return $date;
    }

    private function range(array $data, ?string $from, ?string $to): array
    {
        $days = [];
        foreach (array_merge($data['tasks'] ?? [], $data['stages'] ?? []) as $item) {
            foreach (['start_date', 'end_date'] as $key) {
                if (($day = $this->day($item[$key] ?? null)) !== null) {
                    $days[] = $day;
                }
            }
        }
        foreach (['planned_start', 'planned_end'] as $key) {
            if (($day = $this->day($data['project'][$key] ?? null)) !== null) {
                $days[] = $day;
            }
        }
        $start = $this->day($from) ?? ($days === [] ? $this->day($to) : min($days));
        $end = $this->day($to) ?? ($days === [] ? $start : max($days));
        if ($start !== null && $end !== null && $start > $end) {
            throw new ApiException(422, 'EXPORT_INVALID_DATES', 'El rango solicitado tiene el inicio posterior al fin.');
        }
        return [$start, $end];
    }

    private function tooLarge(string $message): never
    {
        throw new ApiException(422, 'EXPORT_RANGE_TOO_LARGE', $message);
    }
}
