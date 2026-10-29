<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Domestic\ChinaSmallPacketBarcode69Reader;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;

/** Read-only preview for European parcels fulfilled from the China factory. */
final class ChinaSmallPacketBarcode69Controller
{
    private const EUROPE = [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU',
        'IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        'GB','UK','NO','CH','IS','LI','AD','MC','SM','VA','AL','BA','ME','MK','RS','MD','UA','TR',
    ];

    public function __invoke(Order $order, ChinaSmallPacketBarcode69Reader $reader): JsonResponse
    {
        $order->loadMissing(['address', 'shipment.warehouse']);
        $country = strtoupper(trim((string) $order->address?->country));
        abort_unless(in_array($country, self::EUROPE, true), 422, '该订单目的地不是欧洲。');
        abort_unless($order->shipment?->warehouse?->is_domestic, 422, '该订单并非国内仓出库。');

        return response()->json([
            'order_id' => $order->id,
            'erp_order_id' => $order->erp_order_id,
            'destination_country' => $country,
            'warehouse' => $order->shipment->warehouse->slug,
            'items' => $reader->forOrder($order),
        ]);
    }
}
