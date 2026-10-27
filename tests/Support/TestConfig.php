<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Configuración de la aplicación con la base de pruebas inyectada.
 *
 * Lee `config/config.php` (no versionado) y sustituye **solo en memoria**
 * `db.database` por `gestion_test`. El archivo de configuración de desarrollo
 * nunca se escribe. También aísla el nombre de la cookie de sesión para que las
 * sesiones de PHPUnit no colisionen con las del navegador del desarrollador.
 */
final class TestConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        /** @var array<string, mixed> $values */
        $values = require APP_ROOT . '/config/config.php';

        $values['db']['database'] = TEST_DATABASE;
        $values['app']['env'] = 'test';

        $values['session']['name'] = 'gestion_test_session';
        $values['session']['cookie_secure'] = false;
        $values['paths']['storage'] = sys_get_temp_dir() . '/gestion-phpunit-storage';

        // El rate limit no debe hacer fallar una suite completa por acumulación.
        $values['security']['login_max_attempts'] = 5;
        $values['security']['login_window'] = 900;

        return $values;
    }

    public static function sessionName(): string
    {
        return (string) self::values()['session']['name'];
    }

    public static function config(): \App\Core\Config
    {
        return new \App\Core\Config(self::values());
    }
}
