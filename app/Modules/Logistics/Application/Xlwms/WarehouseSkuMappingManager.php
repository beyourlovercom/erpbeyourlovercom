<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Models\User;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseSkuMapping;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WarehouseSkuMappingManager
{
    public function __construct(
        private readonly XlwmsOrderGateway $gateway,
        private readonly AuditService $audit
    ) {}

    public function propose(int $warehouseId, string $erpSku, string $omsSku, string $note, int $actorId): WarehouseSkuMapping
    {
        $erpSku=trim($erpSku);
        $omsSku=trim($omsSku);
        if ($erpSku==='' || $omsSku==='' || $erpSku===$omsSku) {
            throw new HttpException(422,'相同 SKU 默认直接使用，不需要创建例外映射');
        }
        // Only real ERP business SKUs or existing order fulfillment SKUs.
        if (! Product::query()->where('sku',$erpSku)->exists()
            && ! ProductSkuAlias::query()->where('alias_sku',$erpSku)->exists()
            && ! OrderItem::query()->where('sku',$erpSku)->exists()
            && ! OrderItem::query()->where('resolved_fulfillment_sku',$erpSku)->exists()) {
            throw new HttpException(422,'ERP 未找到该 SKU 的有效业务来源');
        }

        return DB::transaction(function () use ($warehouseId,$erpSku,$omsSku,$note,$actorId): WarehouseSkuMapping {
            $norm=mb_strtoupper($erpSku);
            $record=WarehouseSkuMapping::query()->where('warehouse_id',$warehouseId)
                ->where('provider','xlwms')->where('erp_sku_norm',$norm)->lockForUpdate()->first();
            if ($record && $record->status==='active') {
                throw new HttpException(409,'当前 SKU 映射正在使用，请先停用再提出新版本');
            }
            $before=$record?->only(['erp_sku','oms_sku','status','evidence_note','confirmed_by','confirmed_at']);
            $record ??= new WarehouseSkuMapping([
                'warehouse_id'=>$warehouseId,'provider'=>'xlwms','erp_sku_norm'=>$norm,
            ]);
            $record->erp_sku=$erpSku;
            $record->oms_sku=$omsSku;
            $record->status='draft';
            $record->evidence_note=$note;
            $record->confirmed_at=null;
            $record->confirmed_by=null;
            $record->save();
            $this->audit->record(
                AuditActor::user(User::query()->findOrFail($actorId), AuditSource::Web),
                'xlwms.sku_mapping.proposed', WarehouseSkuMapping::class, $record->id,
                before:$before, after:$record->only(['erp_sku','oms_sku','status','evidence_note']),
                reason:'warehouse_sku_mapping_proposal'
            );
            return $record;
        });
    }

    public function confirm(int $warehouseId, int $mappingId, int $actorId, string $statement, string $evidence): WarehouseSkuMapping
    {
        $mapping=WarehouseSkuMapping::query()
            ->where('warehouse_id',$warehouseId)->where('provider','xlwms')->findOrFail($mappingId);
        $expected='CONFIRM_SKU_EQUIVALENCE:'.$mapping->erp_sku.'='.$mapping->oms_sku;
        if (! hash_equals($expected, $statement) || mb_strlen(trim($evidence)) < 24) {
            throw new HttpException(422,'请确认实物型号一致，并提供可审计的条码或型号核验证据');
        }
        if ($mapping->status!=='draft') throw new HttpException(409,'仅待确认映射可以激活');
        $connection=WarehouseOpenApiConnection::query()->where('warehouse_id',$warehouseId)
            ->where('provider','xlwms')->firstOrFail();
        if (! filled($connection->oms_warehouse_code)) {
            throw new HttpException(409,'OMS 实际仓库尚未绑定');
        }
        // Confirm remote product exists/approved, but this alone does NOT prove
        // product equivalence: the user must provide separate physical evidence.
        $this->gateway->assertSkuApproved($connection, $mapping->oms_sku);
        return DB::transaction(function() use($warehouseId,$mappingId,$mapping,$actorId,$evidence): WarehouseSkuMapping {
            $fresh=WarehouseSkuMapping::query()->where('warehouse_id',$warehouseId)
                ->lockForUpdate()->findOrFail($mappingId);
            if ($fresh->status!=='draft' || $fresh->oms_sku!==$mapping->oms_sku
                || $fresh->erp_sku!==$mapping->erp_sku) {
                throw new HttpException(409,'映射审核过程中已变更，请重新检查');
            }
            $before=$fresh->only(['erp_sku','oms_sku','status','confirmed_by','confirmed_at']);
            $fresh->status='active';
            $fresh->evidence_note=trim($evidence);
            $fresh->confirmed_by=$actorId;
            $fresh->confirmed_at=now();
            $fresh->save();
            $this->audit->record(
                AuditActor::user(User::query()->findOrFail($actorId), AuditSource::Web),
                'xlwms.sku_mapping.confirmed', WarehouseSkuMapping::class, $fresh->id,
                before:$before,
                after:$fresh->only(['erp_sku','oms_sku','status','confirmed_by','confirmed_at','evidence_note']),
                reason:'warehouse_sku_physical_equivalence_verified'
            );
            return $fresh;
        });
    }

    public function disable(int $warehouseId, int $mappingId, int $actorId): WarehouseSkuMapping
    {
        return DB::transaction(function() use($warehouseId,$mappingId,$actorId): WarehouseSkuMapping {
            $mapping=WarehouseSkuMapping::query()->where('warehouse_id',$warehouseId)
                ->where('provider','xlwms')->lockForUpdate()->findOrFail($mappingId);
            $before=$mapping->only(['erp_sku','oms_sku','status','confirmed_by','confirmed_at']);
            $mapping->status='disabled';
            $mapping->confirmed_by=null;
            $mapping->confirmed_at=null;
            $mapping->save();
            $this->audit->record(
                AuditActor::user(User::query()->findOrFail($actorId), AuditSource::Web),
                'xlwms.sku_mapping.disabled', WarehouseSkuMapping::class, $mapping->id,
                before:$before, after:$mapping->only(['erp_sku','oms_sku','status']),
                reason:'warehouse_sku_mapping_disabled'
            );
            return $mapping;
        });
    }
}
