<?php

namespace App\Modules\Logistics\Application\Wlwy;

use App\Models\User;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Application\FulfillmentPaymentGate;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\OverseaWarehouseQueueQuery;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use App\Modules\Logistics\Application\Xlwms\UsNyAllocationReservation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Wlwy\CanadaReadGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable per-order claims. CREATE is never replayed, even after worker crash. */
final class CanadaOutboundBatch
{
    public const MAX = 200;

    public function __construct(private readonly CanadaReadGateway $gateway,
        private readonly WarehouseOrderReference $refs, private readonly WarehouseFinalSkuResolver $skus,
        private readonly UsNyAllocationReservation $stock, private readonly FulfillmentPaymentGate $payment,
        private readonly InventoryReservationRepository $reservations) {}

    public static function liveAllowed(int $orderId): bool
    {
        $canary = (int) config('wlwy.ca_canary_order_id');

        return app()->environment('production') && config('wlwy.ca_dispatch_enabled')
            && config('wlwy.ca_dispatch_owner_approved') && config('wlwy.ca_inventory_apply_enabled')
            && config('wlwy.ca_duplicate_contract_verified') && config('wlwy.ca_batch_contract_verified')
            && ($canary > 0 ? $orderId === $canary : (config('wlwy.ca_operator_batch_enabled')
                && (int) config('wlwy.ca_min_order_id') > 0 && $orderId >= (int) config('wlwy.ca_min_order_id')));
    }

    public static function guarded(int $orderId): bool
    {
        if (! Schema::hasTable('wlwy_ca_batch_items')) {
            return false;
        }

        return DB::table('wlwy_ca_batch_items')->where('order_id', $orderId)->where('dry_run', false)
            ->whereIn('status', ['queued', 'in_flight', 'uncertain', 'accepted', 'confirmed', 'existing', 'tracking_staged', 'exception'])->exists();
    }

    /** Called with the current order/Shipment locked by the exception resolver. */
    public static function releasePreCreateFailure(Order $order, int $actorId): bool
    {
        if (! self::liveAllowed((int) $order->id)) {
            return false;
        }
        $items = DB::table('wlwy_ca_batch_items')->where('order_id', $order->id)->where('dry_run', false)
            ->lockForUpdate()->get();
        $failed = $items->where('status', 'blocked');
        if ($failed->isEmpty() || $items->contains(fn ($i) => ! in_array($i->status, ['blocked', 'retry_released'], true)
            || filled($i->provider_order_id) || (int) $i->shipment_id !== (int) $order->shipment?->id)) {
            return false;
        }
        foreach ($failed as $item) {
            if ($item->result_code !== 'CA_PRECHECK_FAILED'
                || ! DB::table('wlwy_ca_batches')->where('id', $item->batch_id)->where('status', 'completed_partial')->exists()) {
                return false;
            }
        }
        foreach ($failed as $item) {
            DB::table('wlwy_ca_batch_items')->where('id', $item->id)->where('status', 'blocked')
                ->update(['status' => 'retry_released', 'updated_at' => now()]);
            app(AuditRecorder::class)->record(
                AuditActor::user(User::findOrFail($actorId), AuditSource::Web),
                'logistics.ca_precreate_failure_released', 'wlwy_ca_batch_item', $item->id,
                ['status' => 'blocked', 'result_code' => $item->result_code], ['status' => 'retry_released'],
                'operator_confirmed_pre_create_failure');
        }

        return true;
    }

    public static function projection(object $item, string $shipmentStatus): array
    {
        [$status, $label] = match (true) {
            $shipmentStatus === 'shipped' => ['shipped', '已发货'],
            $item->status === 'tracking_staged' => ['warehouse_outbound_pending_sync', '单号已回填 · 待确认发货'],
            $item->status === 'confirmed' => ['oms_processing', '仓库已接单 · 等待物流单号'],
            $item->status === 'retry_released' => ['pending', '异常已解除 · 待人工再次推送'],
            $item->status === 'queued' => ['exception', '批次排队中'],
            $item->status === 'in_flight' => ['exception', '推送处理中 · 禁止重复提交'],
            $item->status === 'accepted' => ['oms_unverified', 'API 已受理 · 等待仓库回读'],
            default => ['exception', '待核实 · 禁止重复推送'],
        };

        return ['status' => $status, 'label' => $label, 'detail' => $item->result_code ?? '',
            'oms_order_no' => $item->provider_order_id, 'last_checked_at' => $item->tracking_checked_at,
            'failure_code' => $item->result_code, 'can_retry_local_block' => false];
    }

    public function connection(int $warehouseId): WarehouseOpenApiConnection
    {
        return WarehouseOpenApiConnection::query()->where('warehouse_id', $warehouseId)->where('provider', 'wlwy')
            ->where('oms_warehouse_code', 'CA02')->firstOrFail();
    }

    private function candidates(?array $ids): array
    {
        $q = Shipment::query()->whereHas('warehouse', fn ($w) => $w->where('code', 'CA'))
            ->whereHas('order', fn ($o) => $o->eligibleForOperations())
            ->where(fn ($s) => $s->whereNull('tracking_number')->orWhere('tracking_number', ''))
            ->whereNotIn('order_id', DB::table('wlwy_ca_batch_items')->where('dry_run', false)
                ->when($ids !== null, fn ($prior) => $prior->where('status', '!=', 'retry_released'))
                ->select('order_id'));
        app(OverseaWarehouseQueueQuery::class)->applyView($q, 'pending');
        if ($ids !== null) {
            $q->whereIn('order_id', $ids);
        }

        return $q->orderBy('order_id')->limit(self::MAX + 1)->pluck('order_id')->map(fn ($id) => (int) $id)->all();
    }

    private function payload(Order $order): array
    {
        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview', 'address', 'items']);
        $s = $order->shipment;
        $a = $order->address;
        if (! $s || $s->warehouse?->code !== 'CA' || $s->shipment_status !== 'pending'
            || $s->shipped_at || filled($s->tracking_number) || $order->order_status?->value !== 'pending_shipment'
            || ! in_array($order->routing_warehouse_code, [null, 'CA'], true)
            || $s->overseaReview?->status !== 'approved' || (int) $s->overseaReview?->reviewed_warehouse_id !== (int) $s->warehouse_id
            || strcasecmp((string) $s->carrier, 'Canada Post') !== 0 || ! $a || strtoupper((string) $a->country) !== 'CA'
            || $order->fulfillment_parent_id !== null || $order->originalFulfillmentChildren()->exists()
            || $order->shipmentAttempts()->exists()) {
            throw new RuntimeException('CA_ORDER_NOT_READY');
        }
        $this->payment->assertAllowed($order);
        $required = $order->items->groupBy('product_id')->map(fn ($rows) => (int) $rows->sum('quantity'))->sortKeys()->all();
        $hold = $this->reservations->activeForOrder((int) $order->id);
        if (! $hold || (int) $hold['warehouse_id'] !== (int) $s->warehouse_id
            || collect($hold['items'])->pluck('quantity', 'product_id')->sortKeys()->all() !== $required
            || $this->stock->dispatchBlockers($order, 'CA') !== []) {
            throw new RuntimeException('CA_STOCK_OR_HOLD_NOT_VERIFIED');
        }
        $customer = trim((string) config('wlwy.ca_customer_code'));
        if ($customer === '') {
            throw new RuntimeException('CA_CUSTOMER_NOT_CONFIGURED');
        }
        foreach (['recipient_name', 'phone', 'province_state', 'city', 'postal_code', 'address_line_1'] as $field) {
            if (blank($a->$field)) {
                throw new RuntimeException('CA_ADDRESS_INCOMPLETE');
            }
        }
        $items = [];
        foreach ($order->items as $item) {
            $sku = $this->skus->expected((int) $item->product_id, (int) $s->warehouse_id);
            if (! $sku || strlen($sku) > 40 || (int) $item->quantity <= 0) {
                throw new RuntimeException('CA_FINAL_SKU_INVALID');
            }
            // No guessed batch identifier. A batch-managed SKU requires an explicitly verified batch contract.
            $items[$sku] = ($items[$sku] ?? 0) + (int) $item->quantity;
        }
        ksort($items);
        if ($items === [] || count($items) > 100 || max($items) > 99999) {
            throw new RuntimeException('CA_ITEMS_INVALID');
        }
        $street = trim($a->address_line_1.' '.($a->address_line_2 ?? ''));
        $desc = ['country' => 'CA', 'state' => $a->province_state, 'city' => $a->city,
            'post_code' => $a->postal_code, 'street' => $street, 'first_name' => $a->recipient_name, 'phone' => $a->phone];
        foreach (['state' => 64, 'city' => 64, 'post_code' => 64, 'street' => 256, 'first_name' => 128, 'phone' => 64] as $k => $max) {
            if (mb_strlen((string) $desc[$k]) > $max) {
                throw new RuntimeException('CA_ADDRESS_FIELD_TOO_LONG');
            }
        }

        return ['customer_code' => $customer, 'ref_no' => $this->refs->canada($order),
            'from_warehouse_code' => 'CA02', 'consignment_type' => 'S',
            'logistics_service_info' => ['logistics_product_code' => 'CAPOST'],
            'oconsignment_desc' => $desc, 'oconsignment_sku' => array_map(
                fn ($sku, $qty) => ['sku_code' => (string) $sku, 'qty' => $qty, 'stock_quality' => 'G'],
                array_keys($items), array_values($items))];
    }

    private function snapshot(?array $ids): array
    {
        $candidates = $this->candidates($ids);
        $rows = [];
        $claims = [];
        foreach (array_slice($candidates, 0, self::MAX) as $id) {
            $o = Order::query()->eligibleForOperations()->findOrFail($id);
            try {
                if (app()->environment('production') && ! self::liveAllowed($id)) {
                    throw new RuntimeException('CA_ORDER_NOT_AUTHORIZED');
                }
                $payload = $this->payload($o);
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $claims[] = ['order_id' => $id, 'shipment_id' => $o->shipment->id, 'order_no' => $o->erp_order_id, 'reference' => $payload['ref_no'], 'payload_hash' => $hash];
                $rows[] = ['order_id' => $id, 'order_no' => $o->erp_order_id, 'ready' => true, 'blockers' => []];
            } catch (\Throwable) {
                $rows[] = ['order_id' => $id, 'order_no' => $o->erp_order_id, 'ready' => false, 'blockers' => ['库存、审核、SKU、地址或授权未通过']];
            }
        }
        $fingerprint = hash('sha256', json_encode([$ids, $claims, $rows], JSON_THROW_ON_ERROR));

        return [$claims, ['count' => count($claims), 'overflow' => count($candidates) > self::MAX, 'fingerprint' => $fingerprint,
            'dry_run' => ! app()->environment('production'), 'scope' => $ids === null ? 'all' : 'selected', 'scan_count' => count($candidates),
            'excluded_count' => count($rows) - count($claims), 'items' => $rows]];
    }

    public function preview(?array $ids): array
    {
        return $this->snapshot($ids)[1];
    }

    public function start(int $actor, string $fingerprint, ?array $ids, string $confirmation): array
    {
        $lock = Cache::lock('wlwy:ca:batch:start', 30);
        if (! $lock->get()) {
            throw new RuntimeException('CA_BATCH_LOCKED');
        }
        try {
            return DB::transaction(function () use ($actor, $fingerprint, $ids, $confirmation) {
                [$claims,$preview] = $this->snapshot($ids);
                if ($preview['overflow'] || ! $claims || ! hash_equals($preview['fingerprint'], $fingerprint)
                    || $confirmation !== 'CONFIRM_CA_BATCH_'.$preview['count']) {
                    throw new RuntimeException('CA_BATCH_STALE');
                }
                if (DB::table('wlwy_ca_batches')->whereIn('status', ['queued', 'running'])->exists()) {
                    throw new RuntimeException('CA_BATCH_ALREADY_RUNNING');
                }
                $id = (string) Str::uuid();
                $dry = $preview['dry_run'];
                DB::table('wlwy_ca_batches')->insert(['id' => $id, 'operator_user_id' => $actor, 'status' => 'queued', 'dry_run' => $dry, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($claims as $row) {
                    if (! $dry && DB::table('wlwy_ca_batch_items')->where('dry_run', false)
                        ->where(fn ($q) => $q->where('order_id', $row['order_id'])->orWhere('reference', $row['reference']))
                        ->where(fn ($q) => $q->where('status', '!=', 'retry_released')
                            ->orWhere('order_id', '!=', $row['order_id'])->orWhere('reference', '!=', $row['reference']))->exists()) {
                        throw new RuntimeException('CA_REFERENCE_ALREADY_CLAIMED');
                    }

                    DB::table('wlwy_ca_batch_items')->insert($row + ['batch_id' => $id, 'status' => 'queued', 'dry_run' => $dry, 'created_at' => now(), 'updated_at' => now()]);
                }

                return ['batch_id' => $id, 'status' => 'queued', 'dry_run' => $dry, 'total' => count($claims)];
            });
        } finally {
            $lock->release();
        }
    }

    public function status(string $id): array
    {
        $batch = DB::table('wlwy_ca_batches')->where('id', $id)->first();
        if (! $batch) {
            throw new RuntimeException('CA_BATCH_NOT_FOUND');
        }
        $items = DB::table('wlwy_ca_batch_items')->where('batch_id', $id)->orderBy('id')->get(['order_id', 'order_no', 'status', 'result_code', 'provider_order_id']);

        return ['batch_id' => $id, 'status' => $batch->status, 'dry_run' => (bool) $batch->dry_run, 'total' => $items->count(),
            'processed' => $items->whereNotIn('status', ['queued', 'in_flight'])->count(), 'items' => $items];
    }

    public function drain(int $limit = 5, ?string $devBatchId = null): array
    {
        $lock = Cache::lock('wlwy:ca:batch:drain', 600);
        if (! $lock->get()) {
            return ['busy' => true];
        }
        try {
            $query = DB::table('wlwy_ca_batches')->whereIn('status', ['queued', 'running']);
            if ($devBatchId !== null) {
                if (! app()->environment('local', 'testing')) {
                    return ['processed' => 0];
                }
                $query->where('id', $devBatchId)->where('dry_run', true);
            }
            $batch = $query->orderBy('created_at')->first();
            if (! $batch) {
                return ['processed' => 0];
            }
            DB::table('wlwy_ca_batches')->where('id', $batch->id)->update(['status' => 'running', 'updated_at' => now()]);
            // A previous process died after claiming. Never resend.
            DB::table('wlwy_ca_batch_items')->where('batch_id', $batch->id)->where('status', 'in_flight')
                ->update(['status' => 'uncertain', 'result_code' => 'CA_PREVIOUS_ATTEMPT_UNCERTAIN', 'updated_at' => now()]);
            $items = DB::table('wlwy_ca_batch_items')->where('batch_id', $batch->id)->where('status', 'queued')->orderBy('id')->limit($limit)->get();
            foreach ($items as $item) {
                $this->process($item, $batch);
            }
            if (! DB::table('wlwy_ca_batch_items')->where('batch_id', $batch->id)->whereIn('status', ['queued', 'in_flight'])->exists()) {
                $bad = DB::table('wlwy_ca_batch_items')->where('batch_id', $batch->id)->whereNotIn('status', ['accepted', 'confirmed', 'existing', 'simulated', 'tracking_staged'])->exists();
                DB::table('wlwy_ca_batches')->where('id', $batch->id)->update(['status' => $bad ? 'completed_partial' : 'completed', 'updated_at' => now()]);
            }

            return ['processed' => $items->count()];
        } finally {
            $lock->release();
        }
    }

    private function process(object $item, object $batch): void
    {
        $status = 'blocked';
        $code = 'CA_PRECHECK_FAILED';
        $oms = null;
        $claimed = false;
        try {
            $actor = User::query()->find($batch->operator_user_id);
            if (! $actor || ! $actor->is_active || ! $actor->can('logistics.operate') || ! app(LogisticsExecutionAccess::class)->allows($actor)) {
                throw new RuntimeException('CA_OPERATOR_REVOKED');
            }
            $order = Order::query()->eligibleForOperations()->findOrFail($item->order_id);
            $q = Shipment::query()->whereKey($item->shipment_id);
            app(OrderProgressQuery::class)->overseasReady($q);
            if (! $q->exists()) {
                throw new RuntimeException('CA_REVIEW_CHANGED');
            }
            $payload = $this->payload($order);
            if ($order->shipment->id !== (int) $item->shipment_id || ! hash_equals($item->payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('CA_ORDER_CHANGED');
            }
            if ($batch->dry_run) {
                $status = 'simulated';
                $code = 'CA_SIMULATED_NO_IO';
            } else {
                if (! self::liveAllowed((int) $order->id)) {
                    throw new RuntimeException('CA_CREATE_DISABLED');
                }
                $connection = $this->connection((int) $order->shipment->warehouse_id);
                $matches = $this->gateway->exactStatus($connection, $item->reference);
                if ($matches === [] && $order->erp_order_id !== $item->reference) {
                    $matches = $this->gateway->exactStatus($connection, (string) $order->erp_order_id);
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('CA_DUPLICATE_REFERENCE');
                }
                if ($matches !== []) {
                    $status = 'exception';
                    $code = 'CA_EXISTING_NO_CREATE';
                    $oms = (string) $matches[0]['consignment_no'];
                } else {
                    $this->gateway->assertDispatchCatalog($connection, array_column($payload['oconsignment_sku'], 'sku_code'), $payload['customer_code']);
                    $claimed = DB::table('wlwy_ca_batch_items')->where('id', $item->id)->where('status', 'queued')
                        ->update(['status' => 'in_flight', 'updated_at' => now()]) === 1;
                    if (! $claimed) {
                        return;
                    }
                    $reply = DB::transaction(function () use ($order, $item, $connection) {
                        $locked = Order::query()->eligibleForOperations()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                        $q = Shipment::query()->whereKey($item->shipment_id);
                        app(OrderProgressQuery::class)->overseasReady($q);
                        if (! $q->exists()) {
                            throw new RuntimeException('CA_REVIEW_CHANGED');
                        }
                        $body = $this->payload($locked);
                        if (! hash_equals($item->payload_hash, hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)))) {
                            throw new RuntimeException('CA_ORDER_CHANGED');
                        }

                        return $this->gateway->create($connection, $body, (int) $item->id);
                    }, 1);
                    $oms = trim((string) ($reply['consignment_no'] ?? ''));
                    if ($oms === '' || strlen($oms) > 100) {
                        throw new RuntimeException('CA_ACK_ID_MISSING');
                    }
                    $status = 'accepted';
                    $code = 'CA_ACK_PENDING_READBACK';
                }
            }
        } catch (\Throwable) {
            $status = $claimed ? 'uncertain' : 'blocked';
            $code = $claimed ? 'CA_CREATE_UNCERTAIN' : 'CA_PRECHECK_FAILED';
        }
        DB::table('wlwy_ca_batch_items')->where('id', $item->id)->update(['status' => $status, 'result_code' => $code, 'provider_order_id' => $oms, 'updated_at' => now()]);
    }
}
