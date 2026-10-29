<?php

declare(strict_types=1);

namespace App\Exports;

use App\Core\ApiException;
use InvalidArgumentException;
use Throwable;

/** Pure, read-only presentation of an object-authorized DTO (§9, §19C). */
final class ProjectTicket
{
    public function __construct(private readonly string $templatesDir = __DIR__ . '/../../templates')
    {
    }

    public function render(array $data, string $paper = '80mm'): string
    {
        // Defence in depth, even if the endpoint already checked the object policy.
        if (($data['can_view_finance'] ?? false) !== true) {
            throw ApiException::forbidden();
        }
        if (!in_array($paper, ['80mm', 'A4'], true)) {
            throw new InvalidArgumentException('Formato de papel no válido.');
        }
        $project = $data['project'];
        $escape = static fn (mixed $v): string => htmlspecialchars(
            ProjectExportView::text($v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
        );
        $timezone = ProjectExportView::timezone($data);
        $date = static fn (mixed $v): string => ProjectExportView::date($v, $timezone);
        $currency = ProjectExportView::text($project['currency'] ?? 'MXN');
        $money = static fn (mixed $v): string => $escape(ProjectExportView::money($v, $currency));
        $rows = ProjectExportView::financialRows($project);
        $id = ProjectExportView::text($project['id'] ?? '');
        $ticketUrl = preg_match('/^[1-9][0-9]*$/D', $id) === 1 ? '/api/v1/projects/' . $id . '/ticket' : null;
        $level = ob_get_level();
        ob_start();
        try {
            require $this->templatesDir . '/project-ticket.php';
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
