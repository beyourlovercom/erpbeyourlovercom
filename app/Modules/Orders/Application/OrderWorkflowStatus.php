<?php

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final class OrderWorkflowStatus
{
    /** @return array<string,array{en:string,zh:string,color:string,mutable:bool,sequence:string}> */
    public static function registry(): array
    {
        $container = app();
        // Pure PHPUnit contract tests may call the status registry without
        // booting Laravel's config/database providers. Keep those canonical
        // definitions available without querying a nonexistent application.
        if (! $container->bound('config') || ! $container->bound('db')
            || ! $container->bound(OrderWorkflowStatusDisplayCatalog::class)) {
            $defaults = self::defaults();
            uasort($defaults, static fn (array $left, array $right): int => OrderWorkflowStatusDisplayCatalog::compareSequence($left['sequence'], $right['sequence']));

            return $defaults;
        }

        return $container->make(OrderWorkflowStatusDisplayCatalog::class)->all();
    }

    /** Canonical codes and business mutability live in code, never in admin settings. */
    public static function defaults(): array
    {
        $registry = [
            'pending_allocation' => ['en' => 'Pending Allocation', 'zh' => '待分仓', 'color' => 'neutral', 'mutable' => true],
            'pending_review' => ['en' => 'Pending Review', 'zh' => '待审核', 'color' => 'amber', 'mutable' => true],
            'partially_shipped' => ['en' => 'Partially Shipped', 'zh' => '部分已发货', 'color' => 'blue', 'mutable' => false],
            'archived' => ['en' => 'Archived', 'zh' => '已归档', 'color' => 'neutral', 'mutable' => false],
            'progress_conflict' => ['en' => 'Reconciliation Required', 'zh' => '状态待对账', 'color' => 'red', 'mutable' => false],
            'sending' => ['en' => 'Sending', 'zh' => '待发', 'color' => 'neutral', 'mutable' => true],
            DomesticShippingHold::MISSING_INFO => ['en' => 'Shipping hold - missing info', 'zh' => '信息缺失', 'color' => 'amber', 'mutable' => true],
            DomesticShippingHold::TRACKING_STOCKOUT => ['en' => 'Shipping hold - stockout with tracking', 'zh' => '有单号/缺货', 'color' => 'red', 'mutable' => true],
            'allocated' => ['en' => 'Allocated', 'zh' => '已分仓', 'color' => 'blue', 'mutable' => false],
            'sent' => ['en' => 'Sent', 'zh' => '已发等单号', 'color' => 'blue', 'mutable' => false],
            'resent' => ['en' => 'Resent', 'zh' => '补发订单', 'color' => 'blue', 'mutable' => true],
            'missing_info' => ['en' => 'Missing Info', 'zh' => '信息缺失', 'color' => 'amber', 'mutable' => true],
            'returned' => ['en' => 'Returned', 'zh' => '返回订单', 'color' => 'amber', 'mutable' => true],
            'shipping' => ['en' => 'Shipping', 'zh' => '运输中', 'color' => 'blue', 'mutable' => false],
            'shared' => ['en' => 'Shared', 'zh' => '已通知', 'color' => 'blue', 'mutable' => true],
            'arrived' => ['en' => 'Arrived', 'zh' => '已收货', 'color' => 'green', 'mutable' => false],
            'creating' => ['en' => 'Creating', 'zh' => '创作沟通', 'color' => 'neutral', 'mutable' => true],
            'published' => ['en' => 'Published', 'zh' => '已发布', 'color' => 'green', 'mutable' => true],
            'paying' => ['en' => 'Paying', 'zh' => '等待付款', 'color' => 'amber', 'mutable' => true],
            'paid' => ['en' => 'Paid', 'zh' => '已付款', 'color' => 'green', 'mutable' => false],
            'cancel' => ['en' => 'Cancel', 'zh' => '取消订单', 'color' => 'red', 'mutable' => false],
            'refunded' => ['en' => 'Refunded', 'zh' => '退款单', 'color' => 'red', 'mutable' => false],
            'finished' => ['en' => 'Finished', 'zh' => '完结', 'color' => 'green', 'mutable' => true],
            'shipped' => ['en' => 'Shipped', 'zh' => '已发货', 'color' => 'green', 'mutable' => true],
            'completed' => ['en' => 'Completed', 'zh' => '已完成', 'color' => 'green', 'mutable' => true],
            'cancelled' => ['en' => 'Cancelled', 'zh' => '已取消', 'color' => 'red', 'mutable' => true],
            'returned_follow_up' => ['en' => 'Returned', 'zh' => '退件需跟进', 'color' => 'amber', 'mutable' => true],
            'claims' => ['en' => 'Claims', 'zh' => '索赔需跟进', 'color' => 'red', 'mutable' => true],
            'renew' => ['en' => 'Renew', 'zh' => '问题订单', 'color' => 'amber', 'mutable' => true],
            'exception' => ['en' => 'Exception', 'zh' => '异常', 'color' => 'red', 'mutable' => true],
            'freight_product_cost_follow_up' => ['en' => 'Cost', 'zh' => '需跟进成本', 'color' => 'amber', 'mutable' => true],
            'out_of_stock' => ['en' => 'Out of stock', 'zh' => '缺货', 'color' => 'red', 'mutable' => true],
        ];
        $sequence = [
            'pending_allocation' => '0.0', 'pending_review' => '0.1', 'allocated' => '0.2',
            'sending' => '1.1', 'sent' => '1.2', 'resent' => '1.3', 'partially_shipped' => '1.4',
            'missing_info' => '1.5', 'returned' => '1.6', 'shipped' => '1.7',
            'shipping' => '2.1', 'shared' => '2.2', 'arrived' => '2.3', 'creating' => '2.4',
            'published' => '2.5', 'paying' => '2.6', 'paid' => '2.7', 'cancelled' => '2.8',
            'refunded' => '2.9', 'cancel' => '2.10', 'finished' => '3.0',
            'returned_follow_up' => '3.1', 'claims' => '3.2',
            'freight_product_cost_follow_up' => '3.3', 'out_of_stock' => '3.4',
            'renew' => '3.5', 'exception' => '3.6', 'completed' => '3.7',
            'archived' => '4.0', 'progress_conflict' => '4.1',
            DomesticShippingHold::TRACKING_STOCKOUT => '1.1.1', DomesticShippingHold::MISSING_INFO => '1.1.2',
        ];
        foreach ($registry as $code => &$definition) {
            $definition['sequence'] = $sequence[$code];
        }
        unset($definition);

        return $registry;
    }

    public static function definition(?string $code): ?array
    {
        return $code === null ? null : (self::registry()[$code] ?? null);
    }

    public static function assertMutable(string $code): void
    {
        $definition = self::definition($code);
        if ($definition === null || ! $definition['mutable']) {
            throw new InvalidArgumentException('Invalid or authority-derived workflow status.');
        }
    }

    public static function effectiveCode(?string $workflowStatus, string $canonicalStatus): string
    {
        // Never present a stale pre-shipment annotation as the real fulfillment status.
        // Once staff advances to a post-shipment business phase (e.g. KOL creating/published),
        // that separately persisted workflow phase remains visible without changing canonical truth.
        $preShipment = $workflowStatus === null || in_array($workflowStatus, ['sending', 'sent'], true);

        return match ($canonicalStatus) {
            'cancelled' => 'cancelled',
            'shipped' => $preShipment ? 'shipped' : $workflowStatus,
            'completed', 'completed_delivered' => $preShipment ? 'completed' : $workflowStatus,
            default => $workflowStatus ?? 'sending',
        };
    }

    public static function isCanonicalCode(string $code): bool
    {
        return in_array($code, ['shipped', 'completed', 'cancelled'], true);
    }
}
