<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;

final class WarehouseOrderReference
{
    public function warehouse(Order $order): string
    {
        [$prefix, $number, $suffix] = $this->parts($order);

        return $prefix.$number.'-007'.$suffix;
    }

    public function canada(Order $order): string
    {
        [$prefix, $number, $suffix] = $this->parts($order);

        return $prefix.$number.$suffix;
    }

    /** @return array{0:string,1:string,2:string} */
    private function parts(Order $order): array
    {
        $erp = trim((string) $order->erp_order_id);
        if (preg_match('/^(C|KOL)(\d+)-007([A-Z]?)$/iD', $erp, $canonical) === 1) {
            return [strtoupper($canonical[1]), $canonical[2], strtoupper($canonical[3])];
        }
        if (preg_match('/-(c|k)(\d+)([A-Z]?)$/i', $erp, $matches) !== 1) {
            throw new DomainException("订单 {$erp} 无法生成海外仓客户参考号");
        }

        return [strtolower($matches[1]) === 'k' ? 'KOL' : 'C', $matches[2], strtoupper((string) ($matches[3] ?? ''))];
    }
}
