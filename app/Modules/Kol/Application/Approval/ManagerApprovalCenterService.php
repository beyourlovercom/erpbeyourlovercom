<?php

namespace App\Modules\Kol\Application\Approval;

use App\Modules\Kol\Application\AffiliateWp\PayoutWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

final class ManagerApprovalCenterService
{
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const KOL_DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolApprovalDecision';
    private const COLLABORATION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaboration';
    private const PAYOUT_REQUEST = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolPayoutRequest';
    public function __construct(
        private readonly KolApprovalService $kols,
        private readonly CollaborationApprovalService $collaborations,
        private readonly PayoutWorkflowService $payouts,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(Authenticatable $actor): array
    {
        $this->authorize($actor);

        $kolClass = self::KOL; $decisionClass = self::KOL_DECISION; $collaborationClass = self::COLLABORATION; $payoutClass = self::PAYOUT_REQUEST;
        $kols = $kolClass::query()->whereNull('archived_at')->where('approval_status', 'pending')
            ->with('owner:id,name')->orderBy('approval_updated_at')->orderBy('id')->limit(100)->get();
        $kolIds = $kols->pluck('id');
        $latestReasons = $decisionClass::query()->whereIn('kol_id', $kolIds)->orderByDesc('id')->get()->unique('kol_id')->keyBy('kol_id');

        $collaborations = $collaborationClass::query()->where('approval_status', 'pending')
            ->with(['kol:id,display_name,owner_id', 'owner:id,name'])->orderBy('approval_updated_at')->orderBy('id')->limit(100)->get();

        $payouts = $payoutClass::query()->where('status', 'pending_approval')
            ->with(['items', 'kol:id,display_name,owner_id', 'requestedBy:id,name'])->orderBy('created_at')->orderBy('id')->limit(100)->get();

        return [
            'counts' => ['kols' => $kols->count(), 'collaborations' => $collaborations->count(), 'payouts' => $payouts->count()],
            'kols' => $kols->map(fn ($kol): array => [
                'id' => (int) $kol->id, 'display_name' => $kol->display_name,
                'owner_name' => $kol->owner?->name, 'created_at' => $kol->created_at?->toIso8601String(),
                'approval_updated_at' => $kol->approval_updated_at?->toIso8601String(),
                'reason_code' => $latestReasons->get($kol->id)?->reason_code,
            ])->values()->all(),
            'collaborations' => $collaborations->map(fn ($row): array => [
                'id' => (int) $row->id, 'kol_id' => (int) $row->kol_id,
                'kol_name' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'owner_name' => $row->owner?->name, 'title' => $row->title,
                'cooperation_type' => $row->cooperation_type, 'amount' => (string) $row->agreement_amount,
                'currency' => strtoupper((string) $row->currency), 'created_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
            'payouts' => $payouts->map(fn ($row): array => [
                'id' => (int) $row->id, 'kol_id' => (int) $row->kol_id,
                'kol_name' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'requester_name' => $row->requestedBy?->name ?? 'User #'.$row->requested_by_user_id,
                'affiliate_id' => (int) $row->affiliate_id, 'amount' => (string) $row->amount,
                'currency' => strtoupper((string) $row->currency),
                'referral_ids' => $row->items->pluck('referral_id')->map(fn ($id) => (int) $id)->values()->all(),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function approveKol(int $id, Authenticatable $actor): void { $this->authorize($actor); $kolClass = self::KOL; $this->kols->approve($kolClass::findOrFail($id), $actor); }
    public function rejectKol(int $id, string $reason, Authenticatable $actor): void { $this->authorize($actor); $kolClass = self::KOL; $this->kols->reject($kolClass::findOrFail($id), $actor, $reason); }
    public function approveCollaboration(int $id, Authenticatable $actor): void { $this->authorize($actor); $collaborationClass = self::COLLABORATION; $this->collaborations->approve($collaborationClass::findOrFail($id), $actor); }
    public function rejectCollaboration(int $id, string $reason, Authenticatable $actor): void { $this->authorize($actor); $collaborationClass = self::COLLABORATION; $this->collaborations->reject($collaborationClass::findOrFail($id), $actor, $reason); }
    public function approvePayout(int $id, Authenticatable $actor): void { $this->authorize($actor); $this->payouts->approve($id, $actor); }
    public function rejectPayout(int $id, string $reason, Authenticatable $actor): void { $this->authorize($actor); $this->payouts->reject($id, $reason, $actor); }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('kol.approvals.manage')) throw new AuthorizationException('Manager Approval Center permission is required.');
    }
}
