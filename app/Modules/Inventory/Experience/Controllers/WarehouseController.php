<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Identity\Application\AuthorizationKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WarehouseController
{
    public function __construct(private readonly InventoryService $inventory) {}
    public function create(): Response { return Inertia::render('Inventory/WarehouseCreate'); }
    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate($this->warehouseRules());
        $w=$this->inventory->createWarehouse($data,$request->user());
        return redirect()->route('inventory.warehouse.show',$w->slug)->with('success','仓库已创建');
    }
    public function show(string $warehouse): Response
    {
        $w=$this->inventory->warehouse($warehouse);
        return Inertia::render('Inventory/WarehouseShow',[
            'warehouse'=>$this->payload($w),
            'canOperate'=>auth()->user()?->can(AuthorizationKeys::InventoryOperate->value) ?? false,
            'canManage'=>auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false,
        ]);
    }
    public function update(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $rules=$this->warehouseRules($w->id); unset($rules['slug']);
        $this->inventory->updateWarehouse($w,$request->validate($rules),$request->user());
        return back()->with('success','仓库信息已保存');
    }
    public function billing(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['billing_markdown'=>['nullable','string','max:200000']]);
        $this->inventory->updateBilling($w,$data['billing_markdown'] ?? null,$request->user());
        return back()->with('success','仓库计费表已保存');
    }
    public function regions(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['country_codes'=>['array'],'country_codes.*'=>['string','size:2']]);
        $this->inventory->updateRegions($w,$data['country_codes'] ?? [],$request->user());
        return back()->with('success','服务地区已保存');
    }
    public function saveInventory(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate($this->inventoryRules()); $productId=(int)$data['product_id']; unset($data['product_id']);
        $this->inventory->saveInventory($w,$productId,$data,$request->user());
        return back()->with('success','库存已保存');
    }
    public function updateInventory(Request $request,string $warehouse,int $inventory): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate($this->inventoryRules(false));
        $this->inventory->updateInventory($w,$inventory,$data,$request->user());
        return back()->with('success','库存已更新');
    }
    private function warehouseRules(?int $ignoreId=null): array
    {
        return [
            'slug'=>['required','string','max:64','alpha_dash','unique:warehouses,slug'.($ignoreId?",{$ignoreId}":'')],
            'code'=>['required','string','max:64','unique:warehouses,code'.($ignoreId?",{$ignoreId}":'')],
            'name'=>['required','string','max:191'],'country_code'=>['nullable','string','size:2'],
            'address_line1'=>['nullable','string','max:255'],'address_line2'=>['nullable','string','max:255'],'city'=>['nullable','string','max:128'],
            'state_region'=>['nullable','string','max:128'],'postal_code'=>['nullable','string','max:32'],'timezone'=>['nullable','string','max:64'],
            'type'=>['required','string','max:32'],'is_active'=>['boolean'],'priority'=>['integer','min:0','max:9999'],
        ];
    }
    private function inventoryRules(bool $withProduct=true): array
    {
        $rules=[
            'quantity_on_hand'=>['required','integer','min:0'],'quantity_reserved'=>['required','integer','min:0'],
            'inbound_at'=>['nullable','date'],'location'=>['nullable','string','max:128'],'standard_qoh'=>['nullable','integer','min:0'],
            'expected_restock_qty'=>['nullable','integer','min:0'],
        ];
        if($withProduct)$rules=['product_id'=>['required','integer','exists:products,id'],...$rules];
        return $rules;
    }
    private function payload($w): array
    {
        return [
            'id'=>$w->id,'slug'=>$w->slug,'code'=>$w->code,'name'=>$w->name,'country_code'=>$w->country_code,
            'address_line1'=>$w->address_line1,'address_line2'=>$w->address_line2,'city'=>$w->city,'state_region'=>$w->state_region,'postal_code'=>$w->postal_code,
            'timezone'=>$w->timezone,'type'=>$w->type,'is_active'=>$w->is_active,'priority'=>$w->priority,'billing_markdown'=>$w->billing_markdown,
            'service_regions'=>$w->serviceRegions->pluck('country_code')->all(),
            'inventories'=>$w->inventories->map(fn($i)=>[
                'id'=>$i->id,'product'=>['id'=>$i->product->id,'sku'=>$i->product->sku,'name'=>$i->product->name],
                'quantity_on_hand'=>$i->quantity_on_hand,'quantity_reserved'=>$i->quantity_reserved,'quantity_available'=>$i->quantity_available,
                'inbound_at'=>$i->inbound_at?->format('Y-m-d'),'stay_days'=>$i->stay_days,'location'=>$i->location,'standard_qoh'=>$i->standard_qoh,'expected_restock_qty'=>$i->expected_restock_qty,
            ])->values()->all(),
        ];
    }
}
