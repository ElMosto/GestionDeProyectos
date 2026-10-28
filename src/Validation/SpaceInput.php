<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;

final class SpaceInput
{
    public static function fields(array $body, bool $update = false): array
    {
        $block = $body['block_minutes'] ?? null;
        $hasBlock = array_key_exists('block_minutes', $body);
        unset($body['block_minutes']);
        $out = ProjectInput::fields($body,
            ['name' => 'text120', 'description' => '?text10000', 'hourly_rate' => 'money', 'active' => 'bool'],
            $update ? [] : ['name', 'hourly_rate'], $update);
        if ($hasBlock) {
            if (!is_int($block) || $block < 1 || $block > 1440) {
                throw ApiException::validation(['block_minutes' => 'Envía minutos enteros entre 1 y 1440.']);
            }
            $out['block_minutes'] = $block;
        }
        return $out;
    }

    public static function hours(array $body): array
    {
        $hours = $body['hours'] ?? null;
        unset($body['hours']);
        ProjectInput::fields($body, [], [], true);
        if (!is_array($hours) || !array_is_list($hours) || count($hours) > 100) {
            throw ApiException::validation(['hours' => 'Envía hasta 100 ventanas semanales.']);
        }
        $out = [];
        foreach ($hours as $hour) {
            if (!is_array($hour) || array_diff(array_keys($hour), ['weekday', 'local_start_time', 'local_end_time']) !== []
                || !isset($hour['weekday'], $hour['local_start_time'], $hour['local_end_time'])
                || !is_int($hour['weekday']) || $hour['weekday'] < 1 || $hour['weekday'] > 7
                || !is_string($hour['local_start_time']) || !is_string($hour['local_end_time'])
                || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d:00\z/', $hour['local_start_time']) !== 1
                || preg_match('/\A(?:(?:[01]\d|2[0-3]):[0-5]\d:00|24:00:00)\z/', $hour['local_end_time']) !== 1
                || $hour['local_end_time'] <= $hour['local_start_time']) {
                throw ApiException::validation(['hours' => 'Ventana semanal no válida; divide los cruces de medianoche.']);
            }
            $out[] = $hour;
        }
        usort($out, static fn (array $a, array $b): int => [$a['weekday'], $a['local_start_time']] <=> [$b['weekday'], $b['local_start_time']]);
        $previous = null;
        foreach ($out as $hour) {
            if ($previous !== null && $previous['weekday'] === $hour['weekday'] && $previous['local_end_time'] > $hour['local_start_time']) {
                throw ApiException::validation(['hours' => 'Las ventanas semanales no pueden solaparse.']);
            }
            $previous = $hour;
        }
        return $out;
    }
}
