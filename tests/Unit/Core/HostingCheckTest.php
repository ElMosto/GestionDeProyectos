<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

use function App\HostingCheck\extensionRequirements;
use function App\HostingCheck\requirementsMet;

require_once dirname(__DIR__, 3) . '/bin/check-hosting.php';

final class HostingCheckTest extends TestCase
{
    public function test_H_extensions_follow_manifest_and_normalize_runtime_names(): void
    {
        self::assertSame([
            'pdo_mysql' => ['required' => true, 'present' => false],
            'simplexml' => ['required' => true, 'present' => true],
        ], extensionRequirements(['php' => '^8.4', 'ext-simplexml' => '*', 'ext-pdo_mysql' => '*'], ['SimpleXML']));
    }

    public function test_H_missing_requirement_or_unsupported_php_fails_without_database(): void
    {
        $present = ['pdo_mysql' => ['required' => true, 'present' => true]];
        self::assertTrue(requirementsMet('8.4.27', $present, ['vendor' => true]));
        self::assertTrue(requirementsMet('8.5.0', $present, ['vendor' => true]));
        self::assertFalse(requirementsMet('8.3.99', $present, ['vendor' => true]));
        self::assertFalse(requirementsMet('9.0.0', $present, ['vendor' => true]));
        self::assertFalse(requirementsMet('8.4.27', $present, ['vendor' => false]));
        self::assertFalse(requirementsMet('8.4.27', ['gd' => ['required' => true, 'present' => false]], []));
    }

    public function test_H_cli_json_is_stable_and_never_certifies_hosting(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/check-hosting.php', '--json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $errors);
        self::assertSame(1, $report['schema_version']);
        self::assertFalse($report['hosting_certified']);
        self::assertSame($report['status'] === 'requirements_failed' ? 1 : 0, $exit);
        self::assertSame('not_loaded_requires_manual_verification', $report['unknowns']['private_configuration_cookie_secure_debug_paths']);
        self::assertArrayNotHasKey('db', $report);
        self::assertArrayNotHasKey('environment', $report);
        self::assertArrayHasKey('pdo_mysql', $report['requirements']['extensions']);
    }
}
