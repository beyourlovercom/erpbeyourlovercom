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
    private const COLLABORATION_DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaborationApprovalDecision';
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

        $collaborationDecisionClass = self::COLLABORATION_DECISION;
        $kolHistory = $decisionClass::query()->whereIn('outcome', ['approved', 'rejected'])
            ->with(['kol:id,display_name', 'actor:id,name'])->orderByDesc('decided_at')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($row): array => [
                'record_key' => 'kol:'.$row->id, 'type' => 'kol', 'object_id' => (int) $row->kol_id,
                'object_label' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'outcome' => (string) $row->outcome, 'actor_name' => $row->actor?->name ?? ($row->actor_user_id ? 'User #'.$row->actor_user_id : '系统'),
                'decided_at' => $row->decided_at?->toIso8601String(), 'source' => (string) $row->source,
                'detail' => $row->reason_code, 'reason' => $row->reason_text, 'amount' => null, 'currency' => null,
            ]);
        $collaborationHistory = $collaborationDecisionClass::query()->whereIn('outcome', ['approved', 'rejected'])
            ->with(['collaboration:id,kol_id,title', 'collaboration.kol:id,display_name', 'actor:id,name'])
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($row): array => [
                'record_key' => 'collaboration:'.$row->id, 'type' => 'collaboration', 'object_id' => (int) $row->collaboration_id,
                'object_label' => ($row->collaboration?->kol?->display_name ?? 'KOL').' · '.($row->collaboration?->title ?: '合作 #'.$row->collaboration_id),
                'outcome' => (string) $row->outcome, 'actor_name' => $row->actor?->name ?? ($row->actor_user_id ? 'User #'.$row->actor_user_id : '系统'),
                'decided_at' => $row->decided_at?->toIso8601String(), 'source' => (string) $row->source,
                'detail' => $row->cooperation_type, 'reason' => $row->reason_text,
                'amount' => $row->agreement_amount === null ? null : (string) $row->agreement_amount,
                'currency' => $row->currency === null ? null : strtoupper((string) $row->currency),
            ]);
        $payoutHistory = $payoutClass::query()->where(function ($query): void {
                $query->whereNotNull('approved_at')->orWhereNotNull('rejected_at');
            })
            ->with(['kol:id,display_name,owner_id', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->orderByDesc('id')->limit(300)->get()
            ->map(function ($row): array {
                $rejected = $row->rejected_at !== null;
                return [
                    'record_key' => 'payout:'.$row->id, 'type' => 'payout', 'object_id' => (int) $row->id,
                    'object_label' => 'Payout #'.$row->id.' · '.($row->kol?->display_name ?? 'KOL #'.$row->kol_id),
                    'outcome' => $rejected ? 'rejected' : 'approved',
                    'actor_name' => $rejected ? ($row->rejectedBy?->name ?? 'User #'.$row->rejected_by_user_id) : ($row->approvedBy?->name ?? 'User #'.$row->approved_by_user_id),
                    'decided_at' => ($rejected ? $row->rejected_at : $row->approved_at)?->toIso8601String(),
                    'source' => $rejected ? 'manager_payout_rejected' : 'manager_payout_approved',
                    'detail' => 'Affiliate #'.$row->affiliate_id, 'reason' => $row->rejection_reason,
                    'amount' => (string) $row->amount, 'currency' => strtoupper((string) $row->currency),
                ];
            });
        $history = $kolHistory->concat($collaborationHistory)->concat($payoutHistory)
            ->sortByDesc('decided_at')->take(300)->values();

        return [
            'counts' => ['kols' => $kols->count(), 'collaborations' => $collaborations->count(), 'payouts' => $payouts->count(), 'history' => $history->count()],
            'history' => $history->all(),
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
