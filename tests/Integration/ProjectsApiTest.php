<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures;

/** §5, §8, §19.A/B — real router, middleware, PDO and MariaDB; no mocked policies. */
final class ProjectsApiTest extends DatabaseTestCase
{
    private ProjectFixtures $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new ProjectFixtures($this->fixtures);
    }

    public function test_B_externo_requiere_cliente_y_precio_positivo(): void
    {
        [$leader, $token] = $this->projects->actor();
        $client = $this->fixtures->createClient($leader['id']);
        foreach ([
            ['client_id' => null, 'base_amount' => '100.00'],
            ['client_id' => (string) $client['id'], 'base_amount' => '0.00'],
            ['client_id' => (string) $client['id'], 'base_amount' => '-1.00'],
        ] as $invalid) {
            $result = Api::post('/api/v1/projects', ProjectFixtures::body($leader['id'], [
                ...$invalid, 'type' => 'EXTERNO', 'billable' => true,
            ]), $token, ProjectFixtures::key());
            $this->assertSame(422, $result->status, json_encode($result->payload));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }

    public function test_B_interno_cero_es_valido_y_contrato_conserva_strings(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $this->assertSame('0.00', $project['base_amount']);
        $this->assertIsString($project['version']);
        $this->assertSame('INTERNO', $project['type']);
    }

    public function test_B_cliente_inactivo_no_admite_nuevo_proyecto(): void
    {
        [$leader, $token] = $this->projects->actor();
        $client = $this->fixtures->createClient($leader['id'], ['active' => 0]);
        $result = Api::post('/api/v1/projects', ProjectFixtures::body($leader['id'], [
            'type' => 'EXTERNO', 'client_id' => (string) $client['id'],
            'base_amount' => '10.00', 'billable' => true,
        ]), $token, ProjectFixtures::key());
        $this->assertSame(422, $result->status);
    }

    public function test_B_lider_no_crea_bajo_otro_lider_ni_edita_proyecto_ajeno(): void
    {
        [$leader, $token] = $this->projects->actor();
        [$other, $otherToken] = $this->projects->actor();
        $project = $this->projects->project($otherToken, $other['id']);
        $this->assertSame(403, Api::post('/api/v1/projects', ProjectFixtures::body($other['id']), $token, ProjectFixtures::key())->status);
        $this->assertSame(403, Api::patch('/api/v1/projects/' . $project['id'], [
            'name' => 'Intrusión', 'version' => $project['version'],
        ], $token)->status);
        $this->assertSame($project['name'], Api::get('/api/v1/projects/' . $project['id'], bearer: $otherToken)->payload['data']['name']);
    }

    public function test_A_colaborador_no_accede_a_pagos_de_proyecto_ajeno(): void
    {
        [$leader, $token] = $this->projects->actor();
        [, $outsider] = $this->projects->actor(Role::COLABORADOR);
        $project = $this->projects->project($token, $leader['id'], ['base_amount' => '123456.78']);
        foreach (['', '/payments', '/charges'] as $suffix) {
            $result = Api::get('/api/v1/projects/' . $project['id'] . $suffix, bearer: $outsider);
            $this->assertContains($result->status, [403, 404]);
            $this->assertStringNotContainsString('123456.78', json_encode($result->payload));
        }
    }

    public function test_A_miembro_ve_proyecto_sin_finanzas_y_lista_total_solo_membresias(): void
    {
        [$leader, $token] = $this->projects->actor();
        [$member, $memberToken] = $this->projects->actor(Role::COLABORADOR);
        $own = $this->projects->project($token, $leader['id'], ['base_amount' => '98765.43']);
        $this->projects->project($token, $leader['id'], ['base_amount' => '12345.67']);
        $this->projects->members($token, $own['id'], [$member['id']]);
        $detail = Api::get('/api/v1/projects/' . $own['id'], bearer: $memberToken);
        $list = Api::get('/api/v1/projects', ['limit' => '25'], $memberToken);
        $this->assertSame(200, $detail->status);
        $this->assertSame(200, $list->status);
        $this->assertSame([$own['id']], array_column($list->payload['data'], 'id'));
        $this->assertSame(1, (int) $list->payload['meta']['total']);
        foreach ([$detail->payload['data'], $list->payload['data'][0]] as $row) {
            foreach (['base_amount', 'total_amount', 'financial_summary', 'charges', 'payments', 'expenses', 'balance'] as $field) {
                $this->assertArrayNotHasKey($field, $row);
            }
        }
        foreach (['charges', 'payments'] as $resource) {
            $this->assertSame(403, Api::get('/api/v1/projects/' . $own['id'] . '/' . $resource, bearer: $memberToken)->status);
        }
    }

    public function test_B_colaborador_no_cambia_presupuesto_ni_alcance(): void
    {
        [$leader, $token] = $this->projects->actor();
        [$member, $memberToken] = $this->projects->actor(Role::COLABORADOR);
        $project = $this->projects->project($token, $leader['id'], ['base_amount' => '100.00']);
        $this->projects->members($token, $project['id'], [$member['id']]);
        $before = Api::get('/api/v1/projects/' . $project['id'], bearer: $token)->payload['data'];
        foreach ([['base_amount' => '1.00'], ['description' => 'Cambiar alcance']] as $changes) {
            $this->assertSame(403, Api::patch('/api/v1/projects/' . $project['id'], [
                ...$changes, 'version' => $before['version'],
            ], $memberToken, ProjectFixtures::key())->status);
        }
        $after = Api::get('/api/v1/projects/' . $project['id'], bearer: $token)->payload['data'];
        $this->assertSame($before, $after);
    }

    public function test_B_version_obsoleta_rechaza_sin_perder_cambios(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $first = Api::patch($path, ['name' => 'Versión vigente', 'version' => $project['version']], $token);
        $this->assertSame(200, $first->status);
        $stale = Api::patch($path, ['name' => 'Obsoleta', 'version' => $project['version']], $token);
        $this->assertSame(409, $stale->status);
        $this->assertSame('VERSION_CONFLICT', $stale->payload['error']['code']);
        $this->assertSame('Versión vigente', Api::get($path, bearer: $token)->payload['data']['name']);
    }

    public function test_B_etapa_y_cambio_de_padre_respetan_fechas_anidadas(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $this->assertSame(422, Api::post($path . '/stages', [
            'name' => 'Fuera', 'start_date' => '2026-10-31', 'end_date' => '2026-11-15',
        ], $token)->status);
        $this->projects->stage($token, $project['id']);
        $current = Api::get($path, bearer: $token)->payload['data'];
        $result = Api::patch($path, ['planned_end' => '2026-11-10', 'version' => $current['version']], $token);
        $this->assertSame(422, $result->status);
        $this->assertSame('2026-11-30', Api::get($path, bearer: $token)->payload['data']['planned_end']);
    }

    public function test_B_reordenar_conserva_ids_y_rechaza_ids_ajenos_y_duplicados(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $foreign = $this->projects->project($token, $leader['id']);
        $a = $this->projects->stage($token, $project['id']);
        $b = $this->projects->stage($token, $project['id']);
        $c = $this->projects->stage($token, $foreign['id']);
        $path = '/api/v1/projects/' . $project['id'];
        foreach ([[$a['id'], $a['id']], [$a['id'], $c['id']], [$b['id'], $a['id']]] as $index => $ids) {
            $current = Api::get($path, bearer: $token)->payload['data'];
            $result = Api::post($path . '/stages/reorder', ['stage_ids' => $ids, 'version' => $current['version']], $token);
            $this->assertSame($index < 2 ? 422 : 200, $result->status, json_encode($result->payload));
        }
        $this->assertSame([$b['id'], $a['id']], array_column(Api::get($path . '/stages', bearer: $token)->payload['data'], 'id'));
    }

    public function test_H_busqueda_no_interpreta_inyeccion_sql(): void
    {
        [$leader, $token] = $this->projects->actor();
        $this->projects->project($token, $leader['id']);
        $result = Api::get('/api/v1/projects', ['q' => "' OR 1=1 --"], $token);
        $this->assertSame(200, $result->status);
        $this->assertSame([], $result->payload['data']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }

    public function test_B_if_match_soporta_edicion_y_rechaza_version_antigua(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $headers = ['If-Match' => '"' . $project['version'] . '"'];
        $result = Api::patch($path, ['name' => 'If-Match vigente'], $token, $headers);
        $this->assertSame(200, $result->status);
        $this->assertSame(409, Api::patch($path, ['name' => 'If-Match obsoleto'], $token, $headers)->status);
        $this->assertSame('If-Match vigente', Api::get($path, bearer: $token)->payload['data']['name']);
    }
}
