<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\CatalogRepository;
use App\Support\Money;

/** §10: resolución de catálogo y fórmula única; snapshots definitivos solo al emitir. */
final class SaleCalculatorService
{
    public function __construct(private readonly CatalogRepository $catalog, private readonly Authorizer $authorizer)
    {
    }

    public function calculate(array $items, bool $lock = false, bool $newInput = false, ?array $selection = null): array
    {
        $productIds = [];
        $extraIds = [];
        foreach ($items as $item) {
            if ($item['product_id'] !== null) {
                $productIds[] = (int) $item['product_id'];
            } elseif ($newInput) {
                $this->authorizer->requireAdmin();
            }
            foreach ($item['extras'] as $extra) {
                $extraIds[] = (int) $extra['extra_id'];
            }
        }
        $selection ??= $this->catalog->selection($productIds, $extraIds, $lock);
        $total = '0.00';
        foreach ($items as $sort => &$item) {
            if ($item['product_id'] !== null) {
                $product = $selection['products'][(int) $item['product_id']] ?? null;
                if ($product === null || !$product['active']) {
                    throw ApiException::validation(['items' => 'Selecciona productos activos.']);
                }
                $item['name_snapshot'] = $product['name'];
                $item['unit_price'] = $product['price'];
            } else {
                if ($item['extras'] !== []) {
                    throw ApiException::validation(['extras' => 'Los conceptos libres no admiten extras.']);
                }
                $item['name_snapshot'] = $item['name_snapshot'] ?? $item['name'];
            }
            foreach ($item['extras'] as &$extra) {
                $current = $selection['extras'][(int) $extra['extra_id']] ?? null;
                if ($current === null || !$current['active']
                    || !in_array((int) $extra['extra_id'], $selection['links'][(int) $item['product_id']] ?? [], true)) {
                    throw ApiException::validation(['extras' => 'Selecciona extras activos vinculados al producto.']);
                }
                $extra['name_snapshot'] = $current['name'];
                $extra['unit_price'] = $current['price'];
            }
            unset($extra);
            $item['line_total'] = Money::saleLineTotal($item['quantity'], $item['unit_price'], $item['extras']);
            $item['sort_order'] = $sort;
            unset($item['name']);
            $total = Money::amount(Money::add($total, $item['line_total']));
        }
        unset($item);
        return ['items' => $items, 'total' => $total];
    }

    public function calculateBatch(array $itemsBySale): array
    {
        $products = [];
        $extras = [];
        foreach ($itemsBySale as $items) {
            foreach ($items as $item) {
                if ($item['product_id'] !== null) {
                    $products[] = (int) $item['product_id'];
                }
                foreach ($item['extras'] as $extra) {
                    $extras[] = (int) $extra['extra_id'];
                }
            }
        }
        $selection = $this->catalog->selection($products, $extras, false);
        $out = [];
        foreach ($itemsBySale as $id => $items) {
            $out[$id] = $this->calculate($items, false, false, $selection);
        }
        return $out;
    }
}
