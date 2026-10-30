<?php
namespace App\Modules\Logistics\Experience\Controllers;
use App\Modules\Logistics\Application\Xlwms\ReshipmentPreview;
use App\Modules\Logistics\Application\Xlwms\ReshipmentIntentService;
use App\Modules\Logistics\Application\Xlwms\ReshipmentApprovalService;
use App\Modules\Logistics\Application\Xlwms\ReshipmentDispatchService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Logistics\Application\OverseaFiles\OverseaOutboundFileService;
use App\Modules\Logistics\Application\Xlwms\XlwmsManualDraftBindingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
final class XlwmsReshipmentController {
    public function bindManualDraft(
        int $order, string $requestId, Request $request,
        XlwmsManualDraftBindingService $binding
    ): JsonResponse {
        $input=$request->validate([
            'oms_order_no'=>['required','string','regex:/^[A-Za-z0-9_-]{6,100}$/D'],
        ]);
        try {
            return response()->json($binding->bind($order,$requestId,
                $input['oms_order_no'],(int)$request->user()->id))
                ->header('Cache-Control','private, no-store');
        } catch (\RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)
                ->header('Cache-Control','private, no-store');
        }
    }

    public function manualDraftExport(
        int $order, string $requestId, OverseaOutboundFileService $files
    ): \Symfony\Component\HttpFoundation\Response {
        abort_unless(config('xlwms.us_sm_outbound_entry_mode')==='oms_manual_draft', 404);
        $r=XlwmsReshipment::query()->where('original_order_id',$order)
            ->where('request_id',$requestId)->firstOrFail();
        abort_unless($r->status==='approved_for_reissue' && !filled($r->oms_order_no), 409,
            '重发申请未批准，或已经创建 OMS 订单，禁止重复导入。');
        $o=Order::with(['address','items','shipment.warehouse'])->findOrFail($order);
        abort_unless($o->shipment && $o->shipment->warehouse?->code==='US-SM', 409);
        $file=$files->exportReshipDraft($o->shipment,$r->third_order_no);
        return response($file['bytes'],200,[
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'=>'attachment; filename="'.$file['filename'].'"',
            'Cache-Control'=>'private, no-store',
        ]);
    }


    public function createDraft(int $order, Request $request, ReshipmentDispatchService $service): JsonResponse {
        $input=$request->validate([
            'request_id'=>['required','uuid'],
            'confirmation'=>['required','string','max:100'],
        ]);
        try {
            $result=$service->createDraft($order,$input['request_id'],(int)$request->user()->id,$input['confirmation']);
            return response()->json($result,($result['result']??'')==='created'?201:200)
                ->header('Cache-Control','no-store, private');
        } catch (\RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)->header('Cache-Control','no-store, private');
        }
    }

    public function status(int $order, string $requestId, Request $request, ReshipmentDispatchService $service): JsonResponse {
        try {
            return response()->json($service->readStatus($order,$requestId,(int)$request->user()->id))
                ->header('Cache-Control','no-store, private');
        } catch (\RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)->header('Cache-Control','no-store, private');
        }
    }

    public function approveIntent(int $order, Request $request, ReshipmentApprovalService $service): JsonResponse {
        $input = $request->validate([
            'request_id' => ['required','uuid'],
            'confirmation' => ['required','string','max:100'],
        ]);
        try {
            return response()->json($service->approve($order,$input['request_id'],(int)$request->user()->id,$input['confirmation']))
                ->header('Cache-Control','no-store, private');
        } catch (\RuntimeException $e) {
            return response()->json(['code'=>$e->getMessage()],409)->header('Cache-Control','no-store, private');
        }
    }

    public function requestIntent(int $order, Request $request, ReshipmentIntentService $service): JsonResponse {
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:180'],
            'confirm_original_shipped' => ['sometimes', 'accepted'],
            'confirm_original_canceled' => ['sometimes', 'accepted'],
        ]);
        // Never ask Dede to attest to an untrue shipment. Cancelled OMS drafts
        // and already-shipped source orders have distinct confirmations.
        $preview = app(ReshipmentPreview::class)->forOrder($order);
        $scenario = $preview['source_scenario'] ?? 'not_eligible';
        $acknowledged = match ($scenario) {
            'canceled_original_oms' => ($input['confirm_original_canceled'] ?? false) === true
                && ! isset($input['confirm_original_shipped']),
            'shipped_original' => ($input['confirm_original_shipped'] ?? false) === true
                && ! isset($input['confirm_original_canceled']),
            default => false,
        };
        if (! $acknowledged) {
            return response()->json(['code' => 'RESHIP_SOURCE_CONFIRMATION_INVALID'], 409)
                ->header('Cache-Control', 'no-store, private');
        }
        try {
            $result = $service->request($order, (int) $request->user()->id, $input['reason']);
            return response()->json($result, 201)->header('Cache-Control', 'no-store, private');
        } catch (\RuntimeException $e) {
            return response()->json(['code' => $e->getMessage()], 409)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function preflight(int $order,ReshipmentPreview $service): JsonResponse {
        return response()->json($service->forOrder($order))
            ->header('Cache-Control','no-store, private');
    }
}
