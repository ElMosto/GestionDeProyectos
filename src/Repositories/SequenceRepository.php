<?php

declare(strict_types=1);

namespace App\Repositories;

final class SequenceRepository extends ProjectStorage
{
    public function nextProject(): string
    {
        $year = gmdate('Y');
        $name = 'project.' . $year;
        $this->execute("INSERT INTO sequences (name,prefix,next_value) VALUES (?,'PRY',1) ON DUPLICATE KEY UPDATE name=VALUES(name)", [$name]);
        $row = $this->one('SELECT next_value FROM sequences WHERE name=? FOR UPDATE', [$name]);
        $this->execute('UPDATE sequences SET last_used=next_value,next_value=next_value+1 WHERE name=?', [$name]);
        return 'PRY-' . $year . '-' . str_pad((string) $row['next_value'], 6, '0', STR_PAD_LEFT);
    }
}
