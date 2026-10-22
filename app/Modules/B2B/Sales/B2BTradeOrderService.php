<?php

namespace App\Modules\B2B\Sales;

use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesQuote;
use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class B2BTradeOrderService
{
    public function draft(B2BSalesOpportunity $opportunity, B2BSalesQuote $quote, int $actorId): B2BTradeOrder
    {
        if ((int) $quote->opportunity_id !== (int) $opportunity->id || $opportunity->status === 'lost') {
            throw ValidationException::withMessages(['quote_id' => '报价必须属于有效的本企业商机']);
        }
        if ((int) $quote->total_micros <= 0 || ! preg_match('/^[A-Z]{3}$/', (string) $quote->currency)) {
            throw ValidationException::withMessages(['quote_id' => '必须选择具有合法金额和币种的报价']);
        }
        return DB::transaction(function () use ($opportunity, $quote, $actorId): B2BTradeOrder {
            $existing = B2BTradeOrder::query()->where('quote_id', $quote->id)->first();
            if ($existing) return $existing;

            $order = B2BTradeOrder::query()->create([
                'trade_order_number' => 'B2B-TRD-'.strtoupper(bin2hex(random_bytes(8))),
                'organization_id' => $opportunity->organization_id,
                'opportunity_id' => $opportunity->id,
                'quote_id' => $quote->id,
                'owner_id' => $opportunity->owner_id,
                'created_by' => $actorId,
                'status' => 'draft',
                'currency' => $quote->currency,
                'receivable_micros' => (int) $quote->total_micros,
                'quote_snapshot' => [
                    'quote_number' => $quote->quote_number,
                    'version' => $quote->version,
                    'line_items' => $quote->line_items,
                    'subtotal_micros' => (int) $quote->subtotal_micros,
                    'discount_micros' => (int) $quote->discount_micros,
                    'tax_micros' => (int) $quote->tax_micros,
                    'total_micros' => (int) $quote->total_micros,
                ],
            ]);
            $this->audit($order, $actorId, 'draft_created', ['quote_id' => $quote->id]);
            return $order;
        });
    }

    public function approve(B2BTradeOrder $order, int $approverId): B2BTradeOrder
    {
        return DB::transaction(function () use ($order, $approverId): B2BTradeOrder {
            $row = B2BTradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($row->status !== 'draft' || (int) $row->created_by === $approverId) {
                throw ValidationException::withMessages(['approval' => '仅草稿可审批，且必须由第二位授权人员审核']);
            }
            if ($row->opportunity()->value('status') !== 'won') {
                throw ValidationException::withMessages(['approval' => '仅已成交 Won 商机可审批贸易订单，报价和商机意向不等于合同']);
            }
            // The approved order is a contractual receivable *draft*; NOT a ledger entry.
            // Buyer acceptance, bank settlement and Accounting posting are not implied.
            $row->forceFill(['status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now()])->save();
            $this->audit($row, $approverId, 'trade_approved', ['quote_id' => $row->quote_id]);
            return $row;
        });
    }

    public function recordEvidence(
        B2BTradeOrder $order, array $data, int $actorId
    ): B2BTradeReceiptEvidence {
        return DB::transaction(function () use ($order, $data, $actorId): B2BTradeReceiptEvidence {
            $row = B2BTradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['trade_order' => '必须先审批贸易订单']);
            }
            if (! in_array($data['source'], ['bank_transfer','provider_reference'], true)
                || trim($data['reference']) === ''
                || (int) $data['claimed_amount_micros'] <= 0
                || strtoupper($data['currency']) !== $row->currency) {
                throw ValidationException::withMessages(['reference' => '收款凭证必须提供合法来源、编号、金额和订单币种']);
            }
            if (B2BTradeReceiptEvidence::query()->where('source', $data['source'])
                ->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => '凭证编号已经被认领，不能重复关联']);
            }
            $e = B2BTradeReceiptEvidence::query()->create([
                'trade_order_id' => $row->id,
                'recorded_by' => $actorId,
                'source' => $data['source'],
                'reference' => $data['reference'],
                'currency' => $row->currency,
                'claimed_amount_micros' => (int) $data['claimed_amount_micros'],
                'claimed_paid_on' => $data['claimed_paid_on'] ?? null,
                'status' => 'pending_review',
            ]);
            $this->audit($row, $actorId, 'receipt_evidence_submitted', ['evidence_id' => $e->id]);
            return $e;
        });
    }

    public function reviewEvidence(
        B2BTradeReceiptEvidence $evidence, int $reviewerId, string $decision, string $reason
    ): B2BTradeReceiptEvidence {
        return DB::transaction(function () use ($evidence, $reviewerId, $decision, $reason): B2BTradeReceiptEvidence {
            $e = B2BTradeReceiptEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($e->status !== 'pending_review' || (int) $e->recorded_by === $reviewerId
                || ! in_array($decision, ['reviewed','rejected'], true) || trim($reason) === '') {
                throw ValidationException::withMessages(['review' => '凭证只能由另一位审核员审核，且必须提供审核结论与原因']);
            }
            // "reviewed" means DOCUMENT REVIEW only. It cannot be counted as cash
            // or sent to Accounting without a separate, verified Finance adapter.
            $e->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();
            $this->audit($e->tradeOrder, $reviewerId, 'receipt_evidence_'.$decision, ['evidence_id' => $e->id]);
            return $e;
        });
    }

    private function audit(B2BTradeOrder $order, int $actorId, string $event, array $context): void
    {
        $order->auditEvents()->create([
            'actor_id' => $actorId,
            'event_type' => $event,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
