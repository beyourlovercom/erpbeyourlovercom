<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Logistics\Application\Xlwms\WarehouseSkuMappingManager;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseSkuMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class XlwmsSkuMappingController
{
    private function warehouseId(string $warehouse, InventoryService $inventory): int
    {
        $record=$inventory->warehouse($warehouse);
        abort_unless(strtoupper((string) $record->code)==='US-SM',404);
        return (int) $record->id;
    }

    public function index(string $warehouse, InventoryService $inventory): JsonResponse
    {
        $id=$this->warehouseId($warehouse,$inventory);
        $rows=WarehouseSkuMapping::query()->where('warehouse_id',$id)
            ->where('provider','xlwms')->orderBy('erp_sku_norm')->get()
            ->map(fn(WarehouseSkuMapping $m):array=>[
                'id'=>$m->id,'erp_sku'=>$m->erp_sku,'oms_sku'=>$m->oms_sku,
                'status'=>$m->status,'evidence_note'=>$m->evidence_note,
                'confirmed_at'=>$m->confirmed_at?->toIso8601String(),
            ])->values()->all();
        return response()->json(['warehouse'=>'US-SM','mappings'=>$rows,'auto_dispatch_enabled'=>false])
            ->header('Cache-Control','no-store, private');
    }

    public function propose(
        string $warehouse,
        Request $request,
        InventoryService $inventory,
        WarehouseSkuMappingManager $manager
    ): JsonResponse {
        $id=$this->warehouseId($warehouse,$inventory);
        $data=$request->validate([
            'erp_sku'=>['required','string','max:128','regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'oms_sku'=>['required','string','max:128','regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'note'=>['required','string','min:8','max:1000'],
        ]);
        $m=$manager->propose($id,$data['erp_sku'],$data['oms_sku'],$data['note'], (int) $request->user()->id);
        return response()->json(['id'=>$m->id,'status'=>'draft','erp_sku'=>$m->erp_sku,
            'oms_sku'=>$m->oms_sku,'affects_outbound'=>false])
            ->header('Cache-Control','no-store, private');
    }

    public function confirm(
        string $warehouse,
        int $mapping,
        Request $request,
        InventoryService $inventory,
        WarehouseSkuMappingManager $manager
    ): JsonResponse {
        $id=$this->warehouseId($warehouse,$inventory);
        $data=$request->validate([
            'confirmation'=>['required','string','max:320'],
            'evidence'=>['required','string','min:24','max:2000'],
        ]);
        $m=$manager->confirm($id,$mapping,(int)$request->user()->id,
            $data['confirmation'],$data['evidence']);
        return response()->json(['id'=>$m->id,'status'=>$m->status,
            'erp_sku'=>$m->erp_sku,'oms_sku'=>$m->oms_sku])
            ->header('Cache-Control','no-store, private');
    }

    public function disable(string $warehouse, int $mapping, Request $request, InventoryService $inventory,
        WarehouseSkuMappingManager $manager): JsonResponse {
        $id=$this->warehouseId($warehouse,$inventory);
        $m=$manager->disable($id,$mapping,(int) $request->user()->id);
        return response()->json(['id'=>$m->id,'status'=>'disabled'])
            ->header('Cache-Control','no-store, private');
    }
}
