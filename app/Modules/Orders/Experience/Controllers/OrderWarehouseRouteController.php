<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderWarehouseRouting;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrderWarehouseRouteController
{
    public function update(Request $request, Order $order, OrderWarehouseRouting $routing): JsonResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_unless($request->user()?->can('orders.edit'), 403);
        $allowed = collect($routing->options())->pluck('code')->all();
        $data = $request->validate([
            'warehouse_code' => ['required', 'string', Rule::in($allowed)],
            'expected_routing_revision' => ['required', 'integer', 'min:0'],
        ]);
        $projection = $routing->select(
            $order, $data['warehouse_code'], (int) $data['expected_routing_revision'], $request->user()
        );

        return response()->json([
            'routing_warehouse' => $projection,
            'target_url' => $routing->destination($order->fresh(['shipment.warehouse']), $request->user()),
        ]);
    }
}
