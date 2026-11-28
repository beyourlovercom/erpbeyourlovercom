<script setup lang="ts">
import { ref, watch, onBeforeUnmount, computed } from 'vue'

type Component = {component_type:string;amount:number;currency:string;source_sheet?:string|null;source_cells?:string|null;billable_weight?:number;weight_unit?:string}
type RateRow = {warehouse_code:string;carrier:string|null;service:string|null;version_number?:number;version_id?:number;version_status?:string;status:string;reason:string|null;amount_cny:number|null;native_totals:Record<string,number>|null;extra_item_usd:number|null;components?:Component[];zone?:string|null}
type Result = {status:string;reason:string|null;country:string;as_of:string;measurement:{weight_kg:number;dimensions_cm:number[]|null;item_count:number;basis:string}|null;rows:RateRow[];warehouse_rows?:RateRow[];factory_default_reference?:RateRow|null}
const props=defineProps<{orderId:number;revision:string;disabled?:boolean;embedded?:boolean;refreshKey?:number;warehouses:{code:string;name:string}[]}>()
const data=ref<Result|null>(null)
const loading=ref(false)
const orderedRows=computed(()=>[...(data.value?.warehouse_rows??[])].filter(row=>row.warehouse_code!=='DE'||props.warehouses.some(w=>w.code==='DE')).sort((a,b)=>Number(b.warehouse_code==='FACTORY')-Number(a.warehouse_code==='FACTORY')))
const error=ref(false)
let controller:AbortController|null=null
let sequence=0
const names:Record<string,string>={ground_advantage:'USPS Ground Advantage',express:'GOFO Express',domestic:'DHL 本地',international:'DHL 国际',sd_standard_special:'SD标准-特货',sd_standard_battery:'SD标准挂号-带电',sd_standard_general:'SD标准挂号-普货',sd_economy_battery:'SD经济挂号-带电',sd_economy_general:'SD经济挂号-普货',sd_europe_tax_general:'SDZH欧洲含税-普货',sd_europe_tax_battery:'SDZH欧洲含税-带电',sd_europe_tax_cosmetic:'SDZH欧洲含税-化妆品',sd_island_general:'SD岛屿专线-普货',sd_small:'SD-S小包'}
const reasons:Record<string,string>={missing_destination:'缺少有效目的国',unresolved_product:'存在未确认实际商品或礼品',missing_packaging_standard:'缺少商品重量，暂无法估算',rate_scope_missing:'无对应仓库报价',cargo_or_route_unverified:'暂无已核实适用渠道',rate_version_ambiguous:'存在多个报价来源，需核实',eligibility_unconfirmed:'待核实渠道适用性',supplier_catalog_restriction:'原报价明确限制货物类型，渠道不适用',rule_unsupported:'计费规则待核实',postal_zone_missing_or_ambiguous:'邮编分区待核实',postal_code_invalid:'缺少有效邮编',postal_rate_excluded:'邮编不适用',weight_band_missing_or_ambiguous:'未匹配重量档',package_dimensions_outside_rule:'超尺寸或低于最小尺寸',package_weight_outside_rule:'超限重',rate_rule_unreviewed:'报价规则待审核',rate_coverage_unverified:'费用覆盖待审核'}
function name(row:RateRow){return props.warehouses.find(w=>w.code===row.warehouse_code)?.name??row.warehouse_code}
function key(row:RateRow){return [row.warehouse_code,row.carrier,row.service,row.version_id].join('|')}
function amount(row:RateRow){return row.amount_cny!==null?'¥'+Number(row.amount_cny).toFixed(2):'—'}
function native(row:RateRow){return Object.entries(row.native_totals??{}).map(([c,v])=>c+' '+Number(v).toFixed(2)).join(' + ')}
async function refresh(scope=''){
  controller?.abort();const seq=++sequence
  data.value=null;error.value=false
  if(props.disabled){loading.value=false;return}
  const c=new AbortController();controller=c;loading.value=true
  try{
    const url='/logistics/orders/'+props.orderId+'/fulfillment-allocation/rate-preview'+(scope?'?confirmed_scope='+encodeURIComponent(scope):'')
    const res=await fetch(url,{signal:c.signal,credentials:'same-origin',headers:{Accept:'application/json'}})
    if(!res.ok)throw new Error('rate preview')
    const result=await res.json()
    if(seq===sequence)data.value=result
  }catch(e){if(seq===sequence&&!c.signal.aborted)error.value=true}
  finally{if(seq===sequence)loading.value=false}
}
watch(()=>[props.orderId,props.revision,props.disabled,props.refreshKey],()=>void refresh(),{immediate:true})
onBeforeUnmount(()=>{sequence++;controller?.abort()})
</script>
<template>
  <section class="rate-preview" :class="{embedded}" data-testid="allocation-rate-preview">
    <header><strong :title="data ? '整单单仓参考；不代表库存或可寄送。草稿不参与自动分仓排名。费率日期 ' + data.as_of : '整单单仓参考'">各仓最低运费参考</strong><small v-if="data?.measurement">{{data.measurement.item_count}}件 · {{data.measurement.weight_kg}} kg · {{data.measurement.dimensions_cm?.join(' × ')??'尺寸未知'}} {{data.measurement.dimensions_cm?'cm':''}}</small><button v-if="!embedded" type="button" :disabled="loading||disabled" @click="refresh()">刷新</button></header>
    <p v-if="disabled">实际发货商品尚未确认或已选择替换商品；为防止按原 SKU 错算，暂不展示整单运费。</p>
    <p v-else-if="loading" role="status">正在计算运费…</p>
    <p v-else-if="error" role="status">运费预估暂不可用，请刷新。不影响正常分仓。</p>
    <p v-else-if="data?.status==='unavailable'">{{reasons[data.reason??'']??'缺少可核验的运费数据'}}</p>
    <template v-else-if="data">
      <div class="rate-rows">
        <article v-for="row in orderedRows" :key="key(row)" :data-warehouse="row.warehouse_code" class="rate-row">
          <div><b>{{name(row)}}</b><small v-if="row.service">{{names[row.service]??row.service}} · V{{row.version_number}}</small></div>
          <div class="rate-money"><b v-if="row.native_totals">{{amount(row)}}</b><small v-if="row.native_totals">{{native(row)}}</small><small v-if="row.zone">Zone {{row.zone}}</small>
            <span v-if="row.status==='weight_only_reference'" class="draft">{{row.version_status==='draft'?'未审核 · ':''}}仅实重参考 · 未计体积重</span><span v-else-if="row.status==='draft_reference'" class="draft">未审核 · 基础运费参考</span><span v-else-if="row.status==='approved_reference'" class="reviewed">已审核费率 · 条件估算</span>
            <span v-else class="unavailable">{{reasons[row.reason??'']??'暂不可估算'}}</span>
            <small v-if="row.extra_item_usd!==null&&row.extra_item_usd>0&&row.native_totals">额外件操作参考 USD {{row.extra_item_usd.toFixed(2)}}（未计入）</small>
            <button v-if="row.reason==='eligibility_unconfirmed'" type="button" @click="refresh(key(row))">已核实此渠道适用，试算</button>
          </div>
          <details v-if="row.components?.length"><summary>计费明细</summary><div v-for="(c,i) in row.components" :key="i">{{c.component_type==='transport'?'运输费':c.component_type==='handling'?'操作费':'额外件操作费'}} {{c.currency}} {{Number(c.amount).toFixed(2)}}<span v-if="c.billable_weight!=null"> · {{Number(c.billable_weight).toFixed(3)}} {{c.weight_unit}}</span><small v-if="c.source_sheet">{{c.source_sheet}} · {{c.source_cells}}</small></div></details>
        </article>
      </div>
      <details v-if="!embedded" class="rate-note"><summary>估算说明</summary><small>{{data.measurement?.basis==='weight_only_reference'?'缺少尺寸，仅按实重试算，实际费用可能更高。':data.measurement?.basis==='product_standard_estimate'?'按商品标准数据估算，非实际物流包装尺寸；裸重已增加20%。':data.measurement?.basis==='stacked_packaging_estimate'?'按现有物流包装尺寸及重量堆叠为一个假设包裹，未计新增外箱重量，实际合包需复测。':'使用现有已审核的单件物流包装实测重量及尺寸，实际仓库包装可能不同。'}} 每仓仅展示同等费用范围内最低可计算的渠道参考；不是当前拆单合计，不代表有货或可寄送。草稿不含未知费用，不参与自动分仓排名。费率日期 {{data.as_of}}。</small></details>
    </template>
  </section>
</template>
<style scoped>
.rate-preview{margin:8px 18px;border:1px solid #dbe3ec;border-radius:7px;padding:8px 10px;font-size:12px;color:inherit}
.rate-preview header{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
.rate-preview header small{color:#64748b}.rate-preview button{border:1px solid #cbd5e1;background:transparent;color:inherit;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:11px}
.rate-preview button:disabled{opacity:.5;cursor:default}.rate-preview p{margin:4px 0;color:#64748b}
.rate-rows{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:6px}
.rate-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;padding:6px 8px;border:1px solid #e2e8f0;border-radius:5px}
.rate-row small{display:block;font-size:11px;color:#64748b;overflow-wrap:anywhere}.rate-money{text-align:right}.rate-money b{font-size:14px}
.draft{color:#92400e}.reviewed{color:#166534}.unavailable{color:#64748b}
.rate-row details{grid-column:1/-1;color:#64748b;font-size:11px}.rate-row summary{cursor:pointer}
.rate-note{display:block;margin-top:7px;color:#64748b;font-size:11px}
.rate-preview.embedded{margin:0;border:0;border-radius:0;padding:5px 0 0;border-top:1px solid #e2e8f0}
.embedded header{margin-bottom:4px;gap:6px}
.embedded .rate-rows{gap:4px;align-items:start}
.embedded .rate-row{padding:4px 6px;gap:2px 6px}
.embedded .rate-row details{line-height:1.2}
.embedded .rate-money{font-size:11px;line-height:1.35}
.embedded .rate-money b{font-size:14px}

</style>
