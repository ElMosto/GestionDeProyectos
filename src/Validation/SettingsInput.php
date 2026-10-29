<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Core\Request;

final class SettingsInput
{
    public static function fields(Request $request): array
    {
        $body = $request->body;
        $versions = [];
        if (array_key_exists('expected_version', $body)) {
            $versions[] = $body['expected_version'];
            unset($body['expected_version']);
        }
        if ($request->header('if-match') !== null) {
            if (preg_match('/\A"([a-f0-9]{64})"\z/', $request->header('if-match'), $m) !== 1) {
                throw ApiException::validation(['version' => 'Envía el digest entre comillas en If-Match.']);
            }
            $versions[] = $m[1];
        }
        if ($versions === [] || !is_string($versions[0]) || preg_match('/\A[a-f0-9]{64}\z/', $versions[0]) !== 1
            || count(array_unique($versions, SORT_REGULAR)) !== 1) {
            throw ApiException::validation(['expected_version' => 'Envía un digest SHA256 esperado coherente.']);
        }
        $fields = ProjectInput::fields($body, ['studio_name' => 'text160','timezone' => 'text80']);
        if ($fields === []) {
            throw ApiException::validation(['fields' => 'Envía un campo editable.']);
        }
        if (isset($fields['timezone']) && !in_array($fields['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw ApiException::validation(['timezone' => 'Usa una zona horaria IANA válida.']);
        }
        return $fields + ['expected_version' => $versions[0]];
    }
}
