<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** One exact authorized order, durable attempt, fresh per-SKU provider evidence. */
final class UsNyCanaryDispatch
{
    public function __construct(private readonly SingleOrderPreview $preview,
        private readonly XlwmsOrderGateway $oms, private readonly XlwmsInventoryGateway $stock,
        private readonly XlwmsInventoryPageParser $parser) {}

    private static function authorized(int $orderId, ?int $actorId): bool
    {
        if ($actorId === null) {
            return app()->runningInConsole() && app()->environment('production', 'testing')
                && config('xlwms.us_ny_canary_enabled', false)
                && config('xlwms.us_ny_canary_system_enabled', false)
                && $orderId > 0 && $orderId === (int) config('xlwms.us_ny_canary_order_id');
        }
        $actor = User::query()->find($actorId);
        if (UsNyOutboundBatch::authorizedClaim($orderId, $actorId)) {
            return $actor?->is_active && app(LogisticsExecutionAccess::class)->allows($actor);
        }

        return app()->environment('production', 'testing')
            && config('xlwms.us_ny_canary_enabled', false)
            && $orderId > 0 && $orderId === (int) config('xlwms.us_ny_canary_order_id')
            && $actorId > 0 && $actorId === (int) config('xlwms.us_ny_canary_actor_id')
            && $actor?->is_active && app(LogisticsExecutionAccess::class)->allows($actor);
    }

    public static function permitsCreate(WarehouseOpenApiConnection $connection, array $payload, int $orderId): bool
    {
        $attempt = XlwmsDispatchAttempt::query()->where('order_id', $orderId)
            ->where('warehouse_id', $connection->warehouse_id)->where('state', 'request_in_flight')->first();

        return $attempt && self::authorized($orderId, $attempt->requested_by === null ? null : (int) $attempt->requested_by)
            && hash_equals((string) $attempt->request_fingerprint, self::hash($payload))
            && $attempt->third_order_no === 'BYL-ERP-'.$orderId;
    }

    private static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function ready(int $id, string $hash): array
    {
        $order = Order::query()->eligibleForOperations()->with(['shipment.warehouse', 'items', 'address',
            'shipment.carrierService', 'shipment.overseaReview'])->findOrFail($id);
        if ($order->routing_warehouse_code !== 'US-NY' || $order->workflow_status !== 'sending'
            || $order->shipment?->warehouse?->code !== 'US-NY') {
            throw new RuntimeException('US_NY_WRONG_ROUTE_OR_REVIEW');
        }
        // The canary checks freshly signed OMS stock after duplicate scan;
        // no unrelated SKU is allowed to authorize/block the requested item.
        $p = $this->preview->forOrder($order, false, false, 'US-NY');
        if ($p['blockers'] !== [] || ! hash_equals($hash, $p['payload_hash'])) {
            throw new RuntimeException('US_NY_PREFLIGHT_BLOCKED_OR_STALE');
        }
        $required = [];
        foreach ($order->items as $item) {
            $id = (int) $item->product_id;
            $required[$id] = ($required[$id] ?? 0) + (int) $item->quantity;
        }
        foreach ($required as $id => $qty) {
            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $order->shipment->warehouse_id)->where('product_id', $id)->first();
            if ($id < 1 || $qty < 1 || ! $inventory || (int) $inventory->quantity_available < $qty) {
                throw new RuntimeException('US_NY_LOCAL_STOCK_INSUFFICIENT');
            }
        }
        $connection = WarehouseOpenApiConnection::query()->where('warehouse_id', $order->shipment->warehouse_id)
            ->where('provider', 'xlwms')->where('oms_warehouse_code', 'US01')->firstOrFail();

        return [$order, $connection, $p['payload']];
    }

    private function checkStock(WarehouseOpenApiConnection $connection, array $items): void
    {
        $need = [];
        foreach ($items as $i) {
            $need[$i['sku']] = ($need[$i['sku']] ?? 0) + $i['quantity'];
        }
        $seen = [];
        $total = null;
        $count = 0;
        for ($page = 1; $page <= 1000; $page++) {
            $p = $this->parser->inventoryPage($this->stock->integratedInventoryPage($connection,
                ['page' => $page, 'pageSize' => 10, 'whCode' => 'US01', 'stockType' => 0]), 'US01', $page, 10);
            $total ??= $p['total'];
            if ($p['total'] !== $total || count($p['rows']) !== min(10, max(0, $total - ($page - 1) * 10))) {
                throw new RuntimeException('US_NY_STOCK_INCOMPLETE');
            }
            foreach ($p['rows'] as $r) {
                $sku = trim((string) ($r['sku'] ?? ''));
                if ($sku === '' || isset($seen[$sku]) || (int) ($r['stockType'] ?? -1) !== 0) {
                    throw new RuntimeException('US_NY_STOCK_INVALID');
                }
                $d = $r['productStockDtl'] ?? [];
                $q = $this->parser->nonNegativeInt($r['productTotalAmount'] ?? null, 'TOTAL');
                $a = $this->parser->nonNegativeInt($d['availableAmount'] ?? null, 'AVAILABLE');
                $l = $this->parser->nonNegativeInt($d['lockAmount'] ?? null, 'LOCKED');
                if ($q !== $a + $l) {
                    throw new RuntimeException('US_NY_STOCK_INVALID');
                }
                $seen[$sku] = $a;
                $count++;
            }
            if ($page >= max(1, $p['pages'])) {
                break;
            }
        }
        if ($count !== $total) {
            throw new RuntimeException('US_NY_STOCK_INCOMPLETE');
        }
        foreach ($need as $sku => $qty) {
            if (($seen[$sku] ?? -1) < $qty) {
                throw new RuntimeException('US_NY_STOCK_INSUFFICIENT');
            }
        }
    }

    public function create(int $id, ?int $actorId, string $hash, ?int $recoveryId = null): array
    {
        if (! self::authorized($id, $actorId)) {
            throw new RuntimeException('US_NY_CANARY_NOT_AUTHORIZED');
        }
        [$order, $connection, $payload] = $this->ready($id, $hash);
        $attempt = DB::transaction(function () use ($id, $actorId, $hash, $order, $payload, $recoveryId) {
            Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $prior = XlwmsDispatchAttempt::query()->where('order_id', $id)->lockForUpdate()->first();
            if ($recoveryId !== null) {
                $operatorRecovery = $actorId !== null && $prior && (int) $prior->id === $recoveryId
                    && UsNyOutboundBatch::authorizedRecovery($id, $actorId, $recoveryId)
                    && UsNyOutboundBatch::recoveryEligible($prior, $order);
                $systemRecovery = $actorId === null && $prior && (int) $prior->id === $recoveryId
                    && $prior->requested_by === null && $prior->state === 'blocked_preflight_error'
                    && $prior->failure_code === 'US_NY_PREFLIGHT_OR_DUPLICATE_BLOCKED'
                    && blank($prior->oms_order_no) && blank($prior->oms_status_code)
                    && blank($prior->oms_tracking_no) && $prior->oms_status_checked_at === null
                    && (int) $prior->warehouse_id === (int) $order->shipment->warehouse_id
                    && $prior->third_order_no === $payload['thirdOrderNo']
                    && hash_equals((string) $prior->request_fingerprint, $hash);
                if (! $operatorRecovery && ! $systemRecovery) {
                    throw new RuntimeException('US_NY_RECOVERY_NOT_SAFE');
                }
                $source = AuditSource::InternalSystem;
                $auditActor = $operatorRecovery
                    ? AuditActor::user(User::findOrFail($actorId), $source)
                    : AuditActor::system($source);
                app(AuditRecorder::class)->record(
                    $auditActor, 'logistics.us_ny_preflight_recovery', XlwmsDispatchAttempt::class, $prior->id,
                    before: ['state' => $prior->state, 'failure_code' => $prior->failure_code, 'request_fingerprint' => $prior->request_fingerprint],
                    after: ['state' => 'checking_existing', 'order_id' => $id, 'request_fingerprint' => $hash],
                    reason: $operatorRecovery ? 'operator_confirmed_pre_create_failure_recovery' : 'owner_authorized_pre_create_failure_recovery');
                $prior->forceFill(['state' => 'checking_existing', 'failure_code' => null, 'last_checked_at' => now(),
                    'requested_by' => $actorId, 'request_fingerprint' => $hash])->save();

                return $prior;
            }
            if ($prior) {
                throw new RuntimeException('US_NY_ATTEMPT_EXISTS');
            }

            return XlwmsDispatchAttempt::query()->create(['order_id' => $id, 'warehouse_id' => $order->shipment->warehouse_id,
                'third_order_no' => $payload['thirdOrderNo'], 'request_fingerprint' => $hash, 'requested_by' => $actorId,
                'state' => 'checking_existing']);
        });
        try {
            if ($actorId === null) {
                app(AuditRecorder::class)->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'logistics.us_ny_owner_canary_started', XlwmsDispatchAttempt::class, $attempt->id,
                    after: ['order_id' => $id, 'warehouse_id' => $attempt->warehouse_id, 'reviewed_by' => $order->shipment->overseaReview->reviewed_by],
                    reason: 'owner_authorized_exact_one_order_cli_canary');
            }
            $matches = $this->oms->findExisting($connection, $payload['thirdOrderNo'], $payload['platformOrderNo'],
                [$payload['referOrderNo'], (string) $order->external_order_number]);
            if ($matches !== []) {
                throw new RuntimeException('OMS_DUPLICATE_ORDER');
            }
            $this->oms->assertCatalogReady($connection, 'US01', 'USPS-NJ', array_column($payload['productList'], 'sku'));
            $this->checkStock($connection, $payload['productList']);
            [, $connection, $payload] = $this->ready($id, $hash);
            $attempt->forceFill(['state' => 'request_in_flight', 'last_checked_at' => now()])->save();
            $result = $this->oms->createSingle($connection, $payload);
            if (! $result['confirmed'] || ! filled($result['order_no'])) {
                throw new RuntimeException('OMS_CREATE_NOT_CONFIRMED');
            }
            $attempt->forceFill(['state' => 'oms_create_acknowledged', 'oms_order_no' => $result['order_no']])->save();
            $remote = $this->oms->readExactOrderStatus($connection, $result['order_no']);
            if (! $remote || $remote['third_order_no'] !== $payload['thirdOrderNo']
                || $remote['platform_order_no'] !== $payload['platformOrderNo']
                || $remote['refer_order_no'] !== $payload['referOrderNo']) {
                throw new RuntimeException('OMS_STATUS_IDENTITY_MISMATCH');
            }
            $attempt->forceFill(['state' => 'oms_create_confirmed', 'oms_status_code' => $remote['status_code'],
                'oms_status_checked_at' => now(), 'oms_tracking_no' => $remote['tracking_no'], 'last_checked_at' => now()])->save();
        } catch (Throwable $e) {
            $uncertain = in_array($attempt->state, ['request_in_flight', 'oms_create_acknowledged'], true);
            $attempt->forceFill(['state' => $uncertain ? 'uncertain_manual_reconcile' : 'blocked_preflight_error',
                'failure_code' => $uncertain ? 'US_NY_CREATE_UNCERTAIN' : 'US_NY_PREFLIGHT_OR_DUPLICATE_BLOCKED', 'last_checked_at' => now()])->save();
        }

        return ['state' => $attempt->state, 'oms_order_no' => $attempt->oms_order_no,
            'oms_status_code' => $attempt->oms_status_code, 'tracking_present' => filled($attempt->oms_tracking_no)];
    }
}
