<?php

namespace Tests\Feature\Logistics;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaFiles\MorePlusOutboundExporter;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

final class WarehouseFinalSkuContractTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->updateOrCreate(['code' => $code], [
            'name' => $code, 'slug' => strtolower($code), 'type' => 'warehouse',
            'is_active' => true, 'priority' => 1,
        ]);
    }

    private function product(string $sku = 'BY489A-Lubes'): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $sku, 'product_type' => 'simple',
            'source_type' => 'manual', 'is_active' => true,
        ]);
    }

    private function verified(Product $product, Warehouse $warehouse, string $physicalSku): ProductWarehouseSku
    {
        return ProductWarehouseSku::query()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'sku' => $physicalSku, 'is_active' => true,
            'verified_at' => now(), 'verification_note' => 'Physical SKU confirmed against warehouse product catalog',
        ]);
    }

    private function order(Product $product, Warehouse $warehouse): Order
    {
        $order = Order::query()->create([
            'erp_order_id' => '007-1118-c42380', 'internal_order_number' => 'INT-FINAL-SKU-TEST',
            'source_type' => 'manual_other', 'order_kind' => 'customer',
            'order_status' => 'pending_shipment', 'order_date' => now()->toDateString(),
        ]);
        $order->address()->create([
            'recipient_name' => 'Synthetic Test Recipient', 'phone' => '5550001',
            'country' => 'US', 'province_state' => 'CA', 'city' => 'Test City',
            'postal_code' => '90210', 'address_line_1' => '100 Example St',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'sku' => 'BY489A-Lubes',
            'source_sku' => 'BY489A-Lubes', 'product_summary' => 'Lubricant',
            'quantity' => 1, 'unit_price' => '5.00', 'line_revenue' => '5.00',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending', 'carrier' => 'USPS',
        ]);

        return $order->fresh(['items', 'shipment.warehouse', 'address']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'final-sku-'.uniqid(), 'label' => 'Final SKU Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    public function test_same_product_uses_distinct_verified_physical_skus_per_warehouse(): void
    {
        $product = $this->product();
        $moreplus = $this->warehouse('US-LA');
        $germany = $this->warehouse('DE');
        $factory = $this->warehouse('FACTORY');
        $this->verified($product, $moreplus, 'BY489A');
        $this->verified($product, $germany, 'DE-BY489A');
        $this->verified($product, $factory, 'CN-BY489A');

        $resolver = app(WarehouseFinalSkuResolver::class);
        self::assertSame('BY489A', $resolver->resolve($product->id, 'US-LA', 'BY489A-Lubes'));
        self::assertSame('DE-BY489A', $resolver->resolve($product->id, 'DE', 'BY489A-Lubes'));
        self::assertSame('CN-BY489A', $resolver->resolve($product->id, 'FACTORY', 'BY489A-Lubes'));
    }

    public function test_strict_dispatch_refuses_missing_and_stale_final_sku_without_rewriting_source(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $moreplus = $this->warehouse('US-LA');
        $order = $this->order($product, $moreplus);
        $this->verified($product, $moreplus, 'BY489A');
        $item = $order->items->firstOrFail();

        try {
            app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item);
            self::fail('Unmaterialized physical SKU must block dispatch');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }

        $item->forceFill(['final_sku' => 'BY489A', 'resolved_fulfillment_sku' => 'BY489A'])->save();
        self::assertSame('BY489A', app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item->fresh()));
        self::assertSame('BY489A', WarehouseSkuPresentation::sku($item->fresh()));
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);

        $item->forceFill(['final_sku' => 'DE-BY489A'])->save();
        $this->expectException(HttpException::class);
        app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item->fresh());
    }

    public function test_moreplus_export_uses_exact_final_sku_without_canonicalization(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $warehouse = $this->warehouse('US-LA');
        $this->verified($product, $warehouse, 'BY489A');
        $order = $this->order($product, $warehouse);
        $order->items->firstOrFail()->forceFill([
            'final_sku' => 'BY489A', 'resolved_fulfillment_sku' => 'BY489A',
        ])->save();

        $xlsx = app(MorePlusOutboundExporter::class)->export([$order->shipment]);
        $temp = tempnam(sys_get_temp_dir(), 'byl-final-sku-');
        self::assertNotFalse($temp);
        file_put_contents($temp, $xlsx);
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($temp) === true);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertStringContainsString('BY489A</t>', $sheet);
            self::assertStringNotContainsString('BY489A-Lubes</t>', $sheet);
        } finally {
            $zip->close();
            unlink($temp);
        }
        self::assertSame('BY489A-Lubes', $order->items->first()->fresh()->source_sku);
    }

    public function test_mapping_write_requires_product_operator_and_evidence(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse('US-LA');
        $viewer = $this->userWith(['products.view']);
        $operator = $this->userWith(['products.view', 'products.operate']);
        $url = "/products/{$product->id}/warehouse-skus/{$warehouse->id}";
        $body = [
            'sku' => 'BY489A', 'same_physical_product_confirmed' => true,
            'verification_evidence' => 'Physical product catalog and available stock matched by Dede',
        ];
        $this->actingAs($viewer)->put($url, $body)->assertForbidden();
        $this->actingAs($operator)->put($url, array_diff_key($body, ['verification_evidence' => true]))
            ->assertSessionHasErrors('verification_evidence');
        $this->actingAs($operator)->put($url, $body)->assertRedirect();
        $this->assertDatabaseHas('product_warehouse_skus', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'sku' => 'BY489A', 'is_active' => 1,
        ]);
    }

    public function test_unverified_mapping_never_qualifies_as_warehouse_identity(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $warehouse = $this->warehouse('US-LA');
        $mapping = $this->verified($product, $warehouse, 'BY489A');
        $mapping->forceFill(['verified_at' => null])->save();

        $this->expectException(HttpException::class);
        app(WarehouseFinalSkuResolver::class)->resolve($product->id, 'US-LA', 'BY489A-Lubes');
    }
}
