<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures as F;

final class CatalogApiTest extends DatabaseTestCase
{
    public function test_D_catalogo_solo_admin_y_precios_siempre_strings(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $collaborator] = $f->actor(Role::COLABORADOR);
        $product = $f->product($admin);
        self::assertIsString($product['id']);
        self::assertSame('50.00', $product['price']);
        self::assertSame(403, Api::post('/api/v1/products', ['name' => 'Ataque', 'price' => '0.01'], $collaborator)->status);
        self::assertSame(403, Api::patch('/api/v1/products/' . $product['id'], [
            'price' => '0.01', 'version' => $product['version'],
        ], $collaborator)->status);
        self::assertSame(403, Api::post('/api/v1/extras', ['name' => 'Ataque', 'price' => '0.01'], $collaborator)->status);
    }

    public function test_D_catalogo_if_match_rechaza_version_obsoleta_y_sku_duplicado(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $product = $f->product($admin, overrides: ['sku' => 'UNICO']);
        $first = Api::patch('/api/v1/products/' . $product['id'], ['price' => '51.00'], $admin,
            ['If-Match' => '"' . $product['version'] . '"']);
        F::ok($first);
        $stale = Api::patch('/api/v1/products/' . $product['id'], ['price' => '52.00'], $admin,
            ['If-Match' => '"' . $product['version'] . '"']);
        self::assertSame(409, $stale->status);
        self::assertSame('VERSION_CONFLICT', $stale->errorCode());
        $duplicate = Api::post('/api/v1/products', ['sku' => 'UNICO', 'name' => 'Duplicado', 'price' => '1.00'], $admin);
        self::assertSame(409, $duplicate->status);
    }

    public function test_H_busqueda_con_sql_injection_no_retira_filtro(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $f->product($admin);
        $result = Api::get('/api/v1/products', ['q' => "' OR 1=1 --"], $admin);
        F::ok($result);
        self::assertSame([], $result->items());
    }

    public function test_D_colaborador_no_consulta_catalogo_inactivo(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [, $collaborator] = $f->actor(Role::COLABORADOR);
        $product = $f->product($admin, overrides: ['active' => false]);
        $response = Api::get('/api/v1/products/' . $product['id'], bearer: $collaborator);
        self::assertContains($response->status, [403, 404]);
        self::assertStringNotContainsString($product['name'], $response->json());
    }
}
