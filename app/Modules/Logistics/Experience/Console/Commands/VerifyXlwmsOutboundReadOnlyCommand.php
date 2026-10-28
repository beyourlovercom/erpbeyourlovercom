<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\Xlwms\SingleOrderPreview;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only OMS identity UAT. No dispatch attempt, status or Woo write occurs.
 * Never prints customer PII, credentials, provider raw payload or tracking ID.
 */
final class VerifyXlwmsOutboundReadOnlyCommand extends Command
{
    protected $signature = 'xlwms:verify-outbound-readonly
        {order : Exact ERP order number (e.g. 007-1027-c41863)}';

    protected $description = 'Read-only US-SM OMS order/quantity/status check; no ERP or provider writes';

    public function handle(SingleOrderPreview $previews, XlwmsOrderGateway $oms): int
    {
        $reference = trim((string) $this->argument('order'));
        if (strlen($reference) < 3 || strlen($reference) > 100) {
            $this->error('BLOCKED=ORDER_REFERENCE_INVALID');
            return self::FAILURE;
        }

        $order = Order::query()->where('erp_order_id', $reference)
            ->orWhere('internal_order_number', $reference)->first();
        if (! $order) {
            $this->error('BLOCKED=ERP_ORDER_NOT_FOUND');
            return self::FAILURE;
        }
        $order->loadMissing(['items', 'address', 'shipment.warehouse', 'shipment.carrierService', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        if ($shipment?->warehouse?->code !== 'US-SM'
            || $shipment->overseaReview?->status !== 'approved'
            || (int) $shipment->overseaReview->reviewed_warehouse_id !== (int) $shipment->warehouse_id) {
            $this->error('BLOCKED=US_SM_APPROVED_ORDER_REQUIRED');
            return self::FAILURE;
        }

        $connection = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $shipment->warehouse_id)->where('provider', 'xlwms')->first();
        if (! $connection || ! filled($connection->oms_warehouse_code)
            || ! filled($connection->app_key_encrypted) || ! filled($connection->app_secret_encrypted)) {
            $this->error('BLOCKED=US_SM_OMS_CONNECTION_MISSING');
            return self::FAILURE;
        }
        try {
            // Skip freshness-only dispatch gates: no inventory or shipment is
            // mutated and no provider order is ever created by this command.
            $preview = $previews->forOrder($order, false);
            $payload = (array) ($preview['payload'] ?? []);
            $items = $payload['productList'] ?? null;
            if (! is_array($items) || $items === []
                || blank($payload['thirdOrderNo'] ?? '') || blank($payload['platformOrderNo'] ?? '')) {
                $this->error('BLOCKED=OMS_LOCAL_ORDER_IDENTITY_INVALID');
                return self::FAILURE;
            }
            $matches = $oms->findExistingExact(
                $connection,
                (string) $payload['thirdOrderNo'],
                (string) $payload['platformOrderNo'],
                [(string) ($payload['referOrderNo'] ?? ''), (string) $order->external_order_number],
                $items,
            );
            if ($matches === []) {
                $this->line('RESULT=NOT_FOUND; WRITE_PERFORMED=NO; ACTION=REVIEW_BEFORE_ANY_CREATE');
                return self::SUCCESS;
            }
            $omsId = (string) ($matches[0]['outboundOrderNo'] ?? $matches[0]['orderNo'] ?? '');
            if ($omsId === '') {
                $this->error('BLOCKED=OMS_ORDER_ID_MISSING');
                return self::FAILURE;
            }
            $status = $oms->readExactOrderStatus($connection, $omsId);
            if ($status === null) {
                $this->error('BLOCKED=OMS_STATUS_NOT_FOUND');
                return self::FAILURE;
            }
            if (($status['third_order_no'] ?? '') !== (string) $payload['thirdOrderNo']
                || ($status['platform_order_no'] ?? '') !== (string) $payload['platformOrderNo']) {
                $this->error('BLOCKED=OMS_STATUS_IDENTITY_MISMATCH');
                return self::FAILURE;
            }
            $code = (string) ($status['status_code'] ?? '');
            if ($code === '4') $result = 'CANCELED_REVIEW';
            elseif ($code === '3') $result = filled($status['tracking_no'] ?? null) ? 'SHIPPED_TRACKING_PRESENT' : 'SHIPPED_TRACKING_MISSING';
            else $result = 'STATUS_UNCLASSIFIED';
            $this->line('RESULT='.$result.'; OMS_STATUS_CODE='.$code.'; WRITE_PERFORMED=NO');
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Never expose signed URLs, PII, raw exception or authcode.
            $code = preg_match('/^OMS_[A-Z0-9_]+$/D', $e->getMessage()) ? $e->getMessage() : 'OMS_READONLY_VERIFY_FAILED';
            $this->error('BLOCKED='.$code.'; WRITE_PERFORMED=NO');
            return self::FAILURE;
        }
    }
}
