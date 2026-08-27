<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvPath = tempnam(sys_get_temp_dir(), 'woo_test_') . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    private function writeCsv(array $rows, array $headers = ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price']): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    public function test_basic_import_inserts_products(): void
    {
        $this->writeCsv([
            [1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89],
            [1002, 'simple', 'SKU-B', '', 'Product B', '', 79, 109],
        ]);

        $result = app(ProductService::class)->import($this->csvPath);

        $this->assertSame(2, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->invalid);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 1001, 'sku' => 'SKU-A', 'name' => 'Product A']);
    }

    public function test_import_is_idempotent(): void
    {
        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89]]);

        $service = app(ProductService::class);
        $first = $service->import($this->csvPath);
        $second = $service->import($this->csvPath);

        $this->assertSame(1, $first->inserted);
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->skipped);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_import_updates_changed_product(): void
    {
        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89]]);
        $service = app(ProductService::class);
        $service->import($this->csvPath);

        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A Updated', 0.6, 69, 99]]);
        $result = $service->import($this->csvPath);

        $this->assertSame(1, $result->updated);
        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 1001, 'name' => 'Product A Updated']);
    }

    public function test_blank_sku_is_allowed_in_product_master(): void
    {
        $this->writeCsv([
            [2001, 'simple', '', '', 'No SKU Product', '', 59, 89],
        ]);

        $result = app(ProductService::class)->import($this->csvPath);

        $this->assertSame(1, $result->inserted);
        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 2001, 'sku' => null, 'name' => 'No SKU Product']);
    }

    public function test_nonblank_sku_must_be_unique(): void
    {
        Product::create(['woocommerce_product_id' => 3001, 'sku' => 'DUP-SKU', 'name' => 'First', 'product_type' => 'simple']);

        $this->writeCsv([[3002, 'simple', 'DUP-SKU', '', 'Second', '', 59, 89]]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        app(ProductService::class)->import($this->csvPath);
    }

    public function test_invalid_rows_counted_and_reported(): void
    {
        $this->writeCsv([
            [0, 'simple', 'SKU-BAD', '', 'Bad ID', '', 59, 89],
            [1003, 'simple', 'SKU-OK', '', 'Good Product', '', 59, 89],
        ]);

        $result = app(ProductService::class)->import($this->csvPath);

        $this->assertSame(1, $result->invalid);
        $this->assertSame(1, $result->inserted);
        $this->assertNotEmpty($result->errors);
    }

    public function test_search_skus_excludes_blank_sku_products(): void
    {
        Product::create(['woocommerce_product_id' => 4001, 'sku' => 'FIND-ME', 'name' => 'Findable', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 4002, 'sku' => null, 'name' => 'FIND-ME No SKU', 'product_type' => 'simple']);

        $results = app(ProductService::class)->searchSkus('FIND');

        $this->assertCount(1, $results);
        $this->assertSame('FIND-ME', $results[0]['sku']);
    }

    public function test_search_returns_prefix_match_first(): void
    {
        Product::create(['woocommerce_product_id' => 5001, 'sku' => 'ABC-SUFFIX', 'name' => 'Suffix', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5002, 'sku' => 'ABC-PREFIX', 'name' => 'Prefix', 'product_type' => 'simple']);

        $results = app(ProductService::class)->searchSkus('ABC-P');

        $this->assertSame('ABC-PREFIX', $results[0]['sku']);
    }

    public function test_import_does_not_delete_unrelated_products(): void
    {
        Product::create(['woocommerce_product_id' => 9999, 'sku' => 'EXISTING', 'name' => 'Keep Me', 'product_type' => 'simple']);

        $this->writeCsv([[6001, 'simple', 'NEW-ONE', '', 'New Product', '', 59, 89]]);
        app(ProductService::class)->import($this->csvPath);

        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 9999, 'sku' => 'EXISTING']);
        $this->assertDatabaseCount('products', 2);
    }
}
