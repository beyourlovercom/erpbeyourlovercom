<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Orders\Infrastructure\Models\OrderCollaborationPartner;
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
                ->orderBy('name')->get(['id', 'code', 'name']),
            'can_manage' => (bool) $request->user()->can('system.permissions.manage'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('order_collaboration_partners', 'name')],
        ]);
        $partner = DB::transaction(function () use ($data, $request): OrderCollaborationPartner {
            $partner = OrderCollaborationPartner::query()->create([
                ...$data, 'code' => 'CP_'.Str::upper((string) Str::ulid()), 'is_active' => true,
            ]);
            $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'orders.collaboration_partner.created', 'order_collaboration_partner', $partner->id,
                null, $partner->only(['code', 'name', 'is_active']));

            return $partner;
        });

        return response()->json(['partner' => $partner->only(['id', 'code', 'name'])], 201);
    }

    public function update(Request $request, OrderCollaborationPartner $partner): JsonResponse
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160', Rule::unique('order_collaboration_partners', 'name')->ignore($partner->id)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        DB::transaction(function () use ($partner, $data, $request): void {
            $before = $partner->only(['code', 'name', 'is_active']);
            $partner->update($data);
            $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'orders.collaboration_partner.updated', 'order_collaboration_partner', $partner->id,
                $before, $partner->only(['code', 'name', 'is_active']));
        });

        return response()->json(['partner' => $partner->fresh()->only(['id', 'code', 'name', 'is_active'])]);
    }
}
