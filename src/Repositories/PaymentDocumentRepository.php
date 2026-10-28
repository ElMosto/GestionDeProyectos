<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentDocumentRepository extends ProjectStorage
{
    public function find(string $kind, int $id, bool $lock = false): ?array
    {
        $table = self::table($kind);
        $row = $this->one('SELECT * FROM ' . $table . ' WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($row !== null && $kind === 'project') {
            $row['charge_amounts'] = $this->rows("SELECT amount FROM project_charges WHERE project_id=? AND status='ACTIVO' ORDER BY id"
                . ($lock ? ' FOR UPDATE' : ''), [$id]);
        }
        return $row;
    }

    public function clientActive(int $id): bool
    {
        $row = $this->one('SELECT active FROM clients WHERE id=? LOCK IN SHARE MODE', [$id]);
        return $row !== null && (bool) $row['active'];
    }

    public function recordLedgerChange(string $kind, int $id): void
    {
        $this->execute('UPDATE ' . self::table($kind) . ' SET version=version+1 WHERE id=?', [$id]);
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'sale' => 'sales',
            'project' => 'projects',
            default => throw new \InvalidArgumentException('Documento financiero desconocido.'),
        };
    }
}
