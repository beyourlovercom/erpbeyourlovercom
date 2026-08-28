<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class KolCollaborationController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        $query = KolCollaboration::query()->with(['kol:id,display_name,owner_id', 'owner:id,name', 'products']);
        if (! KolScope::isAllScope($request->user())) {
            $query->whereHas('kol', fn ($q) => $q->where('owner_id', (int) $request->user()->getAuthIdentifier()));
        }
        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }
        if ($request->string('post_url_status')->value() === 'missing') {
            $query->where(fn ($q) => $q->whereNull('post_url')->orWhere('post_url', ''));
        } elseif ($request->string('post_url_status')->value() === 'present') {
            $query->whereNotNull('post_url')->where('post_url', '!=', '');
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('post_url', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('kol', fn ($k) => $k->where('display_name', 'like', "%{$search}%"));
            });
        }

        return Inertia::render('Kol/Collaborations', [
            'collaborations' => $query->latest('id')->limit(100)->get()->map(fn (KolCollaboration $item) => $this->payload($item))->values()->all(),
            'filters' => $request->only(['search', 'status', 'post_url_status']),
            'canOperate' => KolScope::canOperate($request->user()),
        ]);
    }

    public function store(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        $data = $this->validated($request, $kol);
        $products = $data['products'] ?? [];
        unset($data['products']);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }
        $data['kol_id'] = $kol->id;

        $collaboration = DB::transaction(function () use ($data, $products): KolCollaboration {
            $item = KolCollaboration::create($data);
            $this->replaceProducts($item, $products);
            return $item;
        });
        $this->recordAudit($request, 'kol.collaboration.created', $collaboration, null, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity']))], 201);
    }

    public function update(Request $request, Kol $kol, KolCollaboration $collaboration): JsonResponse
    {
        $this->authorizeCollaboration($request, $kol, $collaboration);
        $before = $this->snapshot($collaboration);
        $data = $this->validated($request, $kol, $collaboration);
        $products = $data['products'] ?? [];
        unset($data['products']);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }

        DB::transaction(function () use ($collaboration, $data, $products): void {
            $collaboration->update($data);
            $this->replaceProducts($collaboration, $products);
        });
        $this->recordAudit($request, 'kol.collaboration.updated', $collaboration, $before, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity']))]);
    }

    public function convertOpportunity(Request $request, Kol $kol, KolOpportunity $opportunity): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        abort_unless((int) $opportunity->kol_id === (int) $kol->id, 404);
        if ($existing = KolCollaboration::where('opportunity_id', $opportunity->id)->first()) {
            return response()->json(['collaboration' => $this->payload($existing->load(['owner', 'products.product', 'opportunity'])), 'already_exists' => true]);
        }

        $productRows = Product::query()->whereIn('sku', $opportunity->target_products ?? [])->get()->map(fn (Product $p) => [
            'product_id' => $p->id,
            'sku' => $p->sku,
            'quantity' => 1,
        ])->all();
        $ownerId = KolScope::isAllScope($request->user()) ? ($opportunity->owner_id ?? $kol->owner_id) : (int) $request->user()->getAuthIdentifier();

        $collaboration = DB::transaction(function () use ($opportunity, $kol, $ownerId, $productRows): KolCollaboration {
            $item = KolCollaboration::create([
                'kol_id' => $kol->id,
                'opportunity_id' => $opportunity->id,
                'owner_id' => $ownerId,
                'title' => $opportunity->title,
                'cooperation_type' => $opportunity->cooperation_type,
                'status' => 'confirmed',
                'agreement_amount' => $opportunity->estimated_cost,
                'currency' => $opportunity->currency ?: 'USD',
                'agreed_at' => now()->toDateString(),
                'planned_publish_at' => $opportunity->expected_publish_at,
                'notes' => $opportunity->notes,
            ]);
            $this->replaceProducts($item, $productRows);
            $opportunity->update(['stage' => 'confirmed', 'status' => 'completed']);
            return $item;
        });
        $this->recordAudit($request, 'kol.collaboration.created_from_opportunity', $collaboration, null, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity']))], 201);
    }

    private function validated(Request $request, Kol $kol, ?KolCollaboration $collaboration = null): array
    {
        return $request->validate([
            'opportunity_id' => ['nullable', 'integer', Rule::exists('kol_opportunities', 'id')->where(fn ($q) => $q->where('kol_id', $kol->id)), Rule::unique('kol_collaborations', 'opportunity_id')->ignore($collaboration?->id)],
            'owner_id' => 'nullable|integer|exists:users,id',
            'title' => 'nullable|string|max:255',
            'post_url' => 'nullable|url|max:2048',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'status' => 'required|string|in:draft,confirmed,in_progress,completed,cancelled',
            'agreement_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'currency' => 'required|string|size:3',
            'agreed_at' => 'nullable|date',
            'planned_publish_at' => 'nullable|date',
            'deliverables' => 'nullable|string|max:5000',
            'fulfillment_required' => 'boolean',
            'notes' => 'nullable|string|max:5000',
            'products' => 'nullable|array|max:10',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.sku' => 'required|string|max:128',
            'products.*.quantity' => 'required|integer|min:1|max:9999',
        ]);
    }

    private function replaceProducts(KolCollaboration $collaboration, array $rows): void
    {
        $collaboration->products()->delete();
        foreach ($rows as $row) {
            $product = Product::findOrFail((int) $row['product_id']);
            abort_unless($product->sku === $row['sku'], 422, 'SKU 与 Product Master 不匹配');
            $collaboration->products()->create([
                'product_id' => $product->id,
                'sku_snapshot' => $product->sku,
                'product_name_snapshot' => $product->name,
                'quantity' => (int) $row['quantity'],
            ]);
        }
    }

    private function authorizeKol(Request $request, Kol $kol): void
    {
        if (! KolScope::isAllScope($request->user()) && $kol->owner_id !== (int) $request->user()->getAuthIdentifier()) abort(403);
    }

    private function authorizeCollaboration(Request $request, Kol $kol, KolCollaboration $item): void
    {
        $this->authorizeKol($request, $kol);
        abort_unless((int) $item->kol_id === (int) $kol->id, 404);
    }

    private function payload(KolCollaboration $item): array
    {
        return [
            'id' => $item->id,
            'kol_id' => $item->kol_id,
            'kol' => $item->relationLoaded('kol') && $item->kol ? ['id' => $item->kol->id, 'display_name' => $item->kol->display_name] : null,
            'opportunity_id' => $item->opportunity_id,
            'opportunity' => $item->relationLoaded('opportunity') && $item->opportunity ? ['id' => $item->opportunity->id, 'title' => $item->opportunity->title] : null,
            'owner_id' => $item->owner_id,
            'owner' => $item->relationLoaded('owner') && $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
            'title' => $item->title,
            'post_url' => $item->post_url,
            'cooperation_type' => $item->cooperation_type,
            'status' => $item->status,
            'agreement_amount' => $item->agreement_amount !== null ? (float) $item->agreement_amount : null,
            'currency' => $item->currency,
            'agreed_at' => $item->agreed_at?->format('Y-m-d'),
            'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
            'deliverables' => $item->deliverables,
            'fulfillment_required' => (bool) $item->fulfillment_required,
            'notes' => $item->notes,
            'products' => $item->relationLoaded('products') ? $item->products->map(fn ($row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->sku_snapshot,
                'name' => $row->product_name_snapshot,
                'quantity' => $row->quantity,
            ])->values()->all() : [],
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function snapshot(KolCollaboration $item): array
    {
        return ['kol_id' => $item->kol_id, 'opportunity_id' => $item->opportunity_id, 'owner_id' => $item->owner_id, 'status' => $item->status, 'post_url' => $item->post_url, 'fulfillment_required' => (bool) $item->fulfillment_required];
    }

    private function recordAudit(Request $request, string $action, KolCollaboration $item, ?array $before, ?array $after): void
    {
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), $action, 'kol_collaboration', $item->id, $before, $after);
    }
}
