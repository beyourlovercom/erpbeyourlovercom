<script setup lang="ts">
import {computed,ref,onMounted} from 'vue';
import {allocationStockWarnings} from '#/lib/allocationStockWarning';
import {allocationCarrierRoute,submitOriginalAllocation,resetOriginalAllocation,readAllocationProducts,readAllocationProductAvailability,type AllocationProduct,type AllocationAvailability,type AllocationItem,type AllocationAssignment,type AllocationPlan,type AllocationWarehouse,type LogisticsCarrierOption} from '#/api/erp-logistics';
const props=defineProps<{orderId:number;plan:AllocationPlan;warehouses:AllocationWarehouse[];carrierOptions?:LogisticsCarrierOption[];productLabels?:Record<number,string>;readOnly?:boolean}>();
const emit=defineEmits<{completed:[];reset:[orderId:number]}>();
const carriers=ref<Record<string,string>>({});
const choices=ref<Record<number,string>>({}),gifts=ref<Record<number,number>>({}),note=ref(''),ack=ref(false),busy=ref(false),error=ref(''),confirmReset=ref(false);
function choose(id:number){delete gifts.value[id];}
const products=ref<AllocationProduct[]>([]),catalogReady=ref(false),catalogBusy=ref(false),catalogError=ref('');
const replacements=ref<Record<number,number>>({}),searches=ref<Record<number,string>>({}),availability=ref<Record<number,AllocationAvailability[]>>({}),pending=ref<Record<number,boolean>>({}),availabilityError=ref<Record<number,string>>({});
const chosen=(i:AllocationItem)=>products.value.find(p=>p.id===replacements.value[i.id]);
function effective(i:AllocationItem):AllocationItem{const p=chosen(i);if(replacements.value[i.id])return {...i,productId:replacements.value[i.id],sku:p?.sku||i.sku,productName:p?.name||'',materialName:p?.materialName||'',availability:availability.value[i.id]||[]};return i.previousReplacementId?{...i,productId:i.sourceProductId??null,availability:i.sourceAvailability||[]}:i;}
const effectiveItems=computed(()=>props.plan.items.map(effective));
const hasReplacement=computed(()=>props.plan.items.some(i=>!!i.previousReplacementId||!!replacements.value[i.id]));
const unresolved=computed(()=>props.plan.items.some(i=>!!replacements.value[i.id]&&(!chosen(i)||pending.value[i.id])));
async function loadCatalog(){if(catalogReady.value||catalogBusy.value)return;catalogBusy.value=true;catalogError.value='';try{products.value=await readAllocationProducts();catalogReady.value=true;}catch{catalogError.value='商品目录读取失败，请重试。';}finally{catalogBusy.value=false;}}
function options(i:AllocationItem){const q=(searches.value[i.id]||'').trim().toLowerCase(),original=i.sourceProductId??i.productId;const matches=products.value.filter(p=>p.id!==original&&(p.id===replacements.value[i.id]||!q||[p.sku,p.name,p.materialName].some(v=>v.toLowerCase().includes(q))));const selected=chosen(i),visible=matches.slice(0,100);if(selected&&!visible.some(p=>p.id===selected.id))visible.unshift(selected);return visible;}
const requests:Record<number,number>={};
async function replaceItem(i:AllocationItem,id:number){const seq=(requests[i.id]||0)+1;requests[i.id]=seq;replacements.value[i.id]=id;choices.value[i.id]='';delete gifts.value[i.id];delete availability.value[i.id];delete availabilityError.value[i.id];pending.value[i.id]=false;if(!id)return;pending.value[i.id]=true;try{const data=await readAllocationProductAvailability(id);if(requests[i.id]===seq)availability.value[i.id]=data;}catch{if(requests[i.id]===seq)availabilityError.value[i.id]='替换商品库存未知，请人工核对。';}finally{if(requests[i.id]===seq)pending.value[i.id]=false;}}
onMounted(()=>{for(const i of props.plan.items){if(i.previousReplacementId&&!i.is_gift){void loadCatalog();void replaceItem(i,i.previousReplacementId);}}});
const assignments=computed<AllocationAssignment[]>(()=>props.plan.items.map(i=>{
 const source=choices.value[i.id]||'',route=props.plan.routes[source];
 return {order_item_id:i.id,source_code:source,gift_mapping_id:i.is_gift?gifts.value[i.id]||null:null,...(replacements.value[i.id]?{replacement_product_id:replacements.value[i.id]}:{}),...allocationCarrierRoute(source,route,carriers.value[source])};
}));
const warnings=computed(()=>allocationStockWarnings(effectiveItems.value.filter(i=>choices.value[i.id]!=='OUTSOURCE').map(i=>{const code=choices.value[i.id]||'',w=props.warehouses.find(w=>w.code===code);return {warehouseCode:code,warehouseName:w?.name||code,sku:i.sku,productId:i.productId??null,quantity:i.quantity,availableQuantity:i.availability?.find(a=>a.warehouseId===w?.id)?.available??null,isGift:i.is_gift}})));
const groups=computed(()=>Array.from(new Set(assignments.value.map(a=>a.source_code).filter(Boolean))).map(code=>({code,name:props.warehouses.find(w=>w.code===code)?.name||code,items:assignments.value.filter(a=>a.source_code===code).length})));
const valid=computed(()=>!unresolved.value&&props.plan.items.length>0&&assignments.value.every(a=>a.source_code&&(!props.plan.items.find(i=>i.id===a.order_item_id)?.is_gift||a.gift_mapping_id)));
async function resetAllocation(){
 if(props.readOnly||busy.value||!confirmReset.value||!props.plan.reallocationToken)return;
 confirmReset.value=false;busy.value=true;error.value='';
 try{await resetOriginalAllocation({orderId:props.orderId,token:props.plan.reallocationToken});emit('reset',props.orderId)}
 catch(e){error.value=(e instanceof Error?e.message:'重新分仓未确认')+'；请刷新核对，不要重复提交。'}finally{busy.value=false}
}
async function submit(mode:'manual'|'recommended'){
 if(props.readOnly||busy.value||mode==='manual'&&!valid.value||mode==='recommended'&&hasReplacement.value)return;
 if(!window.confirm(mode==='manual'?'确认提交人工分仓？':'确认采用原 ERP 推荐方案？'))return;
 busy.value=true;error.value='';
 try{await submitOriginalAllocation({orderId:props.orderId,plan:props.plan,warehouses:props.warehouses,mode,assignments:assignments.value,note:note.value,acknowledged:ack.value});emit('completed')}
 catch(e){error.value=e instanceof Error?e.message:'分仓未确认';}finally{busy.value=false}
}
</script>
<template>
<section class="space-y-3 rounded-lg border border-slate-200 p-3" data-testid="p0-allocation-panel">
<div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold">SKU 履约分配</h3><span class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">人工确认履约来源</span></div><p class="text-xs text-slate-500">先确认发货仓，再确认物流商。系统不能判断的项目由运营人工补齐。</p>
<p v-if="readOnly" role="status" class="rounded bg-amber-50 p-2 text-xs text-amber-800">只读验收：可选择仓库查看方案，提交、归档及采用推荐均已禁用。</p>

<button v-if="plan.reallocationToken && !readOnly" type="button" data-testid="erp2-reallocation-open" :disabled="busy" class="rounded border border-red-600 px-3 py-2 text-xs text-red-700" @click="confirmReset=true">重新分仓</button>
<div v-if="confirmReset" role="alertdialog" aria-label="确认重新分仓" data-testid="erp2-reallocation-confirm" class="space-y-2 rounded border border-red-500 bg-red-50 p-3 text-xs text-red-900">
 <strong>⚠️ 警告：此操作将归档所有相关子订单！</strong>
 <p>所有子订单均无物流单号时直接归档，母订单恢复待分仓；任一子订单已有物流单号时，请人工确认发货并手工调整母订单。归档不等于外部仓库撤单，已推送订单请核实仓库是否仍会发货。</p>
 <div class="flex gap-2"><button type="button" class="rounded border px-3 py-2" @click="confirmReset=false">取消</button><button type="button" data-testid="erp2-reallocation-submit" class="rounded bg-red-700 px-3 py-2 text-white" :disabled="busy" @click="resetAllocation">确认归档并重新分仓</button></div>
</div>
<p v-if="plan.usable" class="text-xs">推荐仓：{{plan.warehouses.join(' / ')}}</p>
<button v-if="plan.usable && plan.key && !readOnly && !hasReplacement" type="button" :disabled="busy" class="rounded border px-3 py-2 text-xs" @click="submit('recommended')">采用系统推荐</button>
<div class="overflow-x-auto rounded border border-slate-200"><table class="offline-allocation-table w-full border-collapse text-left text-xs">
<thead class="bg-slate-50 text-slate-600"><tr><th class="p-2">SKU / 产品</th><th class="p-2">数量</th><th class="p-2">商品替换</th><th class="p-2">库存参考</th><th class="p-2">系统推荐 / 人工备选</th><th class="p-2">选择发货仓</th><th class="p-2">选择物流商</th><th class="p-2">实际履约 SKU</th></tr></thead>
<tbody><tr v-for="item in plan.items" :key="item.id" class="border-t align-top"><td class="p-2"><strong>{{item.sku}}</strong><p class="mt-1 text-slate-500">{{item.productName||productLabels?.[item.id]||''}}</p><p v-if="item.materialName" class="text-slate-500">{{item.materialName}}</p></td><td class="p-2">{{item.quantity}}</td><td class="min-w-[200px] p-2"><template v-if="!item.is_gift"><button v-if="!catalogReady" type="button" :disabled="busy||catalogBusy" class="rounded border px-2 py-1" @click="loadCatalog">{{catalogBusy?'读取中…':'读取商品目录 / 替换'}}</button><template v-else><input v-model="searches[item.id]" :aria-label="'搜索替换商品 '+item.sku" class="w-full rounded border p-1" placeholder="搜索 SKU / 中英文名称"/><select :value="replacements[item.id]||0" :aria-label="'替换商品 '+item.sku" :disabled="busy" class="mt-1 w-full rounded border p-1" @change="replaceItem(item,Number(($event.target as HTMLSelectElement).value))"><option :value="0">保持原商品</option><option v-if="replacements[item.id]&&!chosen(item)" :value="replacements[item.id]">原替换商品未找到，请重新选择</option><option v-for="p in options(item)" :key="p.id" :value="p.id">{{p.sku}} · {{p.name}} · {{p.materialName}}</option></select></template><p v-if="catalogError" class="text-red-700">{{catalogError}}</p><p v-if="pending[item.id]">库存读取中…</p><p v-if="availabilityError[item.id]" class="text-amber-700">{{availabilityError[item.id]}}</p></template><span v-else>礼品选择真实 SKU</span></td><td class="max-w-[370px] p-2"><div class="flex flex-wrap gap-1"><span v-for="w in warehouses" :key="w.code" class="rounded bg-slate-100 px-1.5 py-1" :class="w.code==='FACTORY'?'text-blue-700':effective(item).availability?.find(a=>a.warehouseId===w.id)?.available==null?'text-amber-700':(effective(item).availability?.find(a=>a.warehouseId===w.id)?.available??0)>=item.quantity?'text-emerald-700':'text-red-700'">{{w.name}} · {{w.code==='FACTORY'?'国内工厂':effective(item).availability?.find(a=>a.warehouseId===w.id)?.available??'未知'}}</span></div></td><td class="p-2">{{plan.usable?plan.warehouses.join(' / '):'需要人工判断'}}</td><td class="min-w-[150px] p-2"><label class="block">发货仓<select v-model="choices[item.id]" :aria-label="'分仓 '+item.sku" :disabled="busy" class="w-full rounded border p-2" @change="choose(item.id)"><option value="">选择仓库</option><option v-for="w in warehouses" :key="w.code" :value="w.code">{{w.name}}</option></select></label></td><td class="min-w-[150px] p-2"><label v-if="choices[item.id]&&!['FACTORY','OUTSOURCE'].includes(choices[item.id]||'')" class="block">物流商<select :value="carriers[choices[item.id]||'']||plan.routes[choices[item.id]||'']?.carrier||''" :aria-label="'物流商 '+item.sku" :disabled="busy" class="w-full rounded border p-2" @change="carriers[choices[item.id]||'']=($event.target as HTMLSelectElement).value"><option value="">使用仓库默认物流商</option><option v-for="carrier in carrierOptions||[]" :key="carrier.value" :value="carrier.value">{{carrier.label}}</option></select><small>同一仓库商品共享物流商；更换物流商时服务类型交由原 ERP 确认。</small></label><span v-if="!choices[item.id]||['FACTORY','OUTSOURCE'].includes(choices[item.id]||'')" class="text-slate-400">—</span></td><td class="min-w-[150px] p-2"><label v-if="item.is_gift" class="block">礼品真实 SKU<select v-model.number="gifts[item.id]" :aria-label="'礼品 '+item.sku" :disabled="busy" class="w-full rounded border p-2"><option :value="0">选择真实 SKU</option><option v-for="g in item.gifts[choices[item.id]||'']||[]" :key="g.mapping_id" :value="g.mapping_id">{{g.fulfillment_sku}} · 可用 {{g.quantity_available??'未知'}}</option></select></label><span v-else>{{chosen(item)?.sku||(item.previousReplacementId?item.sku:item.resolvedSku||item.sku)}} <small v-if="chosen(item)">（仓库最终 SKU 由原 ERP 校验）</small></span></td></tr></tbody></table><p class="border-t p-2 font-medium text-amber-800">分仓规则：每个订单商品只归属一个履约来源，不拆数量；由原 ERP 生成 A/B/C 等履约单。</p></div>
<div v-if="groups.length" class="rounded bg-slate-50 p-2 text-xs"><p>分仓预览（最终子单由原 ERP 生成）</p><p v-for="g in groups" :key="g.code">{{g.name}}：{{g.items}} 个商品行</p></div>
<div v-if="warnings.length" class="rounded border border-amber-200 p-2 text-xs"><p>库存参考提示（不新增拦截）</p><p v-for="(w,i) in warnings" :key="i">{{w.warehouseName}} · {{w.sku}}：需求 {{w.requiredQuantity}}，可用 {{w.availableQuantity??'未知'}}</p></div>
<slot name="reference" :effective-items="effectiveItems"/>
<label class="block text-xs">运营备注（选填）<textarea v-model="note" maxlength="1000" :disabled="busy" class="w-full rounded border p-2"/></label>
<label class="block text-xs"><input v-model="ack" type="checkbox" :disabled="busy"/> 已人工确认库存例外（仅原 ERP 需要时使用）</label>
<button type="button" data-testid="p0-manual-allocate" :disabled="readOnly||!valid||busy" class="rounded bg-indigo-600 px-3 py-2 text-xs text-white disabled:opacity-50" @click="submit('manual')">确认人工分配并提交</button>
<p v-if="error" role="alert" class="text-xs text-red-700">{{error}}</p>
</section>
</template>
<style scoped>
.offline-allocation-table{min-width:1050px;width:100%;border-collapse:collapse}.offline-allocation-table th,.offline-allocation-table td{padding:8px;vertical-align:top}.offline-allocation-table td:nth-child(3){max-width:370px;min-width:180px}.offline-allocation-table td:nth-child(n+5){min-width:150px}
</style>
