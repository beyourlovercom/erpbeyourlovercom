<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PendingFinalSkuMaterializer
{
    public function __construct(
        private readonly WarehouseFinalSkuResolver $resolver,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * An existing authorized business write may heal a pending physical
     * allocation. Never modify source identity or a dispatched order.
     * Resolve every line before writing any line. Unresolved orders remain
     * visible in their existing workflow and cannot pass outbound validation.
     */
    public function heal(Order $order, Authenticatable $actor): void
    {
        if (! config('warehouse_final_sku.require_verified_mapping')) {
            return;
        }

        DB::transaction(function () use ($order, $actor): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $locked->shipment()->lockForUpdate()->first();
            if ($locked->archived_at !== null || $locked->isOperationallyArchived()
                || $locked->order_status?->value !== 'pending_shipment'
                || ! $shipment?->warehouse_id || $shipment->shipment_status !== 'pending'
                || $shipment->shipped_at !== null || filled($shipment->tracking_number)
                || $locked->activeFulfillmentChildren()->exists()
                || $locked->externalReferences()->where('provider', 'historical_logistics')->exists()) {
                return;
            }

            // Even an attempted provider create is not safe to rewrite.
            foreach (['shipout_dispatch_attempts', 'xlwms_dispatch_attempts',
                'shipout_production_batch_items', 'xlwms_de_dispatch_batch_items'] as $table) {
                if (DB::table($table)->where('order_id', $locked->id)->exists()) {
                    return;
                }
            }

            $changes = [];
            foreach ($locked->items()->lockForUpdate()->get() as $item) {
                try {
                    $sku = $this->resolver->forShipment($shipment, $item);
                } catch (HttpExceptionInterface $exception) {
                    if ($exception->getStatusCode() !== 422) {
                        throw $exception;
                    }

                    return;
                }
                if ($item->final_sku !== $sku || $item->resolved_fulfillment_sku !== $sku) {
                    $changes[] = [$item, $sku];
                }
            }
            foreach ($changes as [$item, $sku]) {
                $before = ['final_sku' => $item->final_sku, 'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku];
                $item->forceFill(['final_sku' => $sku, 'resolved_fulfillment_sku' => $sku])->save();
                $this->audit->record(
                    AuditActor::user($actor, AuditSource::Web),
                    'order_item.final_sku_healed', $item::class, $item->getKey(),
                    $before, ['final_sku' => $sku, 'resolved_fulfillment_sku' => $sku],
                    'verified_current_warehouse_mapping',
                );
            }
        });
    }
}
