<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\Core\ApiException;
use App\Validation\ClientValidation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validación de clientes (§7, §15, §16).
 *
 * La regla BANDA (representante + teléfono) es la del §7; la versión obligatoria
 * es el bloqueo optimista del §15.
 */
final class ClientValidationTest extends TestCase
{
    /**
     * @param callable():mixed $action
     */
    private function capture(callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return $exception;
        }

        $this->fail('Se esperaba una ApiException y no se lanzó.');
    }

    public function test_A_banda_without_representative_and_phone_is_rejected(): void
    {
        // §19.A / §7 — «para bandas, registrar nombre de representante y teléfono».
        $exception = $this->capture(
            static fn () => ClientValidation::create(['type' => 'BANDA', 'name' => 'Los Sin Datos'])
        );

        $this->assertSame(422, $exception->status);
        $this->assertSame('VALIDATION_FAILED', $exception->errorCode);
        $this->assertArrayHasKey('representative_name', $exception->fields);
        $this->assertArrayHasKey('phone', $exception->fields);
    }

    public function test_A_banda_without_phone_only_points_at_the_missing_field(): void
    {
        // §7 — el mensaje debe ser accionable: solo falta el teléfono.
        $exception = $this->capture(static fn () => ClientValidation::create([
            'type' => 'BANDA',
            'name' => 'Banda Completa',
            'representative_name' => 'Ana Representante',
        ]));

        $this->assertArrayNotHasKey('representative_name', $exception->fields);
        $this->assertArrayHasKey('phone', $exception->fields);
    }

    public function test_A_banda_with_representative_and_phone_is_accepted(): void
    {
        // §7 — BANDA válida.
        $input = ClientValidation::create([
            'type' => 'BANDA',
            'name' => 'Banda Válida',
            'representative_name' => 'Representante',
            'phone' => '5551234567',
        ]);

        $this->assertSame('BANDA', $input['type']);
        $this->assertSame('Representante', $input['representative_name']);
        $this->assertSame('5551234567', $input['phone']);
    }

    public function test_A_normal_client_does_not_require_representative_or_phone(): void
    {
        // §7 — los campos de banda son opcionales para NORMAL.
        $input = ClientValidation::create(['name' => 'Cliente Normal']);

        $this->assertSame('NORMAL', $input['type']);
        $this->assertNull($input['representative_name']);
        $this->assertNull($input['phone']);
    }

    public function test_A_email_is_normalized_to_lowercase_and_trimmed(): void
    {
        // §7 / §16 — normalización en servidor para que el duplicado sea detectable.
        $input = ClientValidation::create([
            'name' => 'Cliente Correo',
            'email' => '  Cliente.Prueba@TEST.Local ',
        ]);

        $this->assertSame('cliente.prueba@test.local', $input['email']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidEmails(): array
    {
        return [
            'sin arroba' => ['no-es-correo'],
            'dos arrobas' => ['a@@b.com'],
            'inyección' => ["' OR 1=1 --"],
            'matriz' => [['a@b.com']],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function test_A_invalid_client_email_is_rejected(mixed $email): void
    {
        // §7 —— el correo es opcional, pero si viene debe ser válido.
        $exception = $this->capture(static fn () => ClientValidation::create([
            'name' => 'Cliente',
            'email' => $email,
        ]));

        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey('email', $exception->fields);
    }

    public function test_A_notes_accept_multiline_text(): void
    {
        // §7 — las notas pueden incluir los integrantes de la banda en varias líneas.
        $notes = "Integrantes:\n- Ana (voz)\n- Luis (guitarra)\r\n- Sol (batería)";

        $input = ClientValidation::create(['name' => 'Banda Notas', 'notes' => $notes]);

        $this->assertSame($notes, $input['notes']);
    }

    public function test_A_notes_reject_control_characters_other_than_line_breaks(): void
    {
        // §16 — se rechazan caracteres de control, no se guardan a medias.
        $exception = $this->capture(static fn () => ClientValidation::create([
            'name' => 'Banda Control',
            'notes' => "Nota con campana\x07",
        ]));

        $this->assertArrayHasKey('notes', $exception->fields);
    }

    public function test_A_create_rejects_server_assigned_fields(): void
    {
        // §16 — asignación masiva: el cliente no decide id, estado, creador ni versión.
        $exception = $this->capture(static fn () => ClientValidation::create([
            'name' => 'Cliente Intruso',
            'id' => 1,
            'active' => 0,
            'created_by' => 1,
            'version' => 99,
            'created_at' => '2020-01-01 00:00:00',
        ]));

        foreach (['id', 'active', 'created_by', 'version', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $exception->fields, 'Debe rechazarse el campo ' . $field);
        }
    }

    public function test_A_unknown_fields_are_ignored_on_create(): void
    {
        // §16 — un campo desconocido no entra al modelo ni rompe la petición.
        $input = ClientValidation::create([
            'name' => 'Cliente Desconocido',
            'campo_inventado' => 'x',
            'owner' => 5,
        ]);

        $this->assertArrayNotHasKey('campo_inventado', $input);
        $this->assertArrayNotHasKey('owner', $input);
    }

    public function test_A_client_name_is_required_and_bounded(): void
    {
        // §7 — el nombre es obligatorio (160 caracteres en el esquema).
        $empty = $this->capture(static fn () => ClientValidation::create(['name' => '   ']));
        $this->assertArrayHasKey('name', $empty->fields);

        $tooLong = $this->capture(static fn () => ClientValidation::create(['name' => str_repeat('a', 161)]));
        $this->assertArrayHasKey('name', $tooLong->fields);

        $trimmed = ClientValidation::create(['name' => '  Nombre con espacios  ']);
        $this->assertSame('Nombre con espacios', $trimmed['name']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidVersions(): array
    {
        return [
            'ausente' => [null],
            'cero' => [0],
            'negativa' => [-3],
            'texto' => ['abc'],
            'vacía' => [''],
            'decimal' => ['1.5'],
            'booleana' => [true],
        ];
    }

    #[DataProvider('invalidVersions')]
    public function test_A_update_requires_a_positive_version(mixed $version): void
    {
        // §15 — bloqueo optimista: sin versión no se puede editar.
        $body = ['phone' => '5550000000'];

        if ($version !== null) {
            $body['version'] = $version;
        }

        $exception = $this->capture(static fn () => ClientValidation::update($body));

        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey('version', $exception->fields);
    }

    public function test_A_update_accepts_version_as_string_of_digits(): void
    {
        // §15 — el cliente puede enviar la versión como cadena numérica.
        $result = ClientValidation::update(['phone' => '5550000001', 'version' => '4']);

        $this->assertSame(4, $result['expected_version']);
        $this->assertSame(['phone' => '5550000001'], $result['fields']);
    }

    public function test_A_update_rejects_read_only_fields(): void
    {
        // §16 — estado, creador y fechas no se tocan desde el PATCH.
        $exception = $this->capture(static fn () => ClientValidation::update([
            'version' => 1,
            'active' => 0,
            'created_by' => 2,
            'created_at' => '2020-01-01 00:00:00',
        ]));

        foreach (['active', 'created_by', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $exception->fields);
        }
    }

    public function test_A_list_query_applies_defaults_and_whitelists_sorting(): void
    {
        // §15 — limit 25 por defecto, 100 máximo, orden por lista blanca.
        $defaults = ClientValidation::listQuery([]);

        $this->assertSame(25, $defaults['limit']);
        $this->assertSame(0, $defaults['offset']);
        $this->assertSame('name', $defaults['sort']);
        $this->assertSame('asc', $defaults['direction']);
        $this->assertNull($defaults['q']);
        $this->assertNull($defaults['type']);
        $this->assertNull($defaults['active']);

        $clamped = ClientValidation::listQuery([
            'limit' => '1000',
            'offset' => '-4',
            'sort' => 'password_hash',
            'direction' => 'lateral',
            'active' => '1',
            'type' => 'BANDA',
        ]);

        $this->assertSame(100, $clamped['limit']);
        $this->assertSame(0, $clamped['offset']);
        $this->assertSame('name', $clamped['sort']);
        $this->assertSame('asc', $clamped['direction']);
        $this->assertSame(1, $clamped['active']);
        $this->assertSame('BANDA', $clamped['type']);
    }

    public function test_A_list_query_rejects_unknown_type_and_active(): void
    {
        // §15 — los filtros tipados sí fallan con 422, no se ignoran.
        $type = $this->capture(static fn () => ClientValidation::listQuery(['type' => 'VIP']));
        $this->assertArrayHasKey('type', $type->fields);

        $active = $this->capture(static fn () => ClientValidation::listQuery(['active' => '2']));
        $this->assertArrayHasKey('active', $active->fields);
    }

    public function test_A_search_term_is_trimmed_and_capped(): void
    {
        // §16 — una búsqueda desmedida se recorta, nunca se concatena sin control.
        $query = ClientValidation::listQuery(['q' => '   ']);
        $this->assertNull($query['q']);

        $long = ClientValidation::listQuery(['q' => str_repeat('x', 500)]);
        $this->assertSame(120, mb_strlen($long['q']));
    }

    public function test_A_client_id_rejects_non_numeric_values(): void
    {
        // §15 — un id inválido es 404, no un error 500.
        $this->assertSame(12, ClientValidation::id('12'));

        foreach (['abc', '0', '-1', '1 OR 1=1', ''] as $raw) {
            $exception = $this->capture(static fn () => ClientValidation::id($raw));
            $this->assertSame(404, $exception->status);
        }
    }
}
