<?php

namespace Tests\Unit\Orders;

use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\DomesticReviewAmountResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use Tests\TestCase;

final class DomesticReviewAmountResolverTest extends TestCase
{
    public function test_website_uses_real_order_total_and_original_currency(): void
    {
        $order = $this->order('customer', 'woocommerce');
        $order->setRelation('financials', new OrderFinancial(['provider_order_total' => '127.35', 'currency' => 'EUR']));
        self::assertSame(['amount'=>'127.35','currency'=>'EUR','source'=>'网站真实订单总金额'], (new DomesticReviewAmountResolver)->resolve($order));
    }

    public function test_split_child_uses_parent_total_without_fabricating_child_revenue(): void
    {
        $parent = $this->order('customer', 'woocommerce');
        $parent->setRelation('financials', new OrderFinancial(['provider_order_total'=>'199.99','currency'=>'USD']));
        $child = $this->order('fulfillment_derived','manual_other');
        $child->fulfillment_parent_id = 73;
        $child->setRelation('fulfillmentParent', $parent);
        self::assertSame(['amount'=>'199.99','currency'=>'USD','source'=>'母订单总金额 · 分仓不重复计收'], (new DomesticReviewAmountResolver)->resolve($child));
        self::assertFalse($child->relationLoaded('financials'));
    }

    public function test_missing_real_website_amount_stays_unknown_instead_of_zero(): void
    {
        $root=$this->order('customer','website');
        $root->setRelation('financials',null);
        self::assertSame(['amount'=>null,'currency'=>null,'source'=>'网站原始总金额尚未同步'], (new DomesticReviewAmountResolver)->resolve($root));
    }

    public function test_kol_root_and_derived_order_are_zero_usd(): void
    {
        $parent=$this->order('kol_fulfillment','kol');
        $child=$this->order('fulfillment_derived','manual_other');
        $child->fulfillment_parent_id=73;
        $child->setRelation('fulfillmentParent',$parent);
        foreach ([$parent,$child] as $order) {
            self::assertSame(['amount'=>'0.00','currency'=>'USD','source'=>'网红履约订单 · 不计销售金额'], (new DomesticReviewAmountResolver)->resolve($order));
        }
    }

    public function test_chunyi_identified_warehouse_defaults_to_29_usd(): void
    {
        $order=$this->order('customer','manual_other');
        $shipment = new Shipment();
        $shipment->setRelation('warehouse',new Warehouse(['code'=>'CHUNYI','name'=>'春意合作方']));
        $order->setRelation('shipment',$shipment);
        self::assertSame(['amount'=>'29.00','currency'=>'USD','source'=>'春意合作方履约 · 默认订单金额'], (new DomesticReviewAmountResolver)->resolve($order));
    }

    public function test_other_manual_orders_are_not_automatically_chunyi_or_web_revenue(): void
    {
        $order=$this->order('customer','manual');
        self::assertSame(['amount'=>null,'currency'=>null,'source'=>'非网站订单 · 无金额规则'], (new DomesticReviewAmountResolver)->resolve($order));
    }

    private function order(string $kind,string $source): Order
    {
        return new Order(['order_kind'=>$kind,'source_type'=>$source,'currency'=>'USD']);
    }
}
