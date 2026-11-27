<?php

namespace Tests\Feature\Logistics;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticPendingBatchExporter;
use App\Modules\Logistics\Application\OverseaFiles\MorePlusOutboundExporter;
use App\Modules\Logistics\Application\PendingFinalSkuMaterializer;
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

    public function test_strict_dispatch_resolves_missing_and_stale_final_sku_without_rewriting_source(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $moreplus = $this->warehouse('US-LA');
        $order = $this->order($product, $moreplus);
        $this->verified($product, $moreplus, 'BY489A');
        $item = $order->items->firstOrFail();

        self::assertSame('BY489A', app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item));
        self::assertNull($item->fresh()->final_sku);

        $item->forceFill(['final_sku' => 'BY489A', 'resolved_fulfillment_sku' => 'BY489A'])->save();
        self::assertSame('BY489A', app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item->fresh()));
        self::assertSame('BY489A', WarehouseSkuPresentation::sku($item->fresh()));
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);

        $item->forceFill(['final_sku' => 'DE-BY489A'])->save();
        self::assertSame('BY489A', app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $item->fresh()));
        self::assertSame('DE-BY489A', $item->fresh()->final_sku);
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);
    }

    public function test_moreplus_export_resolves_verified_sku_without_materialized_final_sku(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $warehouse = $this->warehouse('US-LA');
        $this->verified($product, $warehouse, 'BY489A');
        $order = $this->order($product, $warehouse);
        $order->items->firstOrFail()->forceFill([
            'final_sku' => null, 'resolved_fulfillment_sku' => null,
        ])->save();

        $xlsx = app(MorePlusOutboundExporter::class)->export([$order->shipment]);
        $temp = tempnam(sys_get_temp_dir(), 'byl-final-sku-');
        self::assertNotFalse($temp);
        file_put_contents($temp, $xlsx);
        $zip = new ZipArchive;
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
        self::assertNull($order->items->first()->fresh()->final_sku);
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

    public function test_strict_factory_uses_selected_product_master_not_old_alias_and_external_warehouse_stays_strict(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product('M2');
        $factory = $this->warehouse('FACTORY');
        $external = $this->warehouse('US-LA');
        $resolver = app(WarehouseFinalSkuResolver::class);
        self::assertSame('M2', $resolver->resolve($product->id, 'FACTORY', 'OLD-WAREHOUSE-SKU'));
        $order = $this->order($product, $factory);
        $item = $order->items->firstOrFail();
        $item->forceFill(['final_sku' => 'M2', 'resolved_fulfillment_sku' => 'M2'])->save();
        self::assertSame('M2', $resolver->forShipment($order->shipment, $item->fresh()));
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);
        $this->verified($product, $factory, 'CN-M2');
        self::assertSame('CN-M2', $resolver->resolve($product->id, 'FACTORY', 'M2'));
        self::assertSame('CN-M2', $resolver->forShipment($order->shipment, $item->fresh()));
        self::assertSame('M2', $item->fresh()->final_sku);
        $this->expectException(HttpException::class);
        $resolver->resolve($product->id, 'US-LA', 'M2');
    }

    public function test_pending_factory_legacy_order_exports_without_mutating_final_sku(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product('VA617A');
        $factory = $this->warehouse('FACTORY');
        $order = $this->order($product, $factory);
        $item = $order->items->firstOrFail();
        $item->forceFill(['sku' => 'VA617A', 'final_sku' => null])->save();
        $resolver = app(WarehouseFinalSkuResolver::class);
        self::assertSame('VA617A', $resolver->forShipment($order->shipment, $item->fresh()));
        $exporter = app(DomesticPendingBatchExporter::class);
        foreach ([$exporter->export(collect([$order->fresh()])), $exporter->exportSkuMerged(collect([$order->fresh()]))] as $xlsx) {
            self::assertStringStartsWith('PK', $xlsx);
        }
        self::assertNull($item->fresh()->final_sku);
    }

    public function test_pending_factory_batch_isolates_unresolved_order_and_selected_batch_fails_atomically(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product('VA617A');
        $factory = $this->warehouse('FACTORY');
        $healthy = $this->order($product, $factory);
        $bad = $healthy->replicate();
        $bad->forceFill(['erp_order_id' => 'BAD-FINAL-SKU', 'internal_order_number' => 'INT-BAD-SKU'])->save();
        $bad->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $bad->items()->create(['sku' => 'gift', 'product_summary' => 'Unknown', 'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00']);
        $exporter = app(DomesticPendingBatchExporter::class);
        $orders = collect([$healthy, $bad->fresh()]);
        $result = $exporter->partition($orders);
        self::assertSame([$healthy->id], $result['ready']->pluck('id')->all());
        self::assertStringContainsString('BAD-FINAL-SKU', $result['blocked'][0]);
        self::assertStringStartsWith('PK', $exporter->export($orders));
        foreach ([$healthy, $bad] as $order) {
            $order->forceFill(['routing_warehouse_code' => 'FACTORY', 'workflow_status' => 'sending'])->save();
        }
        $viewer = $this->userWith(['logistics.view']);
        foreach (['/logistics/china/pending-batch/export', '/logistics/china/pending-batch/export-sku-merged'] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()
                ->assertHeader('X-BYL-Exported-Orders', '1')
                ->assertHeader('X-BYL-Blocked-Orders', '1');
            $this->getJson($url.'?order_ids[]='.$bad->id.'&order_ids[]='.$healthy->id)->assertUnprocessable();
            $this->get($url.'?order_ids[]='.$healthy->id)->assertOk()->assertHeader('X-BYL-Exported-Orders', '1');
        }
        $this->expectException(\DomainException::class);
        $exporter->export($orders, true);
    }

    public function test_authorized_pending_write_heals_sku_without_rewriting_source(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product('VA617A');
        $factory = $this->warehouse('FACTORY');
        $order = $this->order($product, $factory);
        $item = $order->items->firstOrFail();
        $item->forceFill(['sku' => 'VA617A', 'final_sku' => null])->save();
        $actor = $this->userWith(['domestic.review']);
        $service = app(PendingFinalSkuMaterializer::class);
        $order->forceFill(['routing_warehouse_code' => 'FACTORY', 'workflow_status' => 'out_of_stock'])->save();
        $order->operationalMarkers()->create(['marker' => 'domestic_stockout', 'marked_by' => $actor->id, 'marked_at' => now()]);
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/restock')->assertRedirect();
        self::assertSame('sending', $order->fresh()->workflow_status);
        self::assertSame('VA617A', $item->fresh()->final_sku);
        self::assertSame('VA617A', $item->fresh()->resolved_fulfillment_sku);
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);
        $item->forceFill(['final_sku' => 'OLD'])->save();
        $order->shipment->forceFill(['tracking_number' => 'STAGED-TEST'])->save();
        $service->heal($order, $actor);
        self::assertSame('OLD', $item->fresh()->final_sku);
    }

    public function test_pending_external_order_without_verified_mapping_is_blocked_even_with_source_sku(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $product = $this->product();
        $warehouse = $this->warehouse('US-LA');
        $order = $this->order($product, $warehouse);
        $this->expectException(HttpException::class);
        app(WarehouseFinalSkuResolver::class)->forShipment($order->shipment, $order->items->firstOrFail());
    }

    public function test_strict_factory_never_accepts_gift_placeholder_or_inactive_product(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        $factory = $this->warehouse('FACTORY');
        $gift = $this->product('gift');
        $inactive = $this->product('STOPPED');
        $inactive->forceFill(['is_active' => false])->save();
        $resolver = app(WarehouseFinalSkuResolver::class);
        self::assertNull($resolver->expected($gift->id, $factory->id));
        self::assertNull($resolver->expected($inactive->id, $factory->id));
        $this->expectException(HttpException::class);
        $resolver->resolve($inactive->id, 'FACTORY', 'STOPPED');
    }
}
