<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderContactPolicy;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderContactController
{
    public function update(Request $request, Order $order, AuditRecorder $audit): RedirectResponse
    {
        abort_unless(OrderContactPolicy::canEdit($request->user()), 403);
        abort_unless(in_array($order->order_kind, [OrderKind::Customer, OrderKind::KolFulfillment], true), 409, '请在有效的母订单修改订单联络人。');
        KolOrderAccessScope::authorize($request->user(), $order);
        $data = $request->validate(['contact_user_id' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($order, $request, $audit, $data): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked);
            abort_if($locked->archived_at !== null || $locked->isOperationallyArchived(), 409, '归档订单不能修改联络人。');
            $id = (int) $data['contact_user_id'];
            if (! OrderContactPolicy::isManualEligible($id)) {
                throw ValidationException::withMessages(['contact_user_id' => '请选择有效的在职员工。']);
            }
            if ((int) $locked->contact_user_id === $id) {
                return;
            }
            $before = $locked->contact_user_id;
            $locked->forceFill(['contact_user_id' => $id])->save();
            $audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'order.contact_changed', Order::class, $locked->id,
                before: ['contact_user_id' => $before],
                after: ['contact_user_id' => $id], reason: 'manual_order_contact_change');
        });

        return back()->with('success', '订单联络人已更新，子订单自动继承。');
    }
}
