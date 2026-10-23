<?php

namespace App\Modules\B2B\Experience\Controllers;

use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesQuote;
use App\Modules\B2B\Sales\B2BTradeOrderService;
use App\Modules\Finance\Application\B2BTradeCashAccountingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Inertia\Inertia;
use Inertia\Response;

final class B2BTradeOrderController
{
    public function __construct(private readonly B2BTradeOrderService $service) {}

    private function visible(Request $request): Builder
    {
        return B2BTradeOrder::query()->when(
            ! $request->user()->hasPermission('b2b.view_all'),
            fn (Builder $query) => $query->where('owner_id', $request->user()->getAuthIdentifier())
        );
    }

    public function index(Request $request): Response
    {
        $scope = $this->visible($request);
        $rows = $scope->with(['organization:id,display_name', 'opportunity:id,title'])
            ->orderByDesc('id')->paginate(25);
        $opportunities = B2BSalesOpportunity::query()->when(
            ! $request->user()->hasPermission('b2b.view_all'),
            fn (Builder $query) => $query->where('owner_id', $request->user()->getAuthIdentifier())
        )->where('status', '!=', 'lost')->with(['organization:id,display_name','quotes:id,opportunity_id,quote_number,version,currency,total_micros'])
        ->whereHas('quotes')->orderByDesc('id')->limit(60)->get();

        return Inertia::render('B2B/Trade/Index', [
            'tradeOrders' => $rows->through(fn (B2BTradeOrder $row) => [
                'id'=>$row->id,'number'=>$row->trade_order_number,
                'status'=>$row->status,'currency'=>$row->currency,
                'amount_micros'=>$row->receivable_micros,
                'company'=>$row->organization?->display_name,
                'opportunity'=>$row->opportunity?->title,
            ]),
            'opportunities' => $opportunities->map(fn (B2BSalesOpportunity $row) => [
                'id'=>$row->id,'title'=>$row->title,'company'=>$row->organization?->display_name,
                'quotes'=>$row->quotes->map(fn (B2BSalesQuote $q) => [
                    'id'=>$q->id,'version'=>$q->version,'number'=>$q->quote_number,
                    'currency'=>$q->currency,'total_micros'=>$q->total_micros,
                ])->all(),
            ]),
            'canOperate'=>$request->user()->hasPermission('b2b.operate'),
        ]);
    }

    public function show(Request $request, B2BTradeOrder $tradeOrder): Response
    {
        $row = $this->visible($request)->with([
            'organization:id,display_name,country_code',
            'opportunity:id,title,status',
            'quote:id,quote_number,version',
            'evidence',
            'auditEvents',
        ])->findOrFail($tradeOrder->id);
        return Inertia::render('B2B/Trade/Show', [
            'tradeOrder' => [
                'id'=>$row->id,'number'=>$row->trade_order_number,'status'=>$row->status,
                'currency'=>$row->currency,'amount_micros'=>$row->receivable_micros,
                'organization'=>$row->organization?->display_name,
                'opportunity'=>$row->opportunity?->title,
                'quote'=>$row->quote?->quote_number,
                'created_by'=>$row->created_by,'approved_by'=>$row->approved_by,
                'approved_at'=>$row->approved_at?->toIso8601String(),
                'evidence'=>$row->evidence->map(fn (B2BTradeReceiptEvidence $e) => [
                    'id'=>$e->id,'source'=>$e->source,'reference'=>$e->reference,
                    'currency'=>$e->currency,'amount_micros'=>$e->claimed_amount_micros,
                    'status'=>$e->status,'recorded_by'=>$e->recorded_by,
                    'reviewed_at'=>$e->reviewed_at?->toIso8601String(),
                    'review_note'=>$e->review_note,
                    'attachment_href'=>$e->attachment_path ? route('b2b.trade.evidence.download',['tradeOrder'=>$row->id,'evidence'=>$e->id]) : null,
                ])->all(),
                'events'=>$row->auditEvents->sortBy('id')->values()->map(fn ($e) => [
                    'type'=>$e->event_type,'occurred_at'=>$e->occurred_at?->toIso8601String(),
                ])->all(),
            ],
            'financeSummary'=>app(B2BTradeCashAccountingService::class)->summary($row),
            'canOpenFinance'=>$request->user()->hasPermission('accounting.view'),
            'canOperate'=>$request->user()->hasPermission('b2b.operate'),
            'canApprove'=>$request->user()->hasPermission('b2b.trade.approve')
                && $request->user()->hasPermission('b2b.view_all'),
            'canReview'=>$request->user()->hasPermission('b2b.receipts.review')
                && $request->user()->hasPermission('b2b.view_all'),
            'viewerId'=>(int)$request->user()->getAuthIdentifier(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opportunity_id'=>['required','integer','exists:b2b_sales_opportunities,id'],
            'quote_id'=>['required','integer','exists:b2b_sales_quotes,id'],
        ]);
        $opportunity = B2BSalesOpportunity::query()->when(
            ! $request->user()->hasPermission('b2b.view_all'),
            fn (Builder $q) => $q->where('owner_id', $request->user()->getAuthIdentifier())
        )->findOrFail($data['opportunity_id']);
        $quote = B2BSalesQuote::query()->findOrFail($data['quote_id']);
        $order = $this->service->draft($opportunity, $quote, (int)$request->user()->getAuthIdentifier());
        return redirect()->route('b2b.trade.show', $order->id);
    }

    public function approve(Request $request, B2BTradeOrder $tradeOrder): RedirectResponse
    {
        $this->visible($request)->findOrFail($tradeOrder->id);
        $this->service->approve($tradeOrder, (int)$request->user()->getAuthIdentifier());
        return back();
    }

    public function evidence(Request $request, B2BTradeOrder $tradeOrder): RedirectResponse
    {
        $this->visible($request)->findOrFail($tradeOrder->id);
        $data = $request->validate([
            'source'=>['required',Rule::in(['bank_transfer','provider_reference'])],
            'reference'=>['required','string','max:191'],
            'currency'=>['required','regex:/^[A-Z]{3}$/'],
            'claimed_amount_micros'=>['required','integer','min:1','max:100000000000000'],
            'claimed_paid_on'=>['nullable','date'],
            'attachment'=>['nullable','file','mimes:pdf,jpg,jpeg,png,webp','max:5120'],
        ]);
        $this->service->recordEvidence($tradeOrder, $data, (int)$request->user()->getAuthIdentifier());
        return back();
    }

    public function download(Request $request, B2BTradeOrder $tradeOrder, B2BTradeReceiptEvidence $evidence): BinaryFileResponse
    {
        $this->visible($request)->findOrFail($tradeOrder->id);
        abort_unless((int) $evidence->trade_order_id === (int) $tradeOrder->id, 404);
        abort_unless($evidence->attachment_disk === 'local'
            && is_string($evidence->attachment_path)
            && str_starts_with($evidence->attachment_path, 'b2b-trade/evidence/'.$tradeOrder->id.'/')
            && Storage::disk('local')->exists($evidence->attachment_path), 404);
        return response()->download(
            Storage::disk('local')->path($evidence->attachment_path),
            $evidence->attachment_name ?: 'receipt-evidence',
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function review(Request $request, B2BTradeOrder $tradeOrder, B2BTradeReceiptEvidence $evidence): RedirectResponse
    {
        $this->visible($request)->findOrFail($tradeOrder->id);
        abort_unless((int)$evidence->trade_order_id === (int)$tradeOrder->id, 404);
        $data = $request->validate([
            'decision'=>['required',Rule::in(['reviewed','rejected'])],
            'reason'=>['required','string','max:1000'],
        ]);
        $this->service->reviewEvidence($evidence,(int)$request->user()->getAuthIdentifier(),
            $data['decision'],$data['reason']);
        return back();
    }
}
