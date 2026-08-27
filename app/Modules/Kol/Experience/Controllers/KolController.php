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

        $filters = $request->only(['search', 'owner_id', 'status', 'relationship_stage', 'country_code', 'platform', 'followers_min', 'followers_max', 'view', 'sort', 'direction']);
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
            'country_code' => 'nullable|string|max:8',
            'language' => 'nullable|string|max:16',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|max:32',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            $data['owner_id'] = (int) $user->getAuthIdentifier();
        }

        $kol = $this->kols->create($data, $data['social_accounts'] ?? [], $user);

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
            'country_code' => 'nullable|string|max:8',
            'language' => 'nullable|string|max:16',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|max:32',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.id' => 'nullable|integer',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            unset($data['owner_id']);
        }

        $this->kols->update($kol, $data, $data['social_accounts'] ?? [], $user);

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
