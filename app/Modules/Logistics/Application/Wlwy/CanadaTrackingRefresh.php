<?php

namespace App\Modules\Logistics\Application\Wlwy;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Wlwy\CanadaReadGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Signed exact receipt read; never marks an order shipped or overwrites tracking. */
final class CanadaTrackingRefresh
{
    public function __construct(private readonly CanadaReadGateway $gateway,
        private readonly CanadaOutboundBatch $batches, private readonly AuditRecorder $audit) {}

    public function run(): array
    {
        $result = ['checked' => 0, 'staged' => 0, 'failed' => 0];
        $warehouse = Warehouse::where('code', 'CA')->firstOrFail();
        $connection = $this->batches->connection($warehouse->id);
        DB::table('wlwy_ca_batch_items')->where('dry_run', false)->whereIn('status', ['accepted', 'confirmed', 'uncertain'])
            ->orderBy('id')->chunkById(100, function ($items) use (&$result, $connection) {
                try {
                    $grouped = $this->gateway->exactStatuses($connection, $items->pluck('reference')->unique()->values()->all());
                } catch (\Throwable) {
                    $result['checked'] += $items->count();
                    $result['failed'] += $items->count();
                    DB::table('wlwy_ca_batch_items')->whereIn('id', $items->pluck('id'))->update(['tracking_checked_at' => now()]);

                    return;
                }
                foreach ($items as $item) {
                    try {
                        $result['staged'] += (int) $this->refresh((int) $item->id, $grouped[$item->reference]);
                    } catch (\Throwable) {
                        $result['failed']++;
                        DB::table('wlwy_ca_batch_items')->where('id', $item->id)->update(['tracking_checked_at' => now()]);
                    }
                    $result['checked']++;
                }
            });

        return $result;
    }

    private function refresh(int $id, ?array $knownRows = null): bool
    {
        $item = DB::table('wlwy_ca_batch_items')->where('id', $id)->where('dry_run', false)
            ->whereIn('status', ['accepted', 'confirmed', 'uncertain', 'tracking_staged'])->first();
        if (! $item) {
            return false;
        }
        $order = Order::query()->eligibleForOperations()->with('shipment.warehouse')->findOrFail($item->order_id);
        $shipment = $order->shipment;
        if (! $shipment || $shipment->warehouse?->code !== 'CA' || (int) $shipment->id !== (int) $item->shipment_id) {
            throw new RuntimeException('CA_TRACKING_SCOPE_CHANGED');
        }
        $rows = $knownRows ?? $this->gateway->exactStatus($this->batches->connection((int) $shipment->warehouse_id), $item->reference);

        return DB::transaction(function () use ($id, $rows, $item) {
            $locked = Order::query()->eligibleForOperations()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $s = $locked->shipment()->lockForUpdate()->firstOrFail();
            $current = DB::table('wlwy_ca_batch_items')->where('id', $id)->lockForUpdate()->first();
            if (! $current || ! in_array($current->status, ['accepted', 'confirmed', 'uncertain', 'tracking_staged'], true)
                || (int) $s->id !== (int) $item->shipment_id || $s->warehouse?->code !== 'CA') {
                throw new RuntimeException('CA_TRACKING_SCOPE_CHANGED');
            }
            $base = ['tracking_checked_at' => now(), 'updated_at' => now()];
            if (count($rows) !== 1) {
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['result_code' => count($rows) > 1 ? 'CA_DUPLICATE_REFERENCE' : 'CA_RECEIPT_NOT_VISIBLE']);

                return false;
            }
            $row = $rows[0];
            $oms = (string) $row['consignment_no'];
            $status = (string) ($row['status'] ?? '');
            if (! $current->provider_order_id || $current->provider_order_id !== $oms || ($row['logistics_product_code'] ?? null) !== 'CAPOST') {
                // An uncertain create without acknowledged identity needs operator reconciliation.
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => 'exception', 'result_code' => 'CA_RECEIPT_IDENTITY_UNVERIFIED']);

                return false;
            }
            if (in_array($status, ['X', 'E'], true) || ! in_array($status, ['N', 'S', 'C'], true)) {
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => 'exception', 'result_code' => 'CA_PROVIDER_STATUS_EXCEPTION']);

                return false;
            }
            $numbers = collect([$row['shipping_no'] ?? null, ...collect($row['boxshippingnolist'] ?? [])->pluck('shipping_no')->all()])
                ->filter(fn ($n) => is_string($n) && trim($n) !== '')->map(fn ($n) => strtoupper(trim($n)))->unique()->values();
            if ($status === 'N' || $numbers->isEmpty()) {
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => $status === 'N' ? 'accepted' : 'confirmed', 'result_code' => $status === 'N' ? 'CA_OMS_DRAFT' : 'CA_AWAITING_TRACKING']);

                return false;
            }
            $tracking = $numbers->first();
            if ($numbers->count() !== 1 || ! preg_match('/^(?:[0-9]{16}|[A-Z]{2}[0-9]{9}CA)$/D', $tracking)) {
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => 'exception', 'result_code' => 'CA_TRACKING_MULTIPLE_OR_INVALID']);

                return false;
            }
            if ($locked->order_status?->value !== 'pending_shipment' || $s->shipment_status !== 'pending' || $s->shipped_at
                || $s->overseaReview?->status !== 'approved' || (int) $s->overseaReview?->reviewed_warehouse_id !== (int) $s->warehouse_id
                || strcasecmp((string) $s->carrier, 'Canada Post') !== 0 || (filled($s->tracking_number) && $s->tracking_number !== $tracking)) {
                DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => 'exception', 'result_code' => 'CA_TRACKING_LOCAL_CONFLICT']);

                return false;
            }
            $changed = blank($s->tracking_number);
            if ($changed) {
                $s->forceFill(['tracking_number' => $tracking])->save();
                $this->audit->record(AuditActor::system(AuditSource::InternalSystem), 'shipment.ca_oms_tracking_staged', Shipment::class, $s->id,
                    ['tracking_number' => null], ['tracking_number' => $tracking, 'shipment_status' => 'pending'], 'ca_signed_exact_receipt');
            }
            DB::table('wlwy_ca_batch_items')->where('id', $id)->update($base + ['status' => 'tracking_staged', 'result_code' => 'CA_TRACKING_STAGED']);

            return $changed;
        });
    }
}
