<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use App\Modules\B2B\Sales\B2BSalesExactMoney;
use App\Modules\Finance\Application\B2BTradeCashAccountingService;
use App\Modules\Finance\Infrastructure\Models\FinanceB2BTradeCashEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class B2BTradeCashController
{
    public function __construct(private readonly B2BTradeCashAccountingService $cash) {}

    public function index(Request $request): Response
    {
        $rows=B2BTradeOrder::query()->with('organization:id,display_name')
            ->orderByDesc('id')->paginate(25);
        return Inertia::render('Finance/B2BTradeCash/Index',[
            'orders'=>$rows->through(fn (B2BTradeOrder $row)=>[
                'id'=>$row->id,
                'number'=>$row->trade_order_number,
                'company'=>$row->organization?->display_name,
                'summary'=>$this->cash->summary($row),
            ]),
        ]);
    }

    public function show(Request $request, B2BTradeOrder $tradeOrder): Response
    {
        $row=B2BTradeOrder::query()->with([
            'organization:id,display_name',
            'evidence',
        ])->findOrFail($tradeOrder->id);
        $events=FinanceB2BTradeCashEvent::query()->where('trade_order_id',$row->id)->orderBy('id')->get();
        return Inertia::render('Finance/B2BTradeCash/Show',[
            'trade'=>[
                'id'=>$row->id,'number'=>$row->trade_order_number,
                'status'=>$row->status,
                'company'=>$row->organization?->display_name,
                'currency'=>$row->currency,
                'summary'=>$this->cash->summary($row),
                'evidence'=>$row->evidence->map(fn (B2BTradeReceiptEvidence $e)=>[
                    'id'=>$e->id,
                    'source'=>$e->source,'reference'=>$e->reference,
                    'status'=>$e->status,'amount_micros'=>$e->claimed_amount_micros,
                    'currency'=>$e->currency,
                    'claimed_paid_on'=>$e->claimed_paid_on?->toDateString(),
                    'reviewed_at'=>$e->reviewed_at?->toIso8601String(),
                    'has_finance_event'=>$events->where('receipt_evidence_id',$e->id)->count()>0,
                ])->values()->all(),
                'cash_events'=>$events->map(fn(FinanceB2BTradeCashEvent $e)=>[
                    'id'=>$e->id,'event_type'=>$e->event_type,
                    'source'=>$e->source,'reference'=>$e->external_reference,
                    'amount_micros'=>$e->amount_micros,
                    'currency'=>$e->currency,'date'=>$e->value_date?->toDateString(),
                    'confirmed_at'=>$e->confirmed_at?->toIso8601String(),
                    'refund_of_id'=>$e->refund_of_id,
                ])->all(),
            ],
            'canConfirm'=>$request->user()->hasPermission('finance.b2b_cash_confirm'),
        ]);
    }

    public function confirmReceipt(
        Request $request, B2BTradeOrder $tradeOrder, B2BTradeReceiptEvidence $evidence
    ): RedirectResponse {
        abort_unless((int)$evidence->trade_order_id === (int)$tradeOrder->id,404);
        $data=$request->validate([
            'source'=>['required',Rule::in(['bank_transfer','provider_reference'])],
            'external_reference'=>['required','string','max:191'],
            'amount_decimal'=>['required','string','max:20'],
            'value_date'=>['required','date'],
            'attestation_method'=>['required',Rule::in(['manual_bank_statement','manual_provider_statement'])],
            'confirmation_note'=>['required','string','min:12','max:2000'],
        ]);
        try {
            $data['amount_micros']=B2BSalesExactMoney::decimalToMicros($data['amount_decimal']);
        } catch(InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount_decimal'=>'金额需大于零，最多两位小数']);
        }
        $this->cash->confirmReceipt($tradeOrder,$evidence,$data,(int)$request->user()->getAuthIdentifier());
        return back();
    }

    public function confirmRefund(
        Request $request, B2BTradeOrder $tradeOrder, FinanceB2BTradeCashEvent $cashEvent
    ): RedirectResponse {
        abort_unless((int)$cashEvent->trade_order_id === (int)$tradeOrder->id,404);
        $data=$request->validate([
            'source'=>['required',Rule::in(['bank_transfer','provider_reference'])],
            'external_reference'=>['required','string','max:191'],
            'amount_decimal'=>['required','string','max:20'],
            'value_date'=>['required','date'],
            'attestation_method'=>['required',Rule::in(['manual_bank_statement','manual_provider_statement'])],
            'confirmation_note'=>['required','string','min:12','max:2000'],
        ]);
        try {
            $data['amount_micros']=B2BSalesExactMoney::decimalToMicros($data['amount_decimal']);
        } catch (InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount_decimal'=>'退款金额必须是正数，最多两位小数']);
        }
        $this->cash->confirmRefund($tradeOrder,$cashEvent,$data,(int)$request->user()->getAuthIdentifier());
        return back();
    }
}
