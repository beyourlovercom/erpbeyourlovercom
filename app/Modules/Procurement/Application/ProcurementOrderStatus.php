<?php

namespace App\Modules\Procurement\Application;

enum ProcurementOrderStatus: string
{
    case Pending = 'pending';
    case Purchasing = 'purchasing';
    case Ordered = 'ordered';
    case Arrived = 'arrived';
    case Released = 'released';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待采购',
            self::Purchasing => '采购中',
            self::Ordered => '已下单',
            self::Arrived => '已到货 · 待 Vita 放行',
            self::Released => '已放行 · 国内待发货',
            self::Completed => '已完成',
            self::Cancelled => '已取消',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
