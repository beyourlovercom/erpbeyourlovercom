<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Explicit single-use T2 experiment, callable from LOCAL terminal only.
 * This is deliberately NOT an HTTP route, scheduled job, or production flow.
 */
final class LocalTestDraftExercise
{
    public function __construct(
        private readonly TestDraftPlan $draftPlan,
        private readonly XlwmsOrderGateway $oms,
    ) {}

    /** @return array{state:string,oms_order_no:?string,error_code:?string} */
    public function execute(int $orderId): array
    {
        if (! app()->environment(['local','testing'])
            || config('xlwms.t2_test_draft_enabled') !== true
            || config('xlwms.live_dispatch_enabled') !== false) {
            throw new RuntimeException('T2_DRAFT_NOT_ENABLED_OR_UNSAFE_ENVIRONMENT');
        }

        $order = Order::query()->with('shipment.warehouse')->findOrFail($orderId);
        if ((string)$order->erp_order_id !== 'TEST-XLWMS-001'
            || (int)$order->items()->count() !== 1) {
            throw new RuntimeException('T2_DRAFT_WRONG_EXACT_TEST_ORDER');
        }
        if (XlwmsDispatchAttempt::query()->where('order_id',$orderId)->exists()) {
            throw new RuntimeException('T2_DRAFT_ATTEMPT_ALREADY_EXISTS_NEVER_RETRY');
        }

        $draft=$this->draftPlan->prepare($orderId);
        if ($draft['remote_post_permitted'] !== false || $draft['mode'] !== 'dry_run_only') {
            throw new RuntimeException('T2_DRAFT_PLAN_INTEGRITY_FAILURE');
        }
        $payload=$draft['payload'];
        $connection=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$order->shipment->warehouse_id)
            ->where('provider','xlwms')->firstOrFail();
        if (! filled($connection->oms_warehouse_code) ||
            $connection->oms_warehouse_code !== $payload['whCode'] ||
            ! filled($connection->last_verified_at)) {
            throw new RuntimeException('T2_OMS_WAREHOUSE_BINDING_NOT_VERIFIED');
        }
        // The exact duplicate and approved catalog/channel checks are
        // READ ONLY; missing/unsupported OMS responses fail closed.
        $existing=$this->oms->findExisting(
            $connection, $payload['thirdOrderNo'],
            $payload['platformOrderNo'],[$payload['referOrderNo']]
        );
        if ($existing !== []) {
            throw new RuntimeException('T2_TEST_REFERENCE_ALREADY_EXISTS_IN_OMS');
        }
        $this->oms->assertCatalogReady(
            $connection,$payload['whCode'],$payload['logisticsChannel'],
            [$payload['productList'][0]['sku']]
        );

        $attempt=DB::transaction(function() use ($order,$payload): XlwmsDispatchAttempt {
            if (XlwmsDispatchAttempt::query()->where('order_id',$order->id)->lockForUpdate()->exists()) {
                throw new RuntimeException('T2_DRAFT_ATTEMPT_CONCURRENTLY_CREATED');
            }
            return XlwmsDispatchAttempt::query()->create([
                'order_id'=>$order->id,
                'warehouse_id'=>$order->shipment->warehouse_id,
                'third_order_no'=>$payload['thirdOrderNo'],
                'state'=>'draft_create_in_flight',
                'request_fingerprint'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),
                'last_checked_at'=>now(),
                'requested_by'=>null,
            ]);
        });

        try {
            $result=$this->oms->createTestDraftSingle($connection,$payload);
            $attempt->forceFill([
                'state'=>'draft_created_pending_cancel',
                'oms_order_no'=>$result['order_no'],
                'last_checked_at'=>now(),
                'failure_code'=>null,
            ])->save();
            return ['state'=>'draft_created_pending_cancel',
                'oms_order_no'=>$attempt->oms_order_no,'error_code'=>null];
        } catch (\Throwable $e) {
            // A timeout may occur after remote creation. No automatic retry.
            $knownCodes=[
                'OMS_TRANSPORT_UNCERTAIN','OMS_SERVER_UNCERTAIN',
                'OMS_HTTP_REJECTED','OMS_BUSINESS_REJECTED',
                'OMS_TEST_DRAFT_CREATE_UNCONFIRMED',
            ];
            $code=in_array($e->getMessage(),$knownCodes,true)
                ? $e->getMessage() : 'OMS_TEST_DRAFT_UNEXPECTED';
            $attempt->forceFill([
                'state'=>'draft_create_uncertain',
                'failure_code'=>$code,
                'last_checked_at'=>now(),
            ])->save();
            return ['state'=>'draft_create_uncertain',
                'oms_order_no'=>null,'error_code'=>$code];
        }
    }
}
