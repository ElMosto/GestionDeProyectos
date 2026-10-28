<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures;

/** §8, D-02, §19.B — commercial charges are not payments or expenses. */
final class ProjectChargesApiTest extends DatabaseTestCase
{
    private ProjectFixtures $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new ProjectFixtures($this->fixtures);
    }

    private static function chargeBody(array $overrides = []): array
    {
        return array_replace([
            'concept' => 'Cargo adicional', 'quantity' => '1.000', 'unit_price' => '500.00',
            'charge_date' => '2026-11-05',
        ], $overrides);
    }

    public function test_B_interno_mil_mas_cargo_quinientos_son_mil_quinientos_sin_cobro(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id'], ['base_amount' => '1000.00']);
        $path = '/api/v1/projects/' . $project['id'];
        $charge = ProjectFixtures::created(Api::post($path . '/charges', self::chargeBody(), $token, ProjectFixtures::key()));
        $this->assertSame('500.00', $charge['amount']);
        $result = Api::get($path, bearer: $token);
        $this->assertSame(200, $result->status);
        $this->assertSame('1500.00', $result->payload['data']['financial_summary']['total']);
        $this->assertFalse($result->payload['data']['billable']);
        $payments = Api::get($path . '/payments', bearer: $token);
        $this->assertSame(200, $payments->status);
        $this->assertSame([], $payments->payload['data']);
        $this->assertSame('0.00', $payments->payload['meta']['summary']['net_paid']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        // Accounts-receivable aggregates belong to the future finance dashboard, not a fabricated phase-2 endpoint.
    }

    public function test_B_gasto_doscientos_no_aumenta_importe_comercial(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id'], ['base_amount' => '1000.00']);
        ProjectFixtures::expense($this->pdo, $project['id'], $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        ProjectFixtures::created(Api::post($path . '/charges', self::chargeBody(), $token, ProjectFixtures::key()));
        $result = Api::get($path, bearer: $token);
        $this->assertSame(200, $result->status);
        $this->assertSame('1500.00', $result->payload['data']['financial_summary']['total']);
        $this->assertSame('200.00', $this->pdo->query('SELECT amount FROM expenses')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_B_cargo_fraccionario_redondea_por_partida_sin_float(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $three = ProjectFixtures::created(Api::post($path . '/charges', self::chargeBody([
            'quantity' => '3.000', 'unit_price' => '0.10',
        ]), $token, ProjectFixtures::key()));
        $this->assertSame('0.30', $three['amount']);
        // Each 0.5 × 0.01 line rounds to 0.01; summing before rounding would lose a cent.
        for ($i = 0; $i < 2; $i++) {
            $half = ProjectFixtures::created(Api::post($path . '/charges', self::chargeBody([
                'quantity' => '0.500', 'unit_price' => '0.01',
            ]), $token, ProjectFixtures::key()));
            $this->assertSame('0.01', $half['amount']);
        }
        $this->assertSame('0.32', Api::get($path, bearer: $token)->payload['data']['financial_summary']['total']);
    }

    public function test_A_colaborador_miembro_no_crea_ni_edita_ni_consulta_cargos(): void
    {
        [$leader, $token] = $this->projects->actor();
        [$member, $memberToken] = $this->projects->actor(Role::COLABORADOR);
        $project = $this->projects->project($token, $leader['id']);
        $this->projects->members($token, $project['id'], [$member['id']]);
        $path = '/api/v1/projects/' . $project['id'] . '/charges';
        $charge = ProjectFixtures::created(Api::post($path, self::chargeBody(), $token, ProjectFixtures::key()));
        $this->assertSame(403, Api::get($path, bearer: $memberToken)->status);
        $this->assertSame(403, Api::post($path, self::chargeBody(), $memberToken, ProjectFixtures::key())->status);
        $this->assertSame(403, Api::patch('/api/v1/project-charges/' . $charge['id'], [
            'unit_price' => '1.00', 'version' => $charge['version'],
        ], $memberToken, ProjectFixtures::key())->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM project_charges')->fetchColumn());
    }

    public function test_A_lider_no_registra_cargo_en_proyecto_ajeno(): void
    {
        [$leader, $token] = $this->projects->actor();
        [, $foreignToken] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'] . '/charges';
        $this->assertSame(403, Api::post($path, self::chargeBody(), $foreignToken, ProjectFixtures::key())->status);
        $this->assertSame(403, Api::get($path, bearer: $foreignToken)->status);
    }

    public function test_B_cargo_rechaza_cantidad_no_positiva_precio_negativo_y_float_json(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'] . '/charges';
        foreach ([['quantity' => '0.000'], ['quantity' => '-1.000'], ['unit_price' => '-0.01'], ['unit_price' => 0.1]] as $invalid) {
            $result = Api::post($path, self::chargeBody($invalid), $token, ProjectFixtures::key());
            $this->assertSame(422, $result->status, json_encode($result->payload));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM project_charges')->fetchColumn());
    }

    public function test_B_editar_cargo_recalcula_y_version_obsoleta_no_duplica_valor(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id'], ['base_amount' => '1000.00']);
        $path = '/api/v1/projects/' . $project['id'];
        $charge = ProjectFixtures::created(Api::post($path . '/charges', self::chargeBody(), $token, ProjectFixtures::key()));
        $chargePath = '/api/v1/project-charges/' . $charge['id'];
        $updated = Api::patch($chargePath, ['quantity' => '2.000', 'version' => $charge['version']], $token, ProjectFixtures::key());
        $this->assertSame(200, $updated->status, json_encode($updated->payload));
        $this->assertSame('1000.00', $updated->payload['data']['amount']);
        $this->assertSame(409, Api::patch($chargePath, ['quantity' => '3.000', 'version' => $charge['version']], $token, ProjectFixtures::key())->status);
        $this->assertSame('2000.00', Api::get($path, bearer: $token)->payload['data']['financial_summary']['total']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM project_charges')->fetchColumn());
    }

    public function test_B_cargo_idempotente_repite_respuesta_y_rechaza_cuerpo_distinto(): void
    {
        [$leader, $token] = $this->projects->actor();
        $project = $this->projects->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'] . '/charges';
        $headers = ProjectFixtures::key();
        $first = Api::post($path, self::chargeBody(), $token, $headers);
        $this->assertSame(201, $first->status);
        $repeat = Api::post($path, self::chargeBody(), $token, $headers);
        $this->assertSame(201, $repeat->status);
        $this->assertSame($first->payload['data'], $repeat->payload['data']);
        $conflict = Api::post($path, self::chargeBody(['unit_price' => '600.00']), $token, $headers);
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->payload['error']['code']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM project_charges')->fetchColumn());
    }
}
