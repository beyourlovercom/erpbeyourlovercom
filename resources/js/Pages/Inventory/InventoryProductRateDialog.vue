<script setup lang="ts">
import {computed,ref} from 'vue'
import axios from 'axios'
type Scope={version_id:number;warehouse_code:string;carrier:string;service:string;destination_country:string;effective_from:string|null;effective_until:string|null}
type Row=Record<string,unknown>
const props=defineProps<{row:Row;scopes:Scope[]}>()
const emit=defineEmits<{close:[];result:[Row]}>()
const selected=ref('')
const postal=ref('')
const date=ref(new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()))
const confirmed=ref(false)
const busy=ref(false)
const error=ref('')
const quote=ref<Row|null>(null)
const scope=computed(()=>selected.value===''?null:props.scopes[Number(selected.value)])
const components=computed(()=>Array.isArray(quote.value?.components)?quote.value.components as Row[]:[])
const reasonLabels:Record<string,string>={measurement_missing_or_changed:'包装测量已变更，请刷新页面',measurement_incomplete:'物流实重或尺寸不完整',rate_center_not_available:'报价中心尚未上线',rate_version_not_approved:'报价版本未批准',rate_version_outside_effective_dates:'不在报价有效期内',supplier_catalog_restriction:'原报价明确限制货物类型，渠道不适用',rate_scope_missing:'缺少对应渠道报价',rate_coverage_unverified:'费率覆盖尚未核验',rate_rule_unreviewed:'规则尚未审核',eligibility_unconfirmed:'未确认渠道适用性',postal_zone_missing_or_ambiguous:'邮编分区未核实或冲突',postal_code_invalid:'邮编不正确或缺失',package_dimensions_outside_rule:'物流尺寸不符合渠道限制',package_weight_outside_rule:'重量超出渠道限制',weight_band_missing_or_ambiguous:'重量档缺失或冲突',fx_unavailable:'缺少有效汇率'}
function reset(){confirmed.value=false;quote.value=null;error.value=''}
async function calculate(){
 if(!scope.value||!confirmed.value||busy.value)return
 const s=scope.value;busy.value=true;error.value='';quote.value=null
 try{
  const {data}=await axios.post('/inventory/products/'+props.row.id+'/rate-preview',{measurement_record_id:props.row.shipping_measurement_id,version_id:s.version_id,warehouse_code:s.warehouse_code,carrier:s.carrier,service:s.service,country:s.destination_country,postal_code:postal.value,as_of:date.value,eligibility_confirmed:confirmed.value})
  quote.value=data;emit('result',data)
 }catch(e){error.value=axios.isAxiosError(e)&&e.response?.status===403?'无物流报价查看权限':'试算失败，请检查输入或刷新重试。'}finally{busy.value=false}
}
</script>
<template>
 <Teleport to="body">
  <div class="fixed inset-0 z-[1000] bg-black/40 flex items-center justify-center p-4" @keydown.esc="!busy&&emit('close')">
   <section role="dialog" aria-modal="true" aria-label="物流费用试算" class="bg-white rounded-lg p-5 w-full max-w-3xl max-h-[90vh] overflow-auto shadow-lg space-y-3">
    <div class="flex justify-between items-center"><strong>物流费用试算 · {{row.sku}}</strong><button type="button" class="mvp-button" :disabled="busy" @click="emit('close')">关闭</button></div>
    <p class="text-sm">已采纳物流包装：{{row.shipping_dimensions}} cm / {{row.shipping_weight_kg}} kg · 单件</p>
    <p v-if="!scopes.length" class="text-sm text-amber-700">暂无已批准并核验的报价渠道。请在报价中心完成费率审核，不能使用草稿作为正式费用。</p>
    <form v-else class="space-y-3" @submit.prevent="calculate">
     <label class="block text-sm">报价渠道 / 版本<select v-model="selected" :disabled="busy" @change="reset" required class="block w-full border rounded p-2" aria-label="报价渠道"><option value="">选择已批准报价</option><option v-for="(s,i) in scopes" :key="i" :value="String(i)">{{s.warehouse_code}} / {{s.carrier}} / {{s.service}} / {{s.destination_country}} / #{{s.version_id}}（{{s.effective_from??'—'}} 至 {{s.effective_until??'长期'}}）</option></select></label>
     <div class="flex gap-3"><label class="flex-1 text-sm">目的地邮编<input v-model="postal" :disabled="busy" @input="reset" class="block w-full border rounded p-2" maxlength="32" aria-label="目的地邮编"/></label><label class="flex-1 text-sm">计费日期<input v-model="date" :disabled="busy" @change="reset" type="date" required class="block w-full border rounded p-2" aria-label="计费日期"/></label></div>
     <label class="flex items-start gap-2 text-sm"><input v-model="confirmed" type="checkbox" :disabled="busy" />我已核对该 SKU、仓库、国家、邮编及渠道的适用性。本次仅作单件费用试算，不代表发货审批。</label>
     <button class="mvp-button" type="submit" :disabled="!scope||!confirmed||busy">{{busy?'计算中…':'计算物流费用'}}</button>
    </form>
    <p v-if="error" role="alert" class="text-sm text-red-700">{{error}}</p>
    <div v-if="quote" class="border-t pt-3 text-sm space-y-2" data-testid="inventory-rate-result">
     <p v-if="quote.status!=='ready'" class="text-amber-700">{{reasonLabels[String(quote.reason)]??('未能提供完整报价：'+String(quote.reason??quote.status))}}</p>
     <p v-if="quote.status==='ready'" class="font-semibold">预计总费用 ¥{{quote.total_cny}}（人民币，非实际账单）</p>
     <p v-if="quote.native_totals">原币合计：{{quote.native_totals}}</p>
     <div class="overflow-x-auto" v-if="components.length"><table class="w-full text-left whitespace-nowrap text-xs"><thead><tr><th>费用</th><th>实重</th><th>体积重</th><th>计费重</th><th>单位</th><th>金额</th><th>原表位置</th></tr></thead><tbody><tr v-for="(c,i) in components" :key="i"><td>{{c.component_type==='transport'?'运输费':c.component_type==='handling'?'操作费':c.component_type}}</td><td>{{c.actual_weight??'—'}}</td><td>{{c.dimensional_weight??'—'}}</td><td>{{c.billable_weight??'—'}}</td><td>{{c.weight_unit??'—'}}</td><td>{{c.amount}} {{c.currency}}</td><td>{{c.source_sheet}} {{c.source_cells}}</td></tr></tbody></table></div>
    </div>
    <p class="text-xs text-slate-500">只读取已采纳的物流尺寸和实重；不使用彩盒尺寸或历史参考重量。试算不保存、不修改订单、库存、费率或包装标准。</p>
   </section>
  </div>
 </Teleport>
</template>
