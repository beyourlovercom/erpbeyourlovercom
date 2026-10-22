<?php

namespace App\Modules\Finance\Application;

use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use App\Modules\Finance\Infrastructure\Models\FinanceB2BTradeCashEvent;
use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class B2BTradeCashAccountingService
{
    /**
     * Finance-only MANUAL source attestation. No bank API or actual funds movement is initiated.
     * The authorized Finance reviewer must independently examine an external bank/provider
     * statement and deliberately repeat its transaction identifier.
     */
    public function confirmReceipt(
        B2BTradeOrder $order, B2BTradeReceiptEvidence $evidence,
        array $attestation, int $financeUserId
    ): FinanceB2BTradeCashEvent {
        $this->authorizedFinance($financeUserId);
        return DB::transaction(function () use ($order, $evidence, $attestation, $financeUserId) {
            $trade=B2BTradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            $claim=B2BTradeReceiptEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($trade->status !== 'approved' || $claim->status !== 'reviewed'
                || (int)$claim->trade_order_id !== (int)$trade->id
                || ! $claim->reviewed_at || (int)$claim->recorded_by === $financeUserId
                || (int)$claim->reviewed_by === $financeUserId) {
                throw ValidationException::withMessages(['confirmation'=>'订单及凭证均须审批、且财务核实人必须独立']);
            }
            $source=(string)($attestation['source']??'');
            $reference=trim((string)($attestation['external_reference']??''));
            $method=(string)($attestation['attestation_method']??'');
            $note=trim((string)($attestation['confirmation_note']??''));
            $amount=$attestation['amount_micros']??null;
            if (! in_array($source,['bank_transfer','provider_reference'],true)
                || $source !== $claim->source || $reference === '' || $reference !== $claim->reference
                || ! in_array($method,['manual_bank_statement','manual_provider_statement'],true)
                || ($source === 'bank_transfer') !== ($method === 'manual_bank_statement')
                || ! is_int($amount) || $amount <= 0 || $amount !== (int)$claim->claimed_amount_micros
                || $claim->currency !== $trade->currency || mb_strlen($note) < 12
                || ! $claim->claimed_paid_on || empty($attestation['value_date'])) {
                throw ValidationException::withMessages([
                    'confirmation'=>'必须由财务独立核对银行/平台账单的编号、币种、金额与入账日期，并记录依据',
                ]);
            }
            $this->rejectAlreadyUsedWooPaymentReference($source,$reference);
            if (FinanceB2BTradeCashEvent::query()->where('source',$source)
                ->where('external_reference',$reference)->exists()
                || FinanceB2BTradeCashEvent::query()->where('receipt_evidence_id',$claim->id)->exists()) {
                throw ValidationException::withMessages(['external_reference'=>'同一收款不得重复财务认领']);
            }
            return FinanceB2BTradeCashEvent::query()->create([
                'trade_order_id'=>$trade->id,
                'receipt_evidence_id'=>$claim->id,
                'refund_of_id'=>null,
                'confirmed_by'=>$financeUserId,
                'event_type'=>'receipt',
                'source'=>$source,
                'external_reference'=>$reference,
                'currency'=>$trade->currency,
                'amount_micros'=>$amount,
                'value_date'=>$attestation['value_date'],
                'attestation_method'=>$method,
                'confirmation_note'=>$note,
                'confirmed_at'=>now(),
            ]);
        });
    }

    public function confirmRefund(
        B2BTradeOrder $order, FinanceB2BTradeCashEvent $original,
        array $attestation, int $financeUserId
    ): FinanceB2BTradeCashEvent {
        $this->authorizedFinance($financeUserId);
        return DB::transaction(function () use ($order,$original,$attestation,$financeUserId) {
            $trade=B2BTradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            $receipt=FinanceB2BTradeCashEvent::query()->lockForUpdate()->findOrFail($original->id);
            $amount=$attestation['amount_micros']??null;
            $source=(string)($attestation['source']??'');
            $ref=trim((string)($attestation['external_reference']??''));
            $note=trim((string)($attestation['confirmation_note']??''));
            $method=(string)($attestation['attestation_method']??'');
            if ($trade->status !== 'approved'
                || $receipt->event_type !== 'receipt' || (int)$receipt->trade_order_id !== (int)$trade->id
                || (int)$receipt->confirmed_by === $financeUserId
                || ! is_int($amount) || $amount <= 0
                || ! in_array($source,['bank_transfer','provider_reference'],true)
                || $ref === '' || empty($attestation['value_date']) || mb_strlen($note) < 12
                || ! in_array($method,['manual_bank_statement','manual_provider_statement'],true)
                || ($source === 'bank_transfer') !== ($method === 'manual_bank_statement')
                || FinanceB2BTradeCashEvent::query()->where('source',$source)
                    ->where('external_reference',$ref)->exists()) {
                throw ValidationException::withMessages(['refund'=>'退款须由另一名财务人员核实原收款、唯一退款流水及账单依据']);
            }
            $this->rejectAlreadyUsedWooPaymentReference($source,$ref);
            $refunded=(int)FinanceB2BTradeCashEvent::query()->where('refund_of_id',$receipt->id)
                ->where('event_type','refund')->sum('amount_micros');
            if ($amount > (int)$receipt->amount_micros - $refunded) {
                throw ValidationException::withMessages(['refund'=>'累计退款不可超过对应原始收款']);
            }
            return FinanceB2BTradeCashEvent::query()->create([
                'trade_order_id'=>$trade->id,
                'receipt_evidence_id'=>null,
                'refund_of_id'=>$receipt->id,
                'confirmed_by'=>$financeUserId,
                'event_type'=>'refund',
                'source'=>$source,
                'external_reference'=>$ref,
                'currency'=>$trade->currency,
                'amount_micros'=>$amount,
                'value_date'=>$attestation['value_date'],
                'attestation_method'=>$method,
                'confirmation_note'=>$note,
                'confirmed_at'=>now(),
            ]);
        });
    }

    /** @return array<string,mixed> Derived ONLY from Finance-owned confirmed events. */
    public function summary(B2BTradeOrder $order): array
    {
        // Reload current approval before deciding whether receivable exists.
        $order->refresh();
        $base=['currency'=>$order->currency,'receivable_micros'=>$order->status==='approved'
            ? (int)$order->receivable_micros : null,
            'gross_confirmed_micros'=>null,'refunded_micros'=>null,'net_confirmed_micros'=>null,
            'outstanding_micros'=>null,'overpaid_micros'=>null,
            'source_of_truth'=>'finance_b2b_trade_cash_events',
            'status'=>'pending_trade_approval'];
        if ($order->status !== 'approved') return $base;
        $events=FinanceB2BTradeCashEvent::query()->where('trade_order_id',$order->id)->get();
        $gross=0; $refund=0;
        foreach ($events as $event) {
            if ($event->currency !== $order->currency) {
                return [...$base,'status'=>'review_required'];
            }
            if ($event->event_type === 'receipt') $gross += (int)$event->amount_micros;
            elseif ($event->event_type === 'refund') $refund += (int)$event->amount_micros;
            else return [...$base,'status'=>'review_required'];
        }
        if ($refund > $gross) return [...$base,'status'=>'review_required'];
        $net=$gross-$refund;
        $receivable=(int)$order->receivable_micros;
        return [...$base,
            'status'=>match(true) {
                $net === 0 => 'unpaid',
                $net < $receivable => 'partial',
                $net === $receivable => 'paid',
                default => 'overpaid',
            },
            'gross_confirmed_micros'=>$gross,
            'refunded_micros'=>$refund,
            'net_confirmed_micros'=>$net,
            'outstanding_micros'=>max(0,$receivable-$net),
            'overpaid_micros'=>max(0,$net-$receivable),
            'receipts_count'=>$events->where('event_type','receipt')->count(),
            'refunds_count'=>$events->where('event_type','refund')->count(),
        ];
    }

    private function rejectAlreadyUsedWooPaymentReference(string $source, string $reference): void
    {
        if ($source !== 'provider_reference') {
            return;
        }
        if (OrderFinancial::query()->where('payment_transaction_id',$reference)
            ->orWhere('provider_reference',$reference)->exists()) {
            throw ValidationException::withMessages([
                'external_reference'=>'该平台交易编号已属于 Woo 客户财务订单，禁止再次确认为 B2B 收款',
            ]);
        }
    }

    private function authorizedFinance(int $financeUserId): void
    {
        $actor=User::query()->findOrFail($financeUserId);
        abort_unless($actor->is_active && $actor->hasPermission('accounting.view')
            && $actor->hasPermission('finance.b2b_cash_confirm'),403);
    }
}
