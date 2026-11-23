<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderAddressController
{
    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code',
    ];

    public function __construct(private readonly OrderService $orders) {}

    /** Child orders remain separate shipments: only address fields are editable here. */
    public function updateDerived(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->order_kind === OrderKind::FulfillmentDerived, 404);
        $parent = $order->fulfillmentParent()->firstOrFail();
        KolOrderAccessScope::authorize($request->user(), $parent);
        $this->assertEditable($order, $parent);
        $rules = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $limit = in_array($field, ['recipient_name', 'address_line_1', 'address_line_2'], true) ? 255
                : ($field === 'phone' ? 64 : ($field === 'postal_code' ? 32 : 128));
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.$limit];
        }
        $data = $request->validate($rules);
        abort_if($data === [], 422, '没有需要保存的收件信息。');
        DB::transaction(function () use ($order, $parent, $data, $request): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked, $parent);
            $this->orders->update($locked, $data, null, $request->user(), 'derived_address_manual_edit');
        });

        return back()->with('success', '子订单收件地址已保存；如已向仓库下单，请另外通知仓库修改地址。');
    }

    /** Copies the saved source address to original A/B/C segments still pending shipment. */
    public function syncOriginalChildren(Request $request, Order $order): RedirectResponse
    {
        abort_if($order->order_kind === OrderKind::FulfillmentDerived, 404);
        KolOrderAccessScope::authorize($request->user(), $order);
        $this->assertEditable($order, $order);
        $result = DB::transaction(function () use ($request, $order): array {
            $source = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($source, $source);
            $source->load('address');
            abort_unless($source->address, 422, '请先在原单填写并保存收件地址。');
            $data = $source->address->only([...self::ADDRESS_FIELDS, 'recipient_customs_id']);
            $count = 0;
            $skipped = 0;
            $children = $source->originalFulfillmentChildren()->with('shipment')->lockForUpdate()->get();
            foreach ($children as $child) {
                $shipment = $child->shipment;
                if ($child->archived_at !== null || $child->trashed() || $child->isOperationallyArchived()
                    || ($shipment && ($shipment->shipment_status !== 'pending'
                        || filled($shipment->tracking_number) || $shipment->shipped_at !== null))) {
                    $skipped++;
                    continue;
                }
                $this->orders->update($child, $data, null, $request->user(), 'parent_address_explicit_sync');
                $count++;
            }

            return [$count, $skipped];
        });

        return back()->with('success', "已更新 {$result[0]} 个未发货子订单收件地址；跳过 {$result[1]} 个已发货或不可修改的子订单。已提交仓库的订单仍需核对仓库收件信息。");
    }

    private function assertEditable(Order $order, Order $parent): void
    {
        abort_if($order->trashed() || $parent->trashed() || $order->archived_at !== null
            || $parent->archived_at !== null || $order->isOperationallyArchived()
            || $parent->isOperationallyArchived(), 409, '该订单已归档或删除，不能编辑地址。');
    }
}
