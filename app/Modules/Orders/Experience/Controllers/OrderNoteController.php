<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderNoteController
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly NotificationPublisher $notifications,
    ) {}

    public function store(Request $request, Order $order): RedirectResponse
    {
        // The route itself requires orders.view. KOL specialists still see only
        // their own orders; derived segments inherit their parent order scope.
        $authority = $order->order_kind === OrderKind::FulfillmentDerived
            ? $order->fulfillmentParent()->firstOrFail() : $order;
        abort_unless($authority->order_kind->value === (string) $request->route('order_kind'), 404);
        KolOrderAccessScope::authorize($request->user(), $authority);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => '备注不能为空。']);
        }

        DB::transaction(function () use ($request, $order, $authority, $body): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at !== null || $locked->isOperationallyArchived()
                || $authority->archived_at !== null || $authority->isOperationallyArchived(),
                409, '归档订单不可追加备注。');

            $entry = $locked->noteEntries()->create([
                'author_user_id' => (int) $request->user()->getAuthIdentifier(),
                'author_name' => (string) $request->user()->name,
                'body' => $body,
            ]);
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'order.note_appended',
                Order::class,
                $locked->getKey(),
                before: null,
                after: ['note_entry_id' => $entry->getKey(), 'body' => $body],
                reason: 'internal_collaboration_note',
            );
        });

        // Reuse the existing business notification channel used for rejections.
        // Keep alerts targeted to the order owner and operations, not every user.
        $url = ($authority->order_kind === OrderKind::KolFulfillment ? '/kol/orders/' : '/customers/orders/').$order->getKey();
        User::query()->where('is_active', true)
            ->where(function ($query) use ($authority): void {
                $query->where('id', $authority->owner_id)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('key', RolePreset::Operations->roleKey()));
            })->get()->each(function (User $recipient) use ($request, $order, $url): void {
                if ($recipient->getKey() === $request->user()->getAuthIdentifier()
                    || ! $recipient->can('orders.view')) return;
                try {
                    $this->notifications->publishToUser($recipient, [
                        'category' => 'orders',
                        'title' => '订单新增内部备注',
                        'message' => "订单 {$order->erp_order_id} 有新的协作备注。",
                        'action_url' => $url,
                        'entity_type' => 'order',
                        'entity_id' => (string) $order->getKey(),
                        'actor_name' => $request->user()->name,
                    ]);
                } catch (\Throwable $exception) {
                    // Never report append failure after the audit + note were committed.
                    \Illuminate\Support\Facades\Log::warning('order_note_notification_failed', [
                        'order_id' => $order->getKey(), 'recipient_id' => $recipient->getKey(),
                    ]);
                }
            });

        return back()->with('success', '备注已追加');
    }
}
