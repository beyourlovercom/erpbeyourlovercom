<?php

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final class OrderWorkflowStatus
{
    /** @return array<string,array{en:string,zh:string,color:string,mutable:bool}> */
    public static function registry(): array
    {
        return [
            'pending_allocation' => ['en' => 'Pending Allocation', 'zh' => '待分仓', 'color' => 'neutral', 'mutable' => false],
            'pending_review' => ['en' => 'Pending Review', 'zh' => '待审核', 'color' => 'amber', 'mutable' => false],
            'progress_conflict' => ['en' => 'Reconciliation Required', 'zh' => '状态待对账', 'color' => 'red', 'mutable' => false],
            'sending' => ['en' => 'Sending', 'zh' => '待发', 'color' => 'neutral', 'mutable' => true],
            'allocated' => ['en' => 'Allocated', 'zh' => '已分仓', 'color' => 'blue', 'mutable' => false],
            'sent' => ['en' => 'Sent', 'zh' => '已发等单号', 'color' => 'blue', 'mutable' => false],
            'resent' => ['en' => 'Resent', 'zh' => '补发订单', 'color' => 'blue', 'mutable' => true],
            'missing_info' => ['en' => 'Missing Info', 'zh' => '信息缺失', 'color' => 'amber', 'mutable' => true],
            'returned' => ['en' => 'Returned', 'zh' => '返回订单', 'color' => 'amber', 'mutable' => true],
            'shipping' => ['en' => 'Shipping', 'zh' => '运输中', 'color' => 'blue', 'mutable' => false],
            'shared' => ['en' => 'Shared', 'zh' => '已经通知', 'color' => 'blue', 'mutable' => true],
            'arrived' => ['en' => 'Arrived', 'zh' => '已收货', 'color' => 'green', 'mutable' => false],
            'creating' => ['en' => 'Creating', 'zh' => '创作沟通', 'color' => 'neutral', 'mutable' => true],
            'published' => ['en' => 'Published', 'zh' => '已发布', 'color' => 'green', 'mutable' => true],
            'paying' => ['en' => 'Paying', 'zh' => '等待付款', 'color' => 'amber', 'mutable' => true],
            'paid' => ['en' => 'Paid', 'zh' => '已付款', 'color' => 'green', 'mutable' => false],
            'cancel' => ['en' => 'Cancel', 'zh' => '取消订单', 'color' => 'red', 'mutable' => false],
            'refunded' => ['en' => 'Refunded', 'zh' => '退款单', 'color' => 'red', 'mutable' => false],
            'finished' => ['en' => 'Finished', 'zh' => '完结', 'color' => 'green', 'mutable' => true],
            'shipped' => ['en' => 'Shipped', 'zh' => '已发货', 'color' => 'green', 'mutable' => false],
            'completed' => ['en' => 'Completed', 'zh' => '已完成', 'color' => 'green', 'mutable' => false],
            'cancelled' => ['en' => 'Cancelled', 'zh' => '已取消', 'color' => 'red', 'mutable' => false],
            'returned_follow_up' => ['en' => 'Returned', 'zh' => '退件需跟进', 'color' => 'amber', 'mutable' => true],
            'claims' => ['en' => 'Claims', 'zh' => '索赔需跟进', 'color' => 'red', 'mutable' => true],
            'renew' => ['en' => 'Renew', 'zh' => '问题订单', 'color' => 'amber', 'mutable' => true],
            'freight_product_cost_follow_up' => ['en' => 'Cost', 'zh' => '需跟进成本', 'color' => 'amber', 'mutable' => true],
            'out_of_stock' => ['en' => 'Out of stock', 'zh' => '缺货', 'color' => 'red', 'mutable' => true],
        ];
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
