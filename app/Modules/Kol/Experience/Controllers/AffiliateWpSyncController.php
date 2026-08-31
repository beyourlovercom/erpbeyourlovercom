<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateBindingService;
use App\Modules\Kol\Application\AffiliateWp\AffiliateSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class AffiliateWpSyncController
{
    public function __construct(
        private readonly AffiliateSnapshotSyncService $syncService,
        private readonly AffiliateBindingService $bindingService,
        private readonly KolProvisioningService $provisioningService,
        private readonly AffiliateWpGateway $gateway,
    ) {}

    public function index(Request $request): Response
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        $canManageAffiliates = $request->user()->can('kol.affiliates.manage');
        $kolOptions = [];
        $ownerOptions = [];

        if ($canManageAffiliates) {
            $kolOptions = Kol::query()
                ->whereNull('archived_at')
                ->orderBy('display_name')
                ->get(['id', 'display_name'])
                ->map(fn (Kol $kol): array => ['value' => $kol->id, 'label' => $kol->display_name])
                ->values()
                ->all();

            $ownerOptions = User::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereHas('roles.permissions', fn ($permission): mixed => $permission->where('key', 'kol.view'))
                        ->orWhereHas('customPermissions', fn ($permission): mixed => $permission->where('key', 'kol.view'));
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
                ->values()
                ->all();
        }

        return Inertia::render('Kol/AffiliateWp/Index', [
            'canManageApprovals' => $request->user()->can('kol.approvals.manage'),
            'canManageAffiliates' => $canManageAffiliates,
            'affiliates' => $this->syncService->workspaceRows($connectionKey),
            'provisioningCandidates' => $this->provisioningCandidates($request, $connectionKey, $canManageAffiliates),
            'kolOptions' => $kolOptions,
            'ownerOptions' => $ownerOptions,
        ]);
    }

    public function bind(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:kol_affiliate_accounts,id',
            'kol_id' => 'required|integer|exists:kols,id',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $this->bindingService->bind($connectionKey, (int) $data['account_id'], (int) $data['kol_id'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_binding' => $exception->getMessage()]);
        }

        return back()->with('success', 'Affiliate 已绑定到 ERP KOL。');
    }

    public function convert(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:kol_affiliate_accounts,id',
            'owner_id' => 'required|integer|exists:users,id',
            'display_name' => 'nullable|string|max:255',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $result = $this->bindingService->convert(
                $connectionKey,
                (int) $data['account_id'],
                (int) $data['owner_id'],
                $data['display_name'] ?? null,
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_binding' => $exception->getMessage()]);
        }

        return back()->with('success', 'Affiliate 已转换并绑定到新 KOL #'.$result['kol']->getKey().'。');
    }

    public function provision(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kol_id' => 'required|integer|exists:kols,id',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $account = $this->provisioningService->provision($connectionKey, (int) $data['kol_id'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_provision' => $exception->getMessage()]);
        }

        $status = $account->provisioning_status;

        if ($status === 'conflict') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 遇到 Affiliate ID 冲突。请核对 ERP mirror 与 Provider 后再安全重试。']);
        }
        if ($status === 'readback_mismatch') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 已调用 Provider，但 read-back 未确认 active + Affiliate ID + WordPress User ID。']);
        }
        if ($status === 'error') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 调用失败。已保留可重试状态与稳定 idempotency key。']);
        }

        return back()->with('success', $status === 'active'
            ? 'KOL 已成功配置为 AffiliateWP Influencer。'
            : 'KOL Provision 请求已提交（当前状态：'.$status.'）。');
    }

    /** @return list<array<string,mixed>> */
    private function provisioningCandidates(Request $request, string $connectionKey, bool $canManageAffiliates): array
    {
        $query = Kol::query()
            ->whereNull('archived_at')
            ->where('approval_status', 'approved')
            ->orderBy('display_name')
            ->orderBy('id');

        if (! KolScope::isAllScope($request->user())) {
            $query->where('owner_id', (int) $request->user()->getAuthIdentifier());
        }

        $kols = $query->get(['id', 'display_name', 'contact_email', 'owner_id', 'approval_status']);
        $accounts = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->whereIn('kol_id', $kols->pluck('id'))
            ->get()
            ->keyBy('kol_id');

        return $kols->map(function (Kol $kol) use ($accounts, $canManageAffiliates): array {
            /** @var KolAffiliateAccount|null $account */
            $account = $accounts->get($kol->id);
            $status = $account?->provisioning_status ?? 'not_requested';
            $hasExternalAffiliate = $account !== null
                && $account->affiliate_id !== null
                && $account->origin !== 'erp_provisioned';

            $disabledReason = match (true) {
                ! $canManageAffiliates => '需要 Affiliate 管理权限',
                $hasExternalAffiliate => '该 KOL 已绑定外部 Affiliate，无需 Provision',
                $status === 'active' => '该 KOL 已完成 Provision',
                $status === 'in_progress' => 'Provision 处理中，请刷新状态后再操作',
                default => null,
            };

            return [
                'kol_id' => $kol->id,
                'display_name' => $kol->display_name,
                'contact_email' => $kol->contact_email,
                'approval_status' => $kol->approval_status,
                'affiliate_id' => $account?->affiliate_id,
                'wordpress_user_id' => $account?->wordpress_user_id,
                'provider_status' => $account?->provider_status,
                'provisioning_status' => $status,
                'last_error_code' => $account?->last_error_code,
                'last_error_message' => $account?->last_error_message,
                'can_provision' => $disabledReason === null,
                'disabled_reason' => $disabledReason,
            ];
        })->values()->all();
    }

    public function sync(Request $request): RedirectResponse
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $result = $this->syncService->sync($connectionKey, $request->user());
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors([
                'affiliatewp_sync' => 'AffiliateWP 同步失败。请检查连接器状态后重试。',
            ]);
        }

        return back()->with('success', sprintf(
            'AffiliateWP 同步完成：%d 条已同步，%d 条标记为未出现在最新快照。',
            $result['synced'],
            $result['marked_missing'],
        ));
    }
}
