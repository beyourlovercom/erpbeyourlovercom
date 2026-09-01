<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\SkuMatchKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SkuAliasRepository $skuAliases,
        private readonly ProductWeightEvidenceService $weightEvidence,
    ) {}

    /** @return array{result: ProductImportResult, candidates: list<array<string, mixed>>} */
    public function preview(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) return ['result' => new ProductImportResult(invalid: 1, errors: ["Cannot open file: {$csvPath}"]), 'candidates' => []];
        $headerLine = fgets($handle);
        if ($headerLine === false) { fclose($handle); return ['result' => new ProductImportResult(invalid: 1, errors: ['Empty or unreadable CSV']), 'candidates' => []]; }
        $headerLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = str_getcsv($headerLine);
        $headerMap = array_flip(array_map(static fn ($header): string => trim((string) $header), $headers));
        foreach (['ID', 'Type', 'SKU', 'Name', 'Published'] as $required) {
            if (! isset($headerMap[$required])) { fclose($handle); return ['result' => new ProductImportResult(invalid: 1, errors: ["Missing required column: {$required}"]), 'candidates' => []]; }
        }

        $total = $candidates = $active = $inactive = $invalid = $skippedVariations = $reconciliation = 0;
        $new = $updates = $unchanged = $duplicateSku = $identity = 0;
        $errors = $candidateRows = $seenSkus = $seenWooIds = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($this->rowIsEmpty($row)) continue;
            $total++;
            $cell = static fn (array $row, array $map, string $column): ?string => isset($map[$column]) ? (($value = trim((string) ($row[$map[$column]] ?? ''))) === '' ? null : $value) : null;
            $wooIdRaw = $cell($row, $headerMap, 'ID');
            $wooId = ctype_digit((string) $wooIdRaw) ? (int) $wooIdRaw : 0;
            $type = strtolower((string) ($cell($row, $headerMap, 'Type') ?? ''));
            $sku = $cell($row, $headerMap, 'SKU');
            $name = $cell($row, $headerMap, 'Name');
            if ($wooId <= 0 || $name === null) { $invalid++; $errors[] = "Row {$rowNum}: invalid or missing ID/Name"; continue; }
            if ($sku === null) { if ($type === 'variation') $skippedVariations++; else $reconciliation++; continue; }
            $values = $this->parseValues($row, $headerMap, $wooId, $type, $sku, $name);
            if ($values === null) { $invalid++; $errors[] = "Row {$rowNum}: invalid numeric value"; continue; }
            $candidates++;
            if ($values['is_active']) $active++; else $inactive++;
            if (isset($seenSkus[$sku])) { $duplicateSku++; $errors[] = "Row {$rowNum}: duplicate SKU conflict: {$sku}"; continue; }
            if (isset($seenWooIds[$wooId])) { $identity++; $errors[] = "Row {$rowNum}: duplicate Woo Product ID conflict: {$wooId}"; continue; }
            $seenSkus[$sku] = true;
            $seenWooIds[$wooId] = true;
            $existingByWoo = $this->products->findByWooId($wooId);
            $existingBySku = $this->products->findBySku($sku);
            if (($existingByWoo !== null && $existingByWoo->getAttribute('sku') !== $sku) || ($existingBySku !== null && (int) $existingBySku->getAttribute('woocommerce_product_id') !== $wooId)) {
                $identity++; $errors[] = "Row {$rowNum}: Woo-ID/SKU identity conflict for {$sku}"; continue;
            }
            // Gate 5: new canonical SKU must not collide with an existing Alias match key.
            if ($existingByWoo === null && $existingBySku === null) {
                $skuMatchKey = SkuMatchKey::compute($sku);
                if ($skuMatchKey !== null && $this->skuAliases->existsMatchKey($skuMatchKey)) {
                    $identity++; $errors[] = "Row {$rowNum}: SKU '{$sku}' match key is already registered as a SKU alias"; continue;
                }
                $new++;
            } elseif ($this->sameBootstrapValues($existingByWoo ?? $existingBySku, $values)) $unchanged++;
            else $updates++;
            $candidateRows[] = $values;
        }
        fclose($handle);
        return ['result' => new ProductImportResult($total, $candidates, $new, $updates, $unchanged, $active, $inactive, $skippedVariations, $reconciliation, $invalid, $duplicateSku, $identity, $errors), 'candidates' => $candidateRows];
    }

    /** @param list<array<string, mixed>> $candidates */
    public function confirm(array $candidates): ProductImportResult
    {
        $inserted = $updated = $unchanged = 0;
        DB::transaction(function () use ($candidates, &$inserted, &$updated, &$unchanged): void {
            foreach ($candidates as $values) {
                $sku = (string) $values['sku']; $wooId = (int) $values['woocommerce_product_id'];
                $byWoo = $this->products->findByWooId($wooId); $bySku = $this->products->findBySku($sku);
                if (($byWoo !== null && $byWoo->getAttribute('sku') !== $sku) || ($bySku !== null && (int) $bySku->getAttribute('woocommerce_product_id') !== $wooId)) throw new \RuntimeException("Confirm blocked by identity conflict for {$sku}");
                $product = $byWoo ?? $bySku;
                if ($product === null) {
                    // Gate 5: guard against alias collision that could have appeared since preview.
                    $skuMatchKey = SkuMatchKey::compute($sku);
                    if ($skuMatchKey !== null && $this->skuAliases->existsMatchKey($skuMatchKey)) {
                        throw new \RuntimeException("Confirm blocked: SKU '{$sku}' match key is already registered as a SKU alias");
                    }
                    $this->products->create($values); $inserted++; continue;
                }
                $attributes = $this->bootstrapAttributes($values);
                if ($product->getAttribute('weight_kg') === null && $values['weight_kg'] !== null) $attributes['weight_kg'] = $values['weight_kg'];
                if ($this->sameBootstrapValues($product, $values)) $unchanged++; else { $this->products->update($product, $attributes); $updated++; }
            }
        });
        $processed = $inserted + $updated + $unchanged;
        $active = count(array_filter($candidates, static fn (array $row): bool => ($row['is_active'] ?? false) === true));
        $inactive = $processed - $active;

        return new ProductImportResult($processed, $processed, $inserted, $updated, $unchanged, $active, $inactive);
    }

    public function import(string $csvPath): ProductImportResult
    {
        $preview = $this->preview($csvPath);
        if (! $preview['result']->toArray()['canConfirm']) return $preview['result'];
        return $this->confirm($preview['candidates']);
    }

    private function parseValues(array $row, array $map, int $wooId, string $type, string $sku, string $name): ?array
    {
        $cell = static fn (string $column): ?string => isset($map[$column]) ? (($value = trim((string) ($row[$map[$column]] ?? ''))) === '' ? null : $value) : null;
        $number = static function (?string $value): ?float { if ($value === null) return null; return is_numeric($value) && (float) $value >= 0 ? (float) $value : null; };
        $rawWeight = $cell('Weight (kg)'); $rawSale = $cell('Sale price'); $rawRegular = $cell('Regular price');
        $weight = $number($rawWeight); $sale = $number($rawSale); $regular = $number($rawRegular);
        foreach ([[$rawWeight, $weight], [$rawSale, $sale], [$rawRegular, $regular]] as [$raw, $parsed]) if ($raw !== null && $parsed === null) return null;
        $published = $cell('Published');
        return ['woocommerce_product_id' => $wooId, 'source_type' => 'woocommerce', 'product_type' => $type ?: 'simple', 'sku' => $sku, 'gtin' => $cell('GTIN, UPC, EAN, or ISBN'), 'name' => $name, 'sale_price' => $sale, 'regular_price' => $regular, 'is_active' => $published === null ? true : $published === '1', 'weight_kg' => $weight];
    }

    private function bootstrapAttributes(array $values): array
    {
        return ['woocommerce_product_id' => $values['woocommerce_product_id'], 'source_type' => 'woocommerce', 'product_type' => $values['product_type'], 'sku' => $values['sku'], 'gtin' => $values['gtin'], 'name' => $values['name'], 'sale_price' => $values['sale_price'], 'regular_price' => $values['regular_price'], 'is_active' => $values['is_active']];
    }

    private function sameBootstrapValues(Model $product, array $values): bool
    {
        foreach ($this->bootstrapAttributes($values) as $key => $value) if ($product->getAttribute($key) != $value) return false;
        return $values['weight_kg'] === null || $product->getAttribute('weight_kg') !== null || $product->getAttribute('weight_kg') == $values['weight_kg'];
    }

    private function rowIsEmpty(array $row): bool { return count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0; }

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

    /**
     * Throw if the given raw SKU's match key is already registered as an Alias match key.
     * This prevents a new canonical SKU from silently taking over an existing Alias's identity.
     * Pass $excludeProductId when updating so the product's own alias list is excluded.
     */
    private function assertNoAliasMatchKeyCollision(string $rawSku, ?int $excludeProductId = null): void
    {
        $matchKey = SkuMatchKey::compute($rawSku);
        if ($matchKey === null) {
            return;
        }
        if ($this->skuAliases->existsMatchKey($matchKey)) {
            throw new \RuntimeException("SKU '{$rawSku}' match key '{$matchKey}' is already registered as a SKU alias. Delete the alias first.");
        }
    }

    public function createManual(array $attributes): Model
    {
        $nullable = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? $attributes[$k] : null;
        $nullableFloat = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? (float) $attributes[$k] : null;

        $sku = $attributes['sku'] ?? null;
        if ($sku !== null && $sku !== '') {
            $this->assertNoAliasMatchKeyCollision((string) $sku);
        }

        $weightKg = $nullableFloat('weight_kg');
        $shippingWeightKg = $nullableFloat('shipping_weight_kg');

        $product = $this->products->create([
            'source_type' => 'manual',
            'woocommerce_product_id' => null,
            'product_type' => $attributes['product_type'] ?? 'simple',
            'sku' => $attributes['sku'] ?? null,
            'gtin' => $nullable('gtin'),
            'name' => $attributes['name'],
            'unit' => $nullable('unit'),
            'is_active' => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : true,
            'weight_kg' => null,
            'sale_price' => $nullableFloat('sale_price'),
            'regular_price' => $nullableFloat('regular_price'),
            'shipping_weight_kg' => null,
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

        $actorUserId = Auth::id();

        if ($weightKg !== null && $weightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'product_weight', $weightKg, $actorUserId);
            $product->refresh();
        }

        if ($shippingWeightKg !== null && $shippingWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'shipping_reference_weight', $shippingWeightKg, $actorUserId);
            $product->refresh();
        }

        return $product;
    }

    public function updateProduct(Model $product, array $attributes): void
    {
        $pick = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? $attributes[$k] : null) : $default;
        $pickFloat = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? (float) $attributes[$k] : null) : $default;

        $newSku = array_key_exists('sku', $attributes) ? ($attributes['sku'] ?: null) : $product->getAttribute('sku');
        if ($newSku !== null && $newSku !== $product->getAttribute('sku')) {
            $this->assertNoAliasMatchKeyCollision((string) $newSku);
        }

        // Determine new weight values; weight changes require provenance creation.
        $newWeightKg = array_key_exists('weight_kg', $attributes)
            ? ($attributes['weight_kg'] !== '' && $attributes['weight_kg'] !== null ? (float) $attributes['weight_kg'] : null)
            : null;
        $newShippingWeightKg = array_key_exists('shipping_weight_kg', $attributes)
            ? ($attributes['shipping_weight_kg'] !== '' && $attributes['shipping_weight_kg'] !== null ? (float) $attributes['shipping_weight_kg'] : null)
            : null;

        $currentWeightKg = $product->getAttribute('weight_kg') !== null ? (float) $product->getAttribute('weight_kg') : null;
        $currentShippingWeightKg = $product->getAttribute('shipping_weight_kg') !== null ? (float) $product->getAttribute('shipping_weight_kg') : null;

        $weightKgChanged = array_key_exists('weight_kg', $attributes) && $newWeightKg !== $currentWeightKg;
        $shippingWeightKgChanged = array_key_exists('shipping_weight_kg', $attributes) && $newShippingWeightKg !== $currentShippingWeightKg;

        $this->products->update($product, [
            'product_type' => $attributes['product_type'] ?? $product->getAttribute('product_type'),
            'sku' => array_key_exists('sku', $attributes) ? ($attributes['sku'] ?: null) : $product->getAttribute('sku'),
            'gtin' => $pick('gtin', $product->getAttribute('gtin')),
            'name' => $attributes['name'] ?? $product->getAttribute('name'),
            'unit' => $pick('unit', $product->getAttribute('unit')),
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $product->getAttribute('is_active'),
            // Weight fields written only by evidence adopt/clear below; generic update always keeps current DB value.
            'weight_kg' => $currentWeightKg,
            'sale_price' => $pickFloat('sale_price', $product->getAttribute('sale_price')),
            'regular_price' => $pickFloat('regular_price', $product->getAttribute('regular_price')),
            'shipping_weight_kg' => $currentShippingWeightKg,
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

        $product->refresh();
        $actorUserId = Auth::id();

        if ($weightKgChanged && $newWeightKg !== null && $newWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'product_weight', $newWeightKg, $actorUserId, confirmed: true);
        } elseif ($weightKgChanged && $newWeightKg === null) {
            $this->weightEvidence->clearWeight($product, 'product_weight', $actorUserId);
        }

        if ($shippingWeightKgChanged && $newShippingWeightKg !== null && $newShippingWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'shipping_reference_weight', $newShippingWeightKg, $actorUserId, confirmed: true);
        } elseif ($shippingWeightKgChanged && $newShippingWeightKg === null) {
            $this->weightEvidence->clearWeight($product, 'shipping_reference_weight', $actorUserId);
        }
    }

    public function deleteProduct(Model $product): void
    {
        $this->products->delete($product);
    }

    public function importFromUpload(string $tmpPath): array
    {
        return $this->preview($tmpPath);
    }
}
