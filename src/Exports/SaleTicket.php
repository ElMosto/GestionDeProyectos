<?php

declare(strict_types=1);

namespace App\Exports;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Read-only presentation of the already authorized SaleService::ticketData DTO (§10–11). */
final class SaleTicket
{
    public function __construct(private readonly string $templatesDir = __DIR__ . '/../../templates')
    {
    }

    /** Authorization belongs to the calling service; this renderer never loads additional data. */
    public function render(array $data, string $paper = '80mm'): string
    {
        if (!in_array($paper, ['80mm', 'A4'], true)) {
            throw new InvalidArgumentException('Formato de papel no válido.');
        }

        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $studio = is_array($data['studio'] ?? null) ? $data['studio'] : [];
        $studioName = trim((string) ($studio['name'] ?? '')) !== '' ? $studio['name'] : 'Estudio musical';
        try {
            $timezone = new DateTimeZone((string) ($data['user_timezone'] ?? $studio['timezone'] ?? 'UTC'));
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }
        $date = static function (mixed $value) use ($timezone): string {
            if (!is_string($value) || trim($value) === '') {
                return 'Fecha no disponible';
            }
            try {
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                    ->setTimezone($timezone)->format('d/m/Y H:i');
            } catch (Throwable) {
                return 'Fecha no disponible';
            }
        };
        // Whitelist fields in the template: never serialize the DTO or embed it as JSON/metadata.
        $summary = $data['financial_summary'] ?? [];
        $currency = $data['currency'] ?? 'MXN';
        $money = static fn (mixed $value): string => $escape($value ?? 'No disponible') . ' ' . $escape($currency);
        $id = (string) ($data['id'] ?? '');
        $ticketUrl = preg_match('/^[1-9][0-9]*$/D', $id) === 1 ? '/api/v1/sales/' . $id . '/ticket' : null;
        $level = ob_get_level();
        ob_start();
        try {
            require $this->templatesDir . '/sale-ticket.php';
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
