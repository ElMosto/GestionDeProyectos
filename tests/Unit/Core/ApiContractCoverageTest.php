<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** §15, D-09 — cada ruta API registrada debe figurar en el contrato Android/web. */
final class ApiContractCoverageTest extends TestCase
{
    public function test_H_every_registered_api_operation_is_documented_once(): void
    {
        // Esta comprobación cubre rutas/operaciones; Redocly valida la sintaxis
        // OpenAPI completa. No pretende implementar un parser YAML alternativo.
        $yaml = file_get_contents(APP_ROOT . '/docs/openapi.yaml');
        $this->assertIsString($yaml);
        $operations = [];
        $path = null;
        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $match) === 1) {
                $path = $match[1];
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $match) === 1) {
                $key = strtoupper($match[1]) . ' ' . $path;
                $this->assertArrayNotHasKey($key, $operations, 'Operación duplicada en OpenAPI: ' . $key);
                $operations[$key] = true;
            } elseif ($line !== '' && $line[0] !== ' ') {
                $path = null;
            }
        }

        $files = array_merge([APP_ROOT . '/src/Core/routes.php'], glob(APP_ROOT . '/src/Core/routes/*.php') ?: []);
        $registered = [];
        foreach ($files as $file) {
            preg_match_all('/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'/', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (!str_starts_with($match[2], '/api/v1/')) {
                    continue;
                }
                $key = strtoupper($match[1]) . ' ' . substr($match[2], strlen('/api/v1'));
                $this->assertArrayNotHasKey($key, $registered, 'Ruta registrada dos veces: ' . $key);
                $registered[$key] = true;
                $this->assertArrayHasKey($key, $operations, 'Falta documentar en OpenAPI: ' . $key);
            }
        }
        $this->assertNotEmpty($registered);
    }
}
