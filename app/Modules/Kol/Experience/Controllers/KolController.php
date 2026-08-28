<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\KolService;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolController
{
    public function __construct(
        private readonly KolService $kols,
        private readonly SavedViewService $views,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ownerScopeId = KolScope::effectiveOwnerId($user);
        $canManageViews = KolScope::canManageViews($user);

        $filters = $request->only(['search', 'owner_id', 'status', 'relationship_stage', 'country_code', 'platform', 'followers_min', 'followers_max', 'level', 'city', 'content_format', 'mcn', 'view', 'sort', 'direction']);
        $kols = $this->kols->list($filters, $ownerScopeId);
        $savedViews = $this->views->forUser('kol', (int) $user->getAuthIdentifier(), $canManageViews);

        $ownerOptions = [];
        if ($canManageViews) {
            $ownerOptions = User::whereHas('roles', function ($q): void {
                $q->whereHas('permissions', function ($q): void {
                    $q->where('key', 'kol.view');
                });
            })->orWhereHas('customPermissions', function ($q): void {
                $q->where('key', 'kol.view');
            })->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        return Inertia::render('Kol/Index', [
            'kols' => $kols->through(fn (Kol $kol): array => $this->kolData($kol))->toArray(),
            'filters' => $filters,
            'savedViews' => $savedViews,
            'ownerOptions' => $ownerOptions,
            'canOperate' => KolScope::canOperate($user),
            'canManageViews' => $canManageViews,
            'isAllScope' => KolScope::isAllScope($user),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        $ownerOptions = [];
        if (KolScope::canManageViews($user)) {
            $ownerOptions = User::where('is_active', true)->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        return Inertia::render('Kol/Create', [
            'ownerOptions' => $ownerOptions,
            'currentUserId' => (int) $user->getAuthIdentifier(),
            'canManageViews' => KolScope::canManageViews($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|in:US,GB,DE,FR,CA,EU,AU,ASIA,PH,OTHER',
            'language' => 'nullable|string|in:en,es,de,fr,it,zh,other',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:64',
            'contact_whatsapp' => 'nullable|string|max:64',
            'other_contact' => 'nullable|string|max:255',
            'preferred_contact_method' => 'nullable|string|in:phone,email,whatsapp,other',
            'tax_id' => 'nullable|string|max:128',
            'payout_method' => 'nullable|string|in:paypal,bank,other',
            'payout_account' => 'nullable|string|max:512',
            'shipping_recipient_name' => 'nullable|string|max:255',
            'shipping_country' => 'nullable|string|max:8',
            'shipping_province_state' => 'nullable|string|max:128',
            'shipping_city' => 'nullable|string|max:128',
            'shipping_postal_code' => 'nullable|string|max:32',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'level' => 'nullable|string|in:头部,腰部,尾部,素人',
            'city' => 'nullable|string|max:128',
            'categories' => 'nullable|array|max:20',
            'categories.*' => 'string|max:64',
            'products' => 'nullable|array|max:20',
            'products.*' => 'string|max:64',
            'content_format' => 'nullable|string|in:图文,短视频,直播,图文+视频,混合',
            'product_quantity' => 'nullable|integer|min:0',
            'store_count' => 'nullable|integer|min:0',
            'internal_contact' => 'nullable|string|max:255',
            'mcn_agency' => 'nullable|string|max:255',
            'is_mcn_managed' => 'boolean',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
            'sales_performance_entries' => 'nullable|array|max:20',
            'sales_performance_entries.*.order_count' => 'nullable|integer|min:1',
            'sales_performance_entries.*.recorded_at' => 'nullable|date',
            'sales_performance_entries.*.source' => 'nullable|string|max:64',
            'sales_performance_entries.*.note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            $data['owner_id'] = (int) $user->getAuthIdentifier();
        }

        $kol = $this->kols->create($data, $data['social_accounts'] ?? [], $user, $data['sales_performance_entries'] ?? []);

        return redirect()->route('kol.library.show', $kol)->with('success', 'KOL 已创建');
    }

    public function show(Request $request, Kol $kol): Response
    {
        $this->authorizeKolAccess($request, $kol);
        $kol->load(['owner', 'socialAccounts.latestMetric', 'socialAccounts.metrics']);

        return Inertia::render('Kol/Show', [
            'kol' => $this->kolDetailData($kol),
            'canOperate' => KolScope::canOperate($request->user()),
            'canManageViews' => KolScope::canManageViews($request->user()),
        ]);
    }

    public function edit(Request $request, Kol $kol): Response
    {
        $this->authorizeKolAccess($request, $kol);
        $kol->load(['socialAccounts.latestMetric']);

        $user = $request->user();
        $ownerOptions = [];
        if (KolScope::canManageViews($user)) {
            $ownerOptions = User::where('is_active', true)->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        return Inertia::render('Kol/Edit', [
            'kol' => $this->kolDetailData($kol),
            'ownerOptions' => $ownerOptions,
            'canManageViews' => KolScope::canManageViews($user),
        ]);
    }

    public function update(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);

        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|in:US,GB,DE,FR,CA,EU,AU,ASIA,PH,OTHER',
            'language' => 'nullable|string|in:en,es,de,fr,it,zh,other',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:64',
            'contact_whatsapp' => 'nullable|string|max:64',
            'other_contact' => 'nullable|string|max:255',
            'preferred_contact_method' => 'nullable|string|in:phone,email,whatsapp,other',
            'tax_id' => 'nullable|string|max:128',
            'payout_method' => 'nullable|string|in:paypal,bank,other',
            'payout_account' => 'nullable|string|max:512',
            'shipping_recipient_name' => 'nullable|string|max:255',
            'shipping_country' => 'nullable|string|max:8',
            'shipping_province_state' => 'nullable|string|max:128',
            'shipping_city' => 'nullable|string|max:128',
            'shipping_postal_code' => 'nullable|string|max:32',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'level' => 'nullable|string|in:头部,腰部,尾部,素人',
            'city' => 'nullable|string|max:128',
            'categories' => 'nullable|array|max:20',
            'categories.*' => 'string|max:64',
            'products' => 'nullable|array|max:20',
            'products.*' => 'string|max:64',
            'content_format' => 'nullable|string|in:图文,短视频,直播,图文+视频,混合',
            'product_quantity' => 'nullable|integer|min:0',
            'store_count' => 'nullable|integer|min:0',
            'internal_contact' => 'nullable|string|max:255',
            'mcn_agency' => 'nullable|string|max:255',
            'is_mcn_managed' => 'boolean',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.id' => 'nullable|integer',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
            'sales_performance_entries' => 'nullable|array|max:20',
            'sales_performance_entries.*.order_count' => 'nullable|integer|min:1',
            'sales_performance_entries.*.recorded_at' => 'nullable|date',
            'sales_performance_entries.*.source' => 'nullable|string|max:64',
            'sales_performance_entries.*.note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            unset($data['owner_id']);
        }

        $this->kols->update($kol, $data, $data['social_accounts'] ?? [], $user, $data['sales_performance_entries'] ?? []);

        return redirect()->route('kol.library.show', $kol)->with('success', 'KOL 已更新');
    }

    public function archive(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $this->kols->archive($kol, $request->user());

        return redirect()->route('kol.library')->with('success', 'KOL 已归档');
    }

    public function reactivate(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $this->kols->reactivate($kol, $request->user());

        return redirect()->route('kol.library.show', $kol)->with('success', 'KOL 已重新激活');
    }

    private function authorizeKolAccess(Request $request, Kol $kol): void
    {
        $user = $request->user();
        if (! KolScope::isAllScope($user) && $kol->owner_id !== (int) $user->getAuthIdentifier()) {
            abort(403);
        }
    }

    private function kolData(Kol $kol): array
    {
        $latestFollowers = null;
        $primaryPlatform = null;

        foreach ($kol->socialAccounts as $account) {
            if ($account->is_active) {
                if ($primaryPlatform === null) {
                    $primaryPlatform = $account->platform;
                }
                $metric = $account->latestMetric;
                if ($metric && ($latestFollowers === null || $metric->followers > $latestFollowers)) {
                    $latestFollowers = $metric->followers;
                }
            }
        }

        return [
            'id' => $kol->id,
            'display_name' => $kol->display_name,
            'country_code' => $kol->country_code,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'owner' => $kol->owner ? ['id' => $kol->owner->id, 'name' => $kol->owner->name] : null,
            'primary_platform' => $primaryPlatform,
            'latest_followers' => $latestFollowers,
            'is_100k_plus' => $latestFollowers !== null && $latestFollowers >= 100000,
            'joined_at' => $kol->joined_at?->format('Y-m-d'),
            'level' => $kol->level,
            'city' => $kol->city,
            'categories' => $kol->categories ?? [],
            'products' => $kol->products ?? [],
            'content_format' => $kol->content_format,
            'product_quantity' => $kol->product_quantity,
            'store_count' => $kol->store_count,
            'internal_contact' => $kol->internal_contact,
            'mcn_agency' => $kol->mcn_agency,
            'is_mcn_managed' => $kol->is_mcn_managed,
            'historical_order_count' => (int) ($kol->historical_order_count ?? 0),
        ];
    }

    private function kolDetailData(Kol $kol): array
    {
        return [
            'id' => $kol->id,
            'display_name' => $kol->display_name,
            'legal_name' => $kol->legal_name,
            'country_code' => $kol->country_code,
            'language' => $kol->language,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'cooperation_type' => $kol->cooperation_type,
            'owner_id' => $kol->owner_id,
            'owner' => $kol->owner ? ['id' => $kol->owner->id, 'name' => $kol->owner->name] : null,
            'joined_at' => $kol->joined_at?->format('Y-m-d'),
            'archived_at' => $kol->archived_at?->format('Y-m-d H:i:s'),
            'notes' => $kol->notes,
            'contact_email' => $kol->contact_email,
            'contact_phone' => $kol->contact_phone,
            'tax_id' => $kol->tax_id,
            'payout_method' => $kol->payout_method,
            'payout_account' => $kol->payout_account,
            'shipping_recipient_name' => $kol->shipping_recipient_name,
            'shipping_country' => $kol->shipping_country,
            'shipping_province_state' => $kol->shipping_province_state,
            'shipping_city' => $kol->shipping_city,
            'shipping_postal_code' => $kol->shipping_postal_code,
            'shipping_address_line_1' => $kol->shipping_address_line_1,
            'shipping_address_line_2' => $kol->shipping_address_line_2,
            'contact_whatsapp' => $kol->contact_whatsapp,
            'other_contact' => $kol->other_contact,
            'preferred_contact_method' => $kol->preferred_contact_method,
            'level' => $kol->level,
            'city' => $kol->city,
            'categories' => $kol->categories ?? [],
            'products' => $kol->products ?? [],
            'content_format' => $kol->content_format,
            'product_quantity' => $kol->product_quantity,
            'store_count' => $kol->store_count,
            'internal_contact' => $kol->internal_contact,
            'mcn_agency' => $kol->mcn_agency,
            'is_mcn_managed' => $kol->is_mcn_managed,
            'historical_order_count' => $kol->historicalOrderCount(),
            'sales_performance_history' => $kol->salesPerformances()->limit(20)->get()->map(fn ($p) => [
                'id' => $p->id,
                'order_count' => $p->order_count,
                'recorded_at' => $p->recorded_at?->format('Y-m-d'),
                'source' => $p->source,
                'note' => $p->note,
            ])->values()->all(),
            'social_accounts' => $kol->socialAccounts->map(fn ($sa) => [
                'id' => $sa->id,
                'platform' => $sa->platform,
                'handle' => $sa->handle,
                'profile_url' => $sa->profile_url,
                'is_active' => $sa->is_active,
                'latest_metric' => $sa->latestMetric ? [
                    'followers' => $sa->latestMetric->followers,
                    'raw_followers_value' => $sa->latestMetric->raw_followers_value,
                    'measured_at' => $sa->latestMetric->measured_at?->format('Y-m-d'),
                ] : null,
            ])->values()->all(),
        ];
    }
}
