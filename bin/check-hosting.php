<?php

declare(strict_types=1);

namespace App\HostingCheck;

// §18 / §19.H: inventario CLI de solo lectura, nunca un diagnóstico público.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @return array<string, array{required: bool, present: bool}> */
function extensionRequirements(array $composerRequirements, array $loaded): array
{
    $loaded = array_map('strtolower', $loaded);
    $result = [];
    foreach (array_keys($composerRequirements) as $name) {
        if (str_starts_with($name, 'ext-')) {
            $extension = substr($name, 4);
            $result[$extension] = ['required' => true, 'present' => in_array($extension, $loaded, true)];
        }
    }
    ksort($result);

    return $result;
}

function requirementsMet(string $version, array $extensions, array $artifacts): bool
{
    if (version_compare($version, '8.4.0', '<') || version_compare($version, '9.0.0', '>=')) {
        return false;
    }
    foreach ($extensions as $extension) {
        if ($extension['required'] && !$extension['present']) {
            return false;
        }
    }
    return !in_array(false, $artifacts, true);
}

/** Does not require config.php, autoload, or a database connection. */
function inventory(string $root): array
{
    $manifest = json_decode((string) @file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $extensions = extensionRequirements($manifest['require'], get_loaded_extensions());
    $artifacts = [
        'vendor_autoload_present' => is_file($root . '/vendor/autoload.php'),
        'composer_lock_present' => is_file($root . '/composer.lock'),
        'config_file_present' => is_file($root . '/config/config.php'),
        'storage_private_writable' => is_dir($root . '/storage/private') && is_writable($root . '/storage/private'),
        'storage_logs_writable' => is_dir($root . '/storage/logs') && is_writable($root . '/storage/logs'),
    ];
    $limits = [];
    foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size',
        'display_errors', 'log_errors', 'session.cookie_secure', 'session.cookie_httponly',
        'session.cookie_samesite', 'session.use_strict_mode', 'session.use_only_cookies'] as $name) {
        $limits[$name] = ini_get($name);
    }

    return [
        'schema_version' => 1,
        'status' => requirementsMet(PHP_VERSION, $extensions, $artifacts) ? 'manual_verification_required' : 'requirements_failed',
        'hosting_certified' => false,
        'runtime' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'timezone' => date_default_timezone_get()],
        'requirements' => [
            'php' => ['constraint' => $manifest['require']['php'], 'met' => version_compare(PHP_VERSION, '8.4.0', '>=') && version_compare(PHP_VERSION, '9.0.0', '<')],
            'extensions' => $extensions,
            'artifacts' => $artifacts,
        ],
        'optional' => ['intl' => ['required' => false, 'present' => extension_loaded('intl')]],
        'cli_ini_metadata' => $limits,
        'unknowns' => [
            'apache_document_root' => 'requires_manual_verification',
            'apache_rewrite_headers_htaccess' => 'requires_manual_verification',
            'https_certificate_redirect' => 'requires_manual_verification',
            'web_sapi_limits_and_session_cookies' => 'requires_manual_verification',
            'private_configuration_cookie_secure_debug_paths' => 'not_loaded_requires_manual_verification',
            'custom_storage_paths' => 'not_loaded_only_repository_default_paths_checked',
            'database_version_constraints_and_privileges' => 'not_connected_requires_manual_verification',
            'cron_quotas_external_backups' => 'requires_provider_inventory',
            'performance_memory_time_adequacy' => 'requires_agreed_dataset_and_web_measurement',
        ],
        'warnings' => [
            'CLI INI values are not web SAPI values; SessionManager sets cookie flags at runtime.',
            'Writable checks inspect metadata only; no file was created and config.php was not loaded.',
            'Run composer check-platform-reqs --no-dev separately for locked transitive requirements.',
        ],
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_slice($_SERVER['argv'], 1);
    if ($arguments === ['--help']) {
        fwrite(STDOUT, "Uso: php bin/check-hosting.php [--json]\nSolo lectura; JSON por defecto. Salida 0: comprobaciones CLI satisfechas, NO certifica hosting; 1: requisito ausente; 2: uso/error de inventario.\n");
        exit(0);
    }
    if ($arguments !== [] && $arguments !== ['--json']) {
        fwrite(STDOUT, "{\"schema_version\":1,\"status\":\"invalid_arguments\",\"hosting_certified\":false}\n");
        exit(2);
    }
    try {
        $report = inventory(dirname(__DIR__));
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit($report['status'] === 'requirements_failed' ? 1 : 0);
    } catch (\Throwable) {
        // Never print exception details: a path or private value could be included.
        fwrite(STDOUT, "{\"schema_version\":1,\"status\":\"inventory_error\",\"hosting_certified\":false}\n");
        exit(2);
    }
}
