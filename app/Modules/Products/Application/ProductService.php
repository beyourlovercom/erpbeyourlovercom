<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class ProductService
{
    public function __construct(private readonly ProductRepository $products) {}

    public function import(string $csvPath): ProductImportResult
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return new ProductImportResult(0, 0, 0, 1, ["Cannot open file: {$csvPath}"]);
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return new ProductImportResult(0, 0, 0, 1, ['Empty or unreadable CSV']);
        }

        $headerMap = array_flip(array_map('trim', $headers));
        $required = ['ID', 'Type', 'SKU', 'Name'];
        foreach ($required as $col) {
            if (! isset($headerMap[$col])) {
                fclose($handle);
                return new ProductImportResult(0, 0, 0, 1, ["Missing required column: {$col}"]);
            }
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $invalid = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $cell = fn (string $col): ?string => isset($headerMap[$col]) ? (trim($row[$headerMap[$col]] ?? '') ?: null) : null;

            $wooId = (int) ($cell('ID') ?? 0);
            if ($wooId <= 0) {
                $invalid++;
                $errors[] = "Row {$rowNum}: invalid or missing ID";
                continue;
            }

            $name = $cell('Name');
            if ($name === null || $name === '') {
                $invalid++;
                $errors[] = "Row {$rowNum}: missing Name";
                continue;
            }

            $sku = $cell('SKU');
            $gtin = $cell('GTIN, UPC, EAN, or ISBN');
            $type = $cell('Type') ?? 'simple';
            $weightRaw = $cell('Weight (kg)');
            $salePriceRaw = $cell('Sale price');
            $regularPriceRaw = $cell('Regular price');

            $attributes = [
                'product_type' => $type,
                'sku' => $sku,
                'gtin' => $gtin,
                'name' => $name,
                'weight_kg' => $weightRaw !== null ? (float) $weightRaw : null,
                'sale_price' => $salePriceRaw !== null ? (float) $salePriceRaw : null,
                'regular_price' => $regularPriceRaw !== null ? (float) $regularPriceRaw : null,
            ];

            $existing = $this->products->findByWooId($wooId);

            if ($existing === null) {
                $this->products->create(['woocommerce_product_id' => $wooId, 'source_type' => 'woocommerce', ...$attributes]);
                $inserted++;
            } else {
                $changed = false;
                foreach ($attributes as $key => $value) {
                    if ($existing->getAttribute($key) != $value) {
                        $changed = true;
                        break;
                    }
                }
                if ($changed) {
                    $this->products->update($existing, $attributes);
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        }

        fclose($handle);

        return new ProductImportResult($inserted, $updated, $skipped, $invalid, $errors);
    }

    public function searchSkus(string $query, int $limit = 20): array
    {
        return $this->products->searchSkus($query, $limit);
    }

    public function findById(int $id): ?Model
    {
        return $this->products->findById($id);
    }

    public function findBySku(string $sku): ?object
    {
        return $this->products->findBySku($sku);
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->products->list($filters, $perPage);
    }

    public function totalCount(): int
    {
        return $this->products->totalCount();
    }

    public function createManual(array $attributes): Model
    {
        $nullable = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? $attributes[$k] : null;
        $nullableFloat = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? (float) $attributes[$k] : null;

        return $this->products->create([
            'source_type' => 'manual',
            'woocommerce_product_id' => null,
            'product_type' => $attributes['product_type'] ?? 'simple',
            'sku' => $attributes['sku'] ?? null,
            'gtin' => $nullable('gtin'),
            'name' => $attributes['name'],
            'unit' => $nullable('unit'),
            'is_active' => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : true,
            'weight_kg' => $nullableFloat('weight_kg'),
            'sale_price' => $nullableFloat('sale_price'),
            'regular_price' => $nullableFloat('regular_price'),
            'shipping_weight_kg' => $nullableFloat('shipping_weight_kg'),
            'length_cm' => $nullableFloat('length_cm'),
            'width_cm' => $nullableFloat('width_cm'),
            'height_cm' => $nullableFloat('height_cm'),
            'country_of_origin' => $nullable('country_of_origin'),
            'hs_code' => $nullable('hs_code'),
            'standard_production_cost' => $nullableFloat('standard_production_cost'),
            'standard_packaging_cost' => $nullableFloat('standard_packaging_cost'),
            'standard_purchase_cost' => $nullableFloat('standard_purchase_cost'),
            'cost_currency' => $nullable('cost_currency'),
            'cost_note' => $nullable('cost_note'),
            'notes' => $nullable('notes'),
        ]);
    }

    public function updateProduct(Model $product, array $attributes): void
    {
        $pick = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? $attributes[$k] : null) : $default;
        $pickFloat = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? (float) $attributes[$k] : null) : $default;

        $this->products->update($product, [
            'product_type' => $attributes['product_type'] ?? $product->getAttribute('product_type'),
            'sku' => array_key_exists('sku', $attributes) ? ($attributes['sku'] ?: null) : $product->getAttribute('sku'),
            'gtin' => $pick('gtin', $product->getAttribute('gtin')),
            'name' => $attributes['name'] ?? $product->getAttribute('name'),
            'unit' => $pick('unit', $product->getAttribute('unit')),
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $product->getAttribute('is_active'),
            'weight_kg' => $pickFloat('weight_kg', $product->getAttribute('weight_kg')),
            'sale_price' => $pickFloat('sale_price', $product->getAttribute('sale_price')),
            'regular_price' => $pickFloat('regular_price', $product->getAttribute('regular_price')),
            'shipping_weight_kg' => $pickFloat('shipping_weight_kg', $product->getAttribute('shipping_weight_kg')),
            'length_cm' => $pickFloat('length_cm', $product->getAttribute('length_cm')),
            'width_cm' => $pickFloat('width_cm', $product->getAttribute('width_cm')),
            'height_cm' => $pickFloat('height_cm', $product->getAttribute('height_cm')),
            'country_of_origin' => $pick('country_of_origin', $product->getAttribute('country_of_origin')),
            'hs_code' => $pick('hs_code', $product->getAttribute('hs_code')),
            'standard_production_cost' => $pickFloat('standard_production_cost', $product->getAttribute('standard_production_cost')),
            'standard_packaging_cost' => $pickFloat('standard_packaging_cost', $product->getAttribute('standard_packaging_cost')),
            'standard_purchase_cost' => $pickFloat('standard_purchase_cost', $product->getAttribute('standard_purchase_cost')),
            'cost_currency' => $pick('cost_currency', $product->getAttribute('cost_currency')),
            'cost_note' => $pick('cost_note', $product->getAttribute('cost_note')),
            'notes' => $pick('notes', $product->getAttribute('notes')),
        ]);
    }

    public function deleteProduct(Model $product): void
    {
        $this->products->delete($product);
    }

    public function importFromUpload(string $tmpPath): ProductImportResult
    {
        return $this->import($tmpPath);
    }
}
