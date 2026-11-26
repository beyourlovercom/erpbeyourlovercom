<?php

namespace Tests\Feature;

use App\Modules\Orders\Application\OrderIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderIdentityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_and_custom_prefix_generate_canonical_ids(): void
    {
        $service = app(OrderIdentityService::class);

        $this->assertSame('007erp', $service->prefix());
        $this->assertSame('007erp-0901-c3721', $service->generate('c', 3721, '2026-09-01'));

        $service->updatePrefix(' 007 ', null);

        $this->assertSame('007', $service->prefix());
        $this->assertSame('007-0901-c3721', $service->generate('c', 3721, '2026-09-01'));
        $this->assertSame('007-0901-k381', $service->generate('k', 381, '2026-09-01'));
    }


    public function test_woo_customer_identity_uses_customer_marker_plus_woo_order_number(): void
    {
        $service = app(OrderIdentityService::class);
        $service->updatePrefix('007', null);

        $this->assertSame('C40414-007', $service->previewWooCustomer('40414', '50123', '2026-09-10'));
        $this->assertSame('C40414-007', $service->previewWooCustomer('40414-VA617A-gift', '50123', '2026-09-10'));
        $this->assertSame('C50123-007', $service->previewWooCustomer('WC-40414', '50123', '2026-09-10'));
        $service->updatePrefix('yby', null);
        $this->assertSame('C40618-007', $service->generateWooCustomer('40618', '50123', '2026-11-24'));
        $this->assertSame('C40618-007', $service->previewWooCustomer('40618', '50123', '2027-01-01'));

    }

    public function test_warehouse_reference_supports_new_and_legacy_customer_identities(): void
    {
        $reference = app(\App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference::class);
        foreach (['C40618-007', '007-1024-c40618'] as $id) {
            $order = new \App\Modules\Orders\Infrastructure\Models\Order(['erp_order_id' => $id]);
            $this->assertSame('C40618-007', $reference->warehouse($order));
            $this->assertSame('C40618', $reference->canada($order));
            $order->erp_order_id = $id.'A';
            $this->assertSame('C40618-007A', $reference->warehouse($order));
            $this->assertSame('C40618A', $reference->canada($order));
            $order->erp_order_id = $id.'A-2';
            $this->assertSame('C40618-007A-2', $reference->warehouse($order));
            $this->assertSame('C40618A-2', $reference->canada($order));
        }
    }

    public function test_warehouse_reference_preserves_collaboration_prefix_and_split_suffix(): void
    {
        $reference = app(\App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference::class);
        $model = \App\Modules\Orders\Infrastructure\Models\Order::class;
        foreach (['CY01265-007', 'PARTNERX00001-007'] as $id) {
            $parent = new $model(['erp_order_id' => $id, 'source_type' => 'collaboration']);
            $this->assertSame($id, $reference->warehouse($parent));
            $this->assertSame(substr($id, 0, -4), $reference->canada($parent));
            $child = new $model(['erp_order_id' => $id.'A', 'source_type' => 'manual_other', 'fulfillment_parent_id' => 1]);
            $child->setRelation('fulfillmentParent', $parent);
            $this->assertSame($id.'A', $reference->warehouse($child));
            $child->erp_order_id = $id.'B-2';
            $this->assertSame($id.'B-2', $reference->warehouse($child));
        }
        $this->expectException(\DomainException::class);
        $reference->warehouse(new $model(['erp_order_id' => 'CY01265-007', 'source_type' => 'manual_other']));
    }

    public function test_kol_daily_sequence_uses_china_creation_date_and_never_reuses_numbers(): void
    {
        $service = app(OrderIdentityService::class);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-28 23:59:59', 'Asia/Shanghai'));
        for ($i = 1; $i <= 7; $i++) {
            $this->assertSame(sprintf('K260928%02d-007', $i), $service->allocateKol());
        }
        $service->updatePrefix('yby', null);
        $this->assertSame('K26092808-007', $service->allocateKol());
        \Illuminate\Support\Facades\DB::table('kol_daily_order_sequences')->where('creation_date', '2026-09-28')->update(['last_number' => 99]);
        $this->assertSame('K260928100-007', $service->allocateKol());
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-29 00:00:00', 'Asia/Shanghai'));
        $this->assertSame('K26092901-007', $service->allocateKol());
        $reference = app(\App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference::class);
        $order = new \App\Modules\Orders\Infrastructure\Models\Order(['erp_order_id' => 'K26092807-007A']);
        $this->assertSame('K26092807-007A', $reference->warehouse($order));
        $this->assertSame('K26092807A', $reference->canada($order));
        $order->erp_order_id = 'KOL26092807-007A';
        $this->assertSame('KOL26092807-007A', $reference->warehouse($order));
        $this->assertSame('KOL26092807A', $reference->canada($order));
        $order->erp_order_id = '007-0928-k123A';
        $this->assertSame('KOL123-007A', $reference->warehouse($order));
    }

    public function test_kol_rollout_day_continues_existing_orders_including_deleted_roots(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-28 12:00:00', 'Asia/Shanghai'));
        $model = \App\Modules\Orders\Infrastructure\Models\Order::class;
        $base = [
            'source_type' => 'manual', 'order_kind' => 'kol_fulfillment',
            'order_date' => '2026-09-01', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ];
        $first = $model::create([...$base, 'internal_order_number' => 'LEGACY-1', 'erp_order_id' => '007-0901-k1']);
        $second = $model::create([...$base, 'internal_order_number' => 'LEGACY-2', 'erp_order_id' => '007-0901-k2']);
        $second->delete();
        $model::create([...$base, 'internal_order_number' => 'DERIVED-1', 'erp_order_id' => '007-0901-k1A', 'fulfillment_parent_id' => $first->id]);
        $model::create([...$base, 'internal_order_number' => 'CUSTOMER-1', 'erp_order_id' => 'C123-007', 'order_kind' => 'customer']);
        $service = app(OrderIdentityService::class);
        $this->assertSame('K26092803-007', $service->allocateKol());
        $this->assertSame('K26092804-007', $service->allocateKol());
    }

    public function test_customer_sequence_is_global_and_prefix_changes_do_not_reset_it(): void
    {
        $service = app(OrderIdentityService::class);
        $setting = $service->setting();
        $setting->next_customer_number = 3721;
        $setting->save();

        $service->updatePrefix('007', null);
        $this->assertSame('007-0901-c3721', $service->allocateCustomer('2026-09-01'));
        $this->assertSame('007-0901-c3722', $service->allocateCustomer('2026-09-01'));

        $service->updatePrefix('yby', null);
        $this->assertSame('yby-0901-c3723', $service->allocateCustomer('2026-09-01'));
    }

    public function test_suffix_is_only_used_when_explicitly_supplied(): void
    {
        $service = app(OrderIdentityService::class);

        $this->assertSame('007erp-0901-k381A', $service->generate('k', 381, '2026-09-01', 'A'));
    }
}
