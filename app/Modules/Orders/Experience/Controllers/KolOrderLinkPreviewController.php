<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class KolOrderLinkPreviewController
{
    public function __invoke(Request $request): Response
    {
        // Read-only review. Only global KOL managers see aggregate historical mappings.
        abort_unless($request->user()?->can('orders.view')
            && $request->user()?->can('kol.views.manage'), 403);

        $orders = Order::query()->where('order_kind', OrderKind::KolFulfillment->value);
        $unlinked = (clone $orders)->whereNull('kol_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('kol_collaborations')
                ->whereColumn('kol_collaborations.order_id', 'orders.id'));
        $collaborationOnly = (clone $orders)->whereNull('kol_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('kol_collaborations')
                ->whereColumn('kol_collaborations.order_id', 'orders.id'));
        $ambiguous = DB::table('kol_collaborations')->join('orders', 'orders.id', '=', 'kol_collaborations.order_id')
            ->where('orders.order_kind', OrderKind::KolFulfillment->value)->whereNull('orders.kol_id')
            ->select('kol_collaborations.order_id')->groupBy('kol_collaborations.order_id')
            ->havingRaw('COUNT(DISTINCT kol_collaborations.kol_id) > 1');

        $preview = [
            'mode' => 'preview_only',
            'auto_match_enabled' => false,
            'requires_manual_evidence' => true,
            'total_kol_orders' => (clone $orders)->count(),
            'explicitly_linked' => (clone $orders)->whereNotNull('kol_id')->count(),
            'existing_collaboration_only' => $collaborationOnly->count(),
            'unlinked' => $unlinked->count(),
            'ambiguous_collaborations' => $ambiguous->count(),
            'unlinked_sample' => (clone $unlinked)->latest('id')->limit(20)
                ->get(['id', 'erp_order_id'])->map(fn (Order $order): array => [
                    'id' => (int) $order->id, 'erp_order_id' => $order->erp_order_id,
                    'status' => 'missing_authoritative_kol_identity',
                ])->all(),
            'next_action' => '核对原始 CRM/人工证据后单独审批历史回填；不得以姓名、邮箱或订单号猜测',
        ];

        return $request->wantsJson()
            ? response()->json($preview)
            : Inertia::render('Kol/OrderLinkPreview', ['preview' => $preview])->toResponse($request);
    }
}
