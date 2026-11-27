<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMaterialVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProductSkuController
{
    private const CODES=['FACTORY','DE','CA','US-LA','US-NY','US-SM'];
    private const HEADERS=['产品图片','ERP SKU','库存表代码','库存表名称','网站 SKU','中国工厂','德国仓','加拿大仓','美西 MorePlus','美东','美世迈仓','别名数量'];
    private const EDIT_HEADERS=['类型','ERP SKU','库存表代码','库存表名称','仓库代码','仓库 SKU','核实依据','确认同一商品','工厂库存范围已核实'];

    private function query(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $filters=$request->only(['search']);
        $search=trim((string)($filters['search']??''));
        $query=Product::query()->with(['materialVersions','warehouseSkus','media','providerSnapshots'])
            ->withCount('skuAliases');
        if ($search!=='') {
            $pattern='%'.$search.'%';
            $query->where(function($q) use($pattern) {
                $q->where('sku','like',$pattern)->orWhere('material_full_name','like',$pattern)
                    ->orWhereHas('materialVersions',fn($m)=>$m->where('material_code','like',$pattern)->orWhere('material_name','like',$pattern))
                    ->orWhereHas('warehouseSkus',fn($m)=>$m->where('sku','like',$pattern))
                    ->orWhereHas('providerSnapshots',fn($m)=>$m->where('provider_sku','like',$pattern))
                    ->orWhereHas('skuAliases',fn($m)=>$m->where('alias_sku','like',$pattern));
            });
        }
        $missing=$request->query('missing');
        if ($missing==='material') $query->whereDoesntHave('materialVersions');
        elseif (in_array($missing, self::CODES, true)) {
            $warehouse=Warehouse::query()->where('code',$missing)->where('is_active',true)->first();
            if ($warehouse) $query->whereDoesntHave('warehouseSkus',fn($q)=>$q->where('warehouse_id',$warehouse->id)->where('is_active',true)->whereNotNull('verified_at'));
        }
        return $query->orderBy('sku')->orderBy('id');
    }

    private function project(Product $p, $warehouses): array
    {
        $codes=self::CODES;
            $mappings=[];
            foreach($codes as $code) {
                $warehouse=$warehouses->get($code);
                $m=$warehouse ? $p->warehouseSkus->firstWhere('warehouse_id',$warehouse->id) : null;
                $verified=$m && $m->is_active && $m->verified_at;
                $fallback=$code==='FACTORY' && $warehouse && !$verified && $p->is_active && $p->sku && strcasecmp($p->sku,'gift')!==0;
                $mappings[$code]=['warehouse_id'=>$warehouse?->id,'sku'=>$verified?$m->sku:($fallback?$p->sku:null),
                    'status'=>$verified?'verified':($fallback?'factory_default':($m?'inactive':'missing'))];
            }
            $media=$p->media->sortByDesc('is_primary')->first();
            $image=null;
            if ($media && $media->storage_path && Storage::disk('local')->exists($media->storage_path)) $image=route('products.media.show',$media->id);
            elseif ($media && filter_var($media->source_url,FILTER_VALIDATE_URL) && str_starts_with($media->source_url,'https://')) $image=$media->source_url;
            return ['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'source_type'=>$p->source_type,'is_active'=>(bool)$p->is_active,
                'material_name'=>$p->material_full_name,'image_url'=>$image,
                'material_versions'=>$p->materialVersions->map(fn($m)=>$m->only(['id','material_code','material_name','factory_stock_approved','factory_physical_qty']))->values(),
                'website_skus'=>$p->providerSnapshots->where('provider','woocommerce')->pluck('provider_sku')->filter()->unique()->values(),
                'mappings'=>$mappings,'alias_count'=>$p->sku_aliases_count];
    }

    public function index(Request $request): Response
    {
        $filters=$request->only(['search','missing']);
        $search=trim((string)($filters['search']??''));
        $warehouses=Warehouse::query()->where('is_active',true)->get()->keyBy(fn($w)=>strtoupper($w->code));
        $products=$this->query($request)->paginate(50)->withQueryString();
        $products->through(fn(Product $p)=>$this->project($p,$warehouses));
        return Inertia::render('Products/Skus',[
            'products'=>$products->toArray(),'filters'=>$filters,
            'unmappedMaterialCount'=>ProductMaterialVersion::query()->whereNull('product_id')->count(),
            'canOperate'=>(bool)$request->user()?->can('products.operate'),
            'canApproveStock'=>(bool)$request->user()?->can('inventory.operate'),
            'unmappedMaterials'=>ProductMaterialVersion::query()->whereNull('product_id')
                ->when($search!=='',fn($q)=>$q->where(fn($q)=>$q->where('material_code','like','%'.$search.'%')->orWhere('material_name','like','%'.$search.'%')))
                ->orderBy('material_code')->limit(50)->get(['id','material_code','material_name','factory_physical_qty']),
            'productOptions'=>$request->user()?->can('products.operate')?Product::query()->where('is_active',true)->whereNotNull('sku')->orderBy('sku')->get(['id','sku']):[],

        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $warehouses=Warehouse::query()->where('is_active',true)->get()->keyBy(fn($w)=>strtoupper($w->code));
        $book=new Spreadsheet();
        $matrix=$book->getActiveSheet();$matrix->setTitle('SKU映射中心');
        $matrix->fromArray(self::HEADERS,null,'A1');
        $n=2;
        foreach($this->query($request)->get() as $p) {
            $r=$this->project($p,$warehouses);
            $values=[$r['image_url']??'', $r['sku']??'',
                $r['material_versions']->pluck('material_code')->implode("\n"),
                $r['material_versions']->pluck('material_name')->implode("\n") ?: ($r['material_name']??''),
                $r['website_skus']->implode("\n")];
            foreach(self::CODES as $code) $values[]=$r['mappings'][$code]['sku']??'';
            $values[]=(string)$r['alias_count'];
            foreach($values as $i=>$value) $matrix->setCellValueExplicit([$i+1,$n],(string)$value,DataType::TYPE_STRING);
            $n++;
        }
        $matrix->freezePane('C2');$matrix->setAutoFilter('A1:L'.max(1,$n-1));
        foreach(['A'=>30,'B'=>22,'C'=>30,'D'=>65,'E'=>24,'F'=>22,'G'=>22,'H'=>22,'I'=>22,'J'=>22,'K'=>22,'L'=>12] as $col=>$width) $matrix->getColumnDimension($col)->setWidth($width);
        $matrix->getStyle('A1:L1')->getFont()->setBold(true);
        $matrix->getStyle('C2:D'.max(2,$n-1))->getAlignment()->setWrapText(true);
        $edit=$book->createSheet();$edit->setTitle('批量维护');$edit->fromArray(self::EDIT_HEADERS);
        $edit->freezePane('C2');$edit->getStyle('A1:I1')->getFont()->setBold(true);
        foreach(['A'=>14,'B'=>22,'C'=>28,'D'=>65,'E'=>20,'F'=>25,'G'=>65,'H'=>20,'I'=>26] as $col=>$width) $edit->getColumnDimension($col)->setWidth($width);
        $raw=$book->createSheet();$raw->setTitle('待绑定物料');$raw->fromArray(['库存表代码','库存表名称','原表工厂数量']);
        $n=2;
        foreach(ProductMaterialVersion::query()->whereNull('product_id')->orderBy('material_code')->get() as $m) {
            foreach([$m->material_code,$m->material_name,(string)($m->factory_physical_qty??'')] as $i=>$v)
                $raw->setCellValueExplicit([$i+1,$n],(string)$v,DataType::TYPE_STRING);
            $n++;
        }
        $raw->getColumnDimension('A')->setWidth(28);$raw->getColumnDimension('B')->setWidth(70);
        $book->setActiveSheetIndex(0);
        return response()->streamDownload(function()use($book){try{(new Xlsx($book))->save('php://output');}finally{$book->disconnectWorksheets();}},
            'ERP-SKU-mapping-'.now()->format('Ymd').'.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function batch(Request $request, ProductMaterialVersionController $materials, ProductWarehouseSkuController $warehouseSkus): \Illuminate\Http\JsonResponse
    {
        $data=$request->validate(['file'=>'required|file|mimes:xlsx|max:10240']);
        $reader=new XlsxReader();
        $reader->setLoadSheetsOnly('批量维护');
        $book=null;
        $rows=[];
        try {
            $book=$reader->load($data['file']->getRealPath());
            $sheet=$book->getSheetByName('批量维护');
            abort_unless($sheet,422,'缺少批量维护工作表');
            abort_if($sheet->getHighestDataRow()>1001,422,'一次最多维护1000条记录');
            $header=[];
            foreach(range(1,9) as $col) $header[]=trim((string)$sheet->getCell([$col,1])->getValue());
            abort_unless($header===self::EDIT_HEADERS,422,'批量维护表头不匹配，请下载最新模板');
            for($n=2;$n<=$sheet->getHighestDataRow();$n++) {
                $row=[];
                foreach(range(1,9) as $col) {
                    $cell=$sheet->getCell([$col,$n]);
                    abort_if($cell->getDataType()===DataType::TYPE_FORMULA,422,"第{$n}行禁止公式");
                    $value=$cell->getValue();
                    abort_if($value!==null&&!is_scalar($value),422,"第{$n}行内容无效");
                    $row[]=trim((string)$value);
                }
                if(implode('',$row)!=='') $rows[$n]=$row;
            }
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            abort(422,'Excel 文件无法解析，请重新下载模板并保存为 XLSX');
        } finally {$book?->disconnectWorksheets();}
        abort_if($rows===[],422,'没有待维护记录，请填写批量维护工作表');
        $count=DB::transaction(function()use($rows,$request,$materials,$warehouseSkus){
            // Lock in a deterministic order; each row uses the same identity and authorization gates as single edit.
            $allWarehouses=Warehouse::query()->where('is_active',true)->orderBy('id')->lockForUpdate()->get()->keyBy(fn($w)=>strtoupper($w->code));
            $seen=[];
            foreach($rows as $n=>[$type,$erp,$code,$name,$warehouse,$sku,$evidence,$confirmed,$approved]) {
                abort_unless($confirmed==='是',422,"第{$n}行未确认同一商品");
                $products=Product::query()->whereRaw('UPPER(TRIM(sku)) = ?',[mb_strtoupper($erp)])->where('is_active',true)->get();
                abort_unless($products->count()===1,422,"第{$n}行ERP SKU不唯一或不存在");
                $product=$products->first();
                if($type==='物料') {
                    abort_unless(in_array($approved,['是','否'],true)&&$warehouse===''&&$sku==='',422,"第{$n}行物料字段不完整或混用了仓库字段");
                    $key='material:'.ProductMaterialVersion::keyFor($code);
                    abort_if(isset($seen[$key]),422,"第{$n}行重复物料代码");
                    $payload=Validator::make(['material_code'=>$code,'material_name'=>$name,'binding_evidence'=>$evidence,
                        'same_physical_product_confirmed'=>true,'factory_stock_approved'=>$approved==='是'],[
                        'material_code'=>'required|string|max:128','material_name'=>'required|string|max:10000','binding_evidence'=>'required|string|min:20|max:1000',
                        'same_physical_product_confirmed'=>'accepted','factory_stock_approved'=>'required|boolean'])->validate();
                    $materials->applyVerified($request,(int)$product->id,$payload);
                } elseif($type==='仓库') {
                    $w=$allWarehouses->get(strtoupper($warehouse));
                    abort_unless($w&&in_array(strtoupper($w->code),self::CODES,true)&&$code===''&&$name===''&&$approved==='',422,"第{$n}行仓库字段无效或混用了物料字段");
                    $key='warehouse:'.$w->id.':'.$product->id;
                    abort_if(isset($seen[$key]),422,"第{$n}行重复商品仓库组合");
                    $payload=Validator::make(['sku'=>$sku,'verification_evidence'=>$evidence,'same_physical_product_confirmed'=>true],[
                        'sku'=>'required|string|max:128','verification_evidence'=>'required|string|min:20|max:500','same_physical_product_confirmed'=>'accepted'])->validate();
                    $warehouseSkus->applyVerified($request,(int)$product->id,(int)$w->id,$payload);
                } else abort(422,"第{$n}行类型须为物料或仓库");
                $seen[$key]=true;
            }
            return count($seen);
        });
        return response()->json(['updated'=>$count,'message'=>'批量映射已核实保存，不直接改写订单或库存数量']);
    }
}
