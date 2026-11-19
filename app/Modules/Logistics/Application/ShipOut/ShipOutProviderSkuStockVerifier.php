<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use RuntimeException;

/** Read-only, never changes inventory; resolves exact physical provider SKUs
 * and checks actual OMS AVAILABLE stock before the one-shot dispatch.
 */
final class ShipOutProviderSkuStockVerifier
{
    public function __construct(
        private readonly ShipOutReadOnlyGateway $gateway,
        private readonly VerifiedOmsProductIdentityResolver $identity,
        private readonly WarehouseFinalSkuResolver $finalSkus,
    ) {}

    /** @param array<int,array<string,mixed>> $payload
     * @return array<int,array<string,mixed>>
     */
    public function verify(Order $order, array $payload, string $warehouseId): array
    {
        if (! config('shipout.sandbox_inventory_preflight_enabled',true)) return $payload;
        $org=trim((string)config('shipout.sandbox_client_org_id',''));
        if ($org==='' || $warehouseId==='') {
            throw new RuntimeException('SHIPOUT_STOCK_IDENTITY_UNAVAILABLE');
        }
        $providers=[];
        $stocks=[];
        // Fail closed on pagination that exceeds a bounded operational query.
        $complete=false;
        for ($page=1;$page<=10;$page++) {
            $batch=$this->gateway->productPage($page,100);
            foreach ($batch['records'] as $row) {
                $sku=$row['omsSku'];
                if (isset($providers[$sku])) throw new RuntimeException('SHIPOUT_PROVIDER_SKU_AMBIGUOUS');
                $providers[$sku]=$row;
            }
            if (count($providers)>=$batch['total']){$complete=true;break;}
        }
        if (! $complete) throw new RuntimeException('SHIPOUT_PRODUCT_CATALOG_INCOMPLETE');
        $complete=false;
        for ($page=1;$page<=10;$page++) {
            $batch=$this->gateway->stockPage($org,$warehouseId,$page,100);
            foreach ($batch['records'] as $row) {
                if (isset($stocks[$row['omsSku']])) throw new RuntimeException('SHIPOUT_PROVIDER_SKU_AMBIGUOUS');
                $stocks[$row['omsSku']]=$row;
            }
            if (count($stocks)>=$batch['total']){$complete=true;break;}
        }
        if (! $complete)throw new RuntimeException('SHIPOUT_STOCK_CATALOG_INCOMPLETE');

        $candidates=[];
        foreach ($providers as $sku=>$row) {
            $identity=$this->identity->resolve($sku);
            if (($identity['status']??null)!=='resolved')continue;
            $pid=(int)$identity['product_id'];
            $candidates[$pid][]=$sku;
        }
        $order->loadMissing('items', 'shipment');
        $items=$order->items->values();
        $list=$payload[0]['shipmentForms'][0]['productList']??[];
        if (count($list)!==$items->count()) throw new RuntimeException('SHIPOUT_SKU_NOT_VERIFIED');
        $used=[];
        foreach ($items as $index=>$item) {
            $pid=(int)$item->product_id;
            $finalSku=trim((string)$item->final_sku);
            if ($finalSku!=='') {
                // A verified warehouse final SKU is frozen by the payload factory.
                // The read-only provider stock check must never replace it with
                // the source SKU or another alias for the same product.
                $payloadSku=trim((string)($list[$index]['omsSku']??''));
                if ($payloadSku!==$finalSku)throw new RuntimeException('SHIPOUT_FINAL_SKU_PAYLOAD_MISMATCH');
                $resolved=$this->identity->resolve($finalSku);
                if (($resolved['status']??null)==='resolved'
                    && (int)$resolved['product_id']!==$pid) {
                    throw new RuntimeException('SHIPOUT_SKU_NOT_VERIFIED');
                }
                $mapping=$this->finalSkus->mapped($pid,(int)($order->shipment?->warehouse_id??0));
                if (($resolved['status']??null)!=='resolved'
                    && ($mapping===null || strcasecmp($mapping,$finalSku)!==0)) {
                    throw new RuntimeException('SHIPOUT_SKU_NOT_VERIFIED');
                }
                if (!isset($providers[$finalSku]))throw new RuntimeException('SHIPOUT_PROVIDER_SKU_NOT_FOUND');
                $sku=$finalSku;
            } else {
                // Legacy orders without final_sku retain the existing alias
                // compatibility path until a separately approved backfill.
                $erpSku=trim((string)$item->sku);
                $resolved=$this->identity->resolve($erpSku);
                if (($resolved['status']??null)!=='resolved'||(int)$resolved['product_id']!==$pid)
                    throw new RuntimeException('SHIPOUT_SKU_NOT_VERIFIED');
                $options=$candidates[$pid]??[];
                if (in_array($erpSku,$options,true))$sku=$erpSku;
                elseif(count($options)===1)$sku=$options[0];
                elseif($options===[])throw new RuntimeException('SHIPOUT_PROVIDER_SKU_NOT_FOUND');
                else throw new RuntimeException('SHIPOUT_PROVIDER_SKU_AMBIGUOUS');
            }
            if (isset($used[$sku]))throw new RuntimeException('SHIPOUT_DUPLICATE_SKU_REQUIRES_AGGREGATION');
            $used[$sku]=true;
            $qty=(int)$item->quantity;
            $stock=$stocks[$sku]??null;
            if ($stock===null)throw new RuntimeException('SHIPOUT_PROVIDER_SKU_OUT_OF_STOCK');
            if ((int)$stock['availableQuantity']<$qty)
                throw new RuntimeException('SHIPOUT_PROVIDER_SKU_OUT_OF_STOCK');
            $list[$index]=['omsSku'=>$sku,'qty'=>$qty];
        }
        $payload[0]['shipmentForms'][0]['productList']=$list;
        return $payload;
    }
}
