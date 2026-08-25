<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
                $this->products->create(['woocommerce_product_id' => $wooId, ...$attributes]);
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
}
