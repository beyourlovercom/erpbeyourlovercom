<?php

namespace Tests\Unit\Orders;

use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Orders\Application\OrderBusinessDateResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\Carbon;
use Tests\TestCase;

final class OrderBusinessDateResolverTest extends TestCase
{
    public function test_split_order_inherits_parent_payment_date_without_overwriting_child_creation_timestamp(): void
    {
        $parent = $this->order('customer', 'woocommerce', '2026-10-09', '2026-10-09 11:00:00');
        $parent->setRelation('financials', new OrderFinancial(['paid_at' => '2026-10-12 10:45:00']));
        $child = $this->split($parent);
        self::assertSame(['date' => '10-12', 'source' => '母订单支付日期'], (new OrderBusinessDateResolver)->domesticReview($child));
        self::assertSame('2026-11-09', $child->created_at->format('Y-m-d'));
    }

    public function test_split_uses_parent_original_order_date_when_payment_is_missing(): void
    {
        $parent = $this->order('customer', 'website', '2026-10-06', '2026-10-07 08:00:00');
        $parent->setRelation('financials', null);
        self::assertSame(['date' => '10-06', 'source' => '母订单日期'], (new OrderBusinessDateResolver)->domesticReview($this->split($parent)));
    }

    public function test_linked_kol_collaboration_date_takes_precedence_for_root_and_split(): void
    {
        $parent = $this->order('kol_fulfillment', 'kol', '2026-11-09', '2026-11-09 08:00:00');
        $parent->setRelation('kolCollaboration', new KolCollaboration(['agreed_at' => '2026-09-24']));
        self::assertSame(['date' => '09-24', 'source' => '合作日期'], (new OrderBusinessDateResolver)->domesticReview($parent));
        self::assertSame(['date' => '09-24', 'source' => '母订单合作日期'], (new OrderBusinessDateResolver)->domesticReview($this->split($parent)));
    }

    public function test_kol_without_a_real_collaboration_date_uses_true_creation_date(): void
    {
        $parent = $this->order('kol_fulfillment', 'kol', '2026-10-01', '2026-11-09 09:00:00');
        $parent->setRelation('kolCollaboration', new KolCollaboration());
        self::assertSame(['date' => '11-09', 'source' => '订单创建日期'], (new OrderBusinessDateResolver)->domesticReview($parent));
    }

    public function test_manually_entered_kol_order_date_is_preserved_as_real_cooperation_date(): void
    {
        $parent = $this->order('kol_fulfillment', 'manual', '2026-08-19', '2026-11-09 09:00:00');
        $parent->setRelation('kolCollaboration', null);
        self::assertSame(['date' => '08-19', 'source' => '网红订单日期'], (new OrderBusinessDateResolver)->domesticReview($parent));
    }

    public function test_regular_unpaid_root_remains_unknown_in_paid_date_column(): void
    {
        $parent = $this->order('customer', 'website', '2026-10-06', '2026-10-07 08:00:00');
        $parent->setRelation('financials', null);
        self::assertSame(['date' => null, 'source' => null], (new OrderBusinessDateResolver)->domesticReview($parent));
    }

    private function order(string $kind, string $source, string $orderDate, string $createdAt): Order
    {
        $order = new Order(['order_kind' => $kind, 'source_type' => $source, 'order_date' => $orderDate]);
        $order->created_at = Carbon::parse($createdAt);
        return $order;
    }

    private function split(Order $parent): Order
    {
        $child = $this->order('fulfillment_derived', 'manual_other', '2026-11-09', '2026-11-09 10:00:00');
        $child->fulfillment_parent_id = 42;
        $child->setRelation('fulfillmentParent', $parent);
        return $child;
    }
}
