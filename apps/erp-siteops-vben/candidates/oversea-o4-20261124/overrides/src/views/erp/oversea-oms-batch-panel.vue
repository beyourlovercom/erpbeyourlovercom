<script setup lang="ts">
import {computed,ref,watch,onBeforeUnmount} from 'vue';
import {previewOmsBatch,startOmsBatch,readOmsBatch,type OmsBatchWarehouse,type OmsBatchPreview,type OmsBatchStatus,type OmsCapabilities} from '#/api/erp-logistics';
const props=defineProps<{warehouse:OmsBatchWarehouse;ids:number[];capabilities:OmsCapabilities;canOperate:boolean}>();
const preview=ref<OmsBatchPreview|null>(null),status=ref<OmsBatchStatus|null>(null),busy=ref(false),error=ref(''),started=ref(false);
let active=true;onBeforeUnmount(()=>{active=false;});
const canStart=computed(()=>props.canOperate&&!props.capabilities.paused&&(props.capabilities.simulation||props.capabilities.liveStart===true));
const selection=computed(()=>[...new Set(props.ids)].sort((a,b)=>a-b).join(','));
const latestId=computed(()=>status.value?.batch_id||props.capabilities.latestBatchId);
watch(selection,()=>{preview.value=null;});
async function check(){if(busy.value||!selection.value)return;const key=selection.value;busy.value=true;error.value='';preview.value=null;try{const p=await previewOmsBatch(props.warehouse,selection.value.split(',').map(Number),fetch,props.capabilities.previewDryRun??props.capabilities.simulation);if(active&&key===selection.value)preview.value=p;}catch(e){if(active)error.value=e instanceof Error?e.message:'预检失败';}finally{busy.value=false;}}
async function start(){const p=preview.value;if(!p||busy.value||started.value||!canStart.value||p.ids.join(',')!==selection.value)return;if(!window.confirm(p.dry_run?'确认创建 '+p.count+' 单 Dev 模拟批次？不会向真实仓库发单。':'确认向真实仓库提交 '+p.count+' 单？API 受理不是出库成功。'))return;busy.value=true;started.value=true;preview.value=null;error.value='';try{const r=await startOmsBatch(p);if(active)status.value=r;}catch(e){if(active)error.value=(e instanceof Error?e.message:'批次未确认')+'；请查询批次状态，不要重复提交。';}finally{busy.value=false;}}
async function refresh(){if(busy.value||!latestId.value)return;busy.value=true;error.value='';try{const r=await readOmsBatch(props.warehouse,latestId.value,fetch,status.value?.dry_run??props.capabilities.latestDryRun??props.capabilities.simulation);if(active){status.value=r;if(['completed','completed_partial','paused'].includes(r.status))started.value=false;}}catch(e){if(active)error.value=e instanceof Error?e.message:'状态查询失败';}finally{busy.value=false;}}
</script>
<template>
<div class="flex flex-wrap items-center gap-2 border-b bg-white px-3 py-2 text-xs">
<strong>{{warehouse==='de-tianyun'?'德国天运':'MorePlus'}} OMS · {{capabilities.simulation?'Dev 模拟':'正式模式'}}</strong>
<button type="button" :disabled="busy||!ids.length" class="rounded border px-2 py-1" @click="check">已选订单预检</button>
<button v-if="latestId" type="button" :disabled="busy" class="rounded border px-2 py-1" @click="refresh">查询批次状态</button>
<template v-if="preview"><span>已选 {{preview.scan_count}} · 可处理 {{preview.count}} · 排除 {{preview.excluded_count}}</span><button v-if="canStart" :disabled="busy||started||preview.overflow||!preview.count" type="button" class="rounded border border-indigo-500 px-2 py-1" @click="start">{{preview.dry_run?'确认模拟批次':'确认正式下发'}}</button><div v-if="preview.items.length" class="w-full max-h-48 overflow-auto"><table class="w-full"><thead><tr><th>订单</th><th>预检结果</th></tr></thead><tbody><tr v-for="r in preview.items" :key="r.order_id"><td>{{r.order_no}}</td><td>{{r.ready?'可处理':r.blockers.join('；')}}</td></tr></tbody></table></div></template>
<div v-if="status" class="w-full"><p>{{status.dry_run?'模拟批次':'OMS 批次'}} {{status.status}} · 已处理 {{status.processed}} / {{status.total}} · 不代表真实出库</p><div class="max-h-48 overflow-auto"><table class="w-full"><tbody><tr v-for="r in status.items" :key="r.order_id"><td>{{r.order_no}}</td><td>{{r.status}}</td><td>{{r.result_code}}</td></tr></tbody></table></div></div>
<p v-if="error" role="alert" class="w-full text-red-700">{{error}}</p>
</div>
</template>
