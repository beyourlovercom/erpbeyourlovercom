<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use RuntimeException;

final class WarehouseBillReconciliationService
{
    public function __construct(private readonly MorePlusCostBridgeService $morePlus) {}

    public function supports(WarehouseBillImportBatch $batch): bool
    {
        return strtolower((string) $batch->provider) === 'moreplus';
    }

    /** @return array<string,mixed> */
    public function preview(Warehouse $warehouse, WarehouseBillImportBatch $batch): array
    {
        $this->assertBatchWarehouse($warehouse, $batch);
        if (! $this->supports($batch)) throw new RuntimeException('warehouse_bill_reconciliation_provider_unsupported');
        return $this->morePlus->preview((int) $batch->id);
    }

    /** @return array<string,mixed> */
    public function applyExact(Warehouse $warehouse, WarehouseBillImportBatch $batch, string $expectedSource, string $expectedPlan): array
    {
        $this->assertBatchWarehouse($warehouse, $batch);
        if (! $this->supports($batch)) throw new RuntimeException('warehouse_bill_reconciliation_provider_unsupported');
        if (app()->environment('production') && ! filter_var(getenv('ERP_WAREHOUSE_BILL_RECONCILIATION_WEB_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('warehouse_bill_reconciliation_production_apply_disabled');
        }
        $fresh = $this->morePlus->preview((int) $batch->id);
        $source = strtolower(trim($expectedSource));
        $plan = strtolower(trim($expectedPlan));
        if (preg_match('/^[a-f0-9]{64}$/D', $source) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1
            || ! hash_equals($source, strtolower((string) $fresh['source_checksum']))
            || ! hash_equals($plan, strtolower((string) $fresh['plan_checksum']))) {
            throw new RuntimeException('warehouse_bill_reconciliation_preflight_mismatch');
        }
        return $this->morePlus->apply((int) $batch->id);
    }

    /** @return list<array<string,mixed>> */
    public function history(Warehouse $warehouse, int $limit = 6): array
    {
        return WarehouseBillImportBatch::query()->where('warehouse_id', $warehouse->id)->latest('id')->limit($limit)->get()->map(function (WarehouseBillImportBatch $batch) use ($warehouse): array {
            $row = [
                'id'=>(int)$batch->id,'provider'=>(string)$batch->provider,'invoice_number'=>$batch->invoice_number,'invoice_date'=>$batch->invoice_date?->toDateString(),
                'currency'=>(string)$batch->currency,'invoice_total'=>(string)$batch->invoice_total,'shipping_total'=>(string)$batch->shipping_total,
                'parcel_outbound_total'=>(string)$batch->parcel_outbound_total,'storage_total'=>(string)$batch->storage_total,'total_rows'=>(int)$batch->total_rows,
                'source_checksum'=>(string)$batch->source_checksum,'applied_at'=>$batch->applied_at?->toIso8601String(),'supported'=>$this->supports($batch),'reconciliation'=>null,
            ];
            if (! $this->supports($batch) || (string)$batch->status !== 'applied') return $row;
            try {
                $preview = $this->preview($warehouse, $batch);
                $row['reconciliation'] = [
                    'plan_checksum'=>(string)$preview['plan_checksum'],
                    'shipping'=>array_intersect_key($preview['shipping'],array_flip(['ready','review','excluded','skip'])),
                    'parcel_outbound'=>array_intersect_key($preview['parcel_outbound'],array_flip(['ready','review','excluded','skip'])),
                    'storage'=>array_intersect_key($preview['storage'],array_flip(['ready_count','review_count','excluded_count','already_applied','batch_id'])),
                    'status'=>$this->status($preview),
                ];
            } catch (\Throwable $e) {
                $row['reconciliation']=['status'=>'error','error'=>$e->getMessage()];
            }
            return $row;
        })->all();
    }

    /** @param array<string,mixed> $preview */
    private function status(array $preview): string
    {
        $ready=(int)$preview['shipping']['ready']+(int)$preview['parcel_outbound']['ready'];
        $review=(int)$preview['shipping']['review']+(int)$preview['parcel_outbound']['review']+(int)($preview['storage']['review_count']??0);
        $done=$ready===0 && (int)$preview['shipping']['skip']>0 && (int)$preview['parcel_outbound']['skip']>0 && (bool)($preview['storage']['already_applied']??false);
        if ($done) return $review>0?'applied_with_review':'applied';
        return $ready>0?'ready':'review';
    }

    private function assertBatchWarehouse(Warehouse $warehouse, WarehouseBillImportBatch $batch): void
    {
        if ((int)$batch->warehouse_id !== (int)$warehouse->id) throw new RuntimeException('warehouse_bill_reconciliation_warehouse_mismatch');
        if ((string)$batch->status !== 'applied') throw new RuntimeException('warehouse_bill_reconciliation_batch_not_applied');
    }
}
