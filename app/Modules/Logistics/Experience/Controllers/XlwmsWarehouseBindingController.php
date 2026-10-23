<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class XlwmsWarehouseBindingController
{
    private function connection(string $warehouse, InventoryService $inventory): WarehouseOpenApiConnection
    {
        $w = $inventory->warehouse($warehouse);
        abort_unless(strtoupper((string)$w->code) === 'US-SM', 404);
        return WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$w->id)->where('provider','xlwms')->firstOrFail();
    }

    public function options(string $warehouse, InventoryService $inventory, XlwmsOrderGateway $oms): JsonResponse
    {
        $entries=$oms->warehouseOptions($this->connection($warehouse,$inventory));
        // Show only display information from the OMS catalog.
        return response()->json(['warehouses'=>array_values(array_map(
            static fn(array $row): array=>[
                'code'=>(string)($row['whCode']??''),
                'name'=>(string)($row['whNameCn']??$row['whNameEn']??''),
                'country'=>(string)($row['countryCode']??'')
            ],
            array_filter($entries,static fn(array $r):bool=>filled($r['whCode']??null))
        ))])->header('Cache-Control','no-store, private');
    }

    public function bind(string $warehouse, Request $request, InventoryService $inventory, XlwmsOrderGateway $oms): JsonResponse
    {
        $input=$request->validate(['wh_code'=>['required','string','max:30']]);
        $entry=$this->connection($warehouse,$inventory);
        $allowed=collect($oms->warehouseOptions($entry))
            ->contains(fn(array $row):bool=>(string)($row['whCode']??'')===$input['wh_code']);
        abort_unless($allowed,422,'所选仓库代码不在 OMS 当前授权仓库中');
        $entry->oms_warehouse_code=$input['wh_code'];
        $entry->save();
        return response()->json(['status'=>'bound','wh_code'=>$input['wh_code']])
            ->header('Cache-Control','no-store, private');
    }
}
