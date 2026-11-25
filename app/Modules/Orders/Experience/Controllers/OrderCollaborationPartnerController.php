<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Orders\Infrastructure\Models\OrderCollaborationPartner;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OrderCollaborationPartnerController
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('orders.view'), 403);

        return response()->json([
            'partners' => OrderCollaborationPartner::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name', 'identity_prefix', 'is_active']),
            'can_manage' => (bool) $request->user()->can('system.permissions.manage'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
        $this->normalizePrefix($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('order_collaboration_partners', 'name')],
            'identity_prefix' => ['required', 'string', 'regex:/^[A-Z]{2,8}$/D', Rule::unique('order_collaboration_partners', 'identity_prefix')],
        ]);
        $partner = DB::transaction(function () use ($data, $request): OrderCollaborationPartner {
            $this->assertHistoricalPrefixAvailable($data['identity_prefix'], null);
            $partner = OrderCollaborationPartner::query()->create([
                ...$data, 'code' => 'CP_'.Str::upper((string) Str::ulid()), 'is_active' => true,
            ]);
            $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'orders.collaboration_partner.created', 'order_collaboration_partner', $partner->id,
                null, $partner->only(['code', 'name', 'is_active', 'identity_prefix']));

            return $partner;
        });

        return response()->json(['partner' => $partner->only(['id', 'code', 'name', 'identity_prefix', 'is_active'])], 201);
    }

    public function update(Request $request, OrderCollaborationPartner $partner): JsonResponse
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
        $this->normalizePrefix($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160', Rule::unique('order_collaboration_partners', 'name')->ignore($partner->id)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'identity_prefix' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2,8}$/D', Rule::unique('order_collaboration_partners', 'identity_prefix')->ignore($partner->id)],
        ]);
        DB::transaction(function () use ($partner, $data, $request): void {
            $partner = OrderCollaborationPartner::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            if (isset($data['identity_prefix'])) {
                $this->assertHistoricalPrefixAvailable($data['identity_prefix'], $partner->id);
            }
            $before = $partner->only(['code', 'name', 'is_active', 'identity_prefix']);
            $partner->update($data);
            $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'orders.collaboration_partner.updated', 'order_collaboration_partner', $partner->id,
                $before, $partner->only(['code', 'name', 'is_active', 'identity_prefix']));
        });

        return response()->json(['partner' => $partner->fresh()->only(['id', 'code', 'name', 'is_active', 'identity_prefix'])]);
    }
    private function normalizePrefix(Request $request): void
    {
        if (is_string($request->input('identity_prefix'))) {
            $request->merge(['identity_prefix' => strtoupper(trim($request->input('identity_prefix')))]);
        }
    }

    private function assertHistoricalPrefixAvailable(string $prefix, ?int $partnerId): void
    {
        $query = Order::withTrashed()->whereRaw('UPPER(erp_order_id) LIKE ?', [$prefix.'_____-007']);
        if ($partnerId !== null) {
            $query->where(fn ($q) => $q->whereNull('collaboration_partner_id')->orWhere('collaboration_partner_id', '<>', $partnerId));
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['identity_prefix' => '此编号缩写已被其他历史订单使用，不能重新分配。']);
        }
    }
}
