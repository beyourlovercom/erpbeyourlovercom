<script setup lang="ts">
import {ref} from 'vue';
import {previewOriginalOverseaReturn,applyOriginalOverseaReturn,type OverseaTrackingConfirmation} from '#/api/erp-logistics';
const props=defineProps<{warehouse:string}>();
const emit=defineEmits<{completed:[] }>();
const file=ref<File|null>(null),preview=ref<OverseaTrackingConfirmation|null>(null),busy=ref(false),error=ref(''),result=ref('');
function choose(e:Event){file.value=(e.target as HTMLInputElement).files?.[0]??null;preview.value=null;error.value='';result.value='';}
async function runPreview(){if(!file.value||busy.value)return;busy.value=true;error.value='';preview.value=null;try{preview.value=await previewOriginalOverseaReturn(props.warehouse,file.value);}catch(e){error.value=e instanceof Error?e.message:'预览失败';}finally{busy.value=false;}}
async function apply(){const p=preview.value;if(!p||busy.value||!p.apply_count)return;if(!window.confirm('确认登记 '+p.apply_count+' 单海外仓发货？'))return;busy.value=true;error.value='';preview.value=null;try{const r=await applyOriginalOverseaReturn(p);result.value=r.message;emit('completed');}catch(e){error.value=(e instanceof Error?e.message:'登记未确认')+'；请刷新核对，不要重复提交。';}finally{busy.value=false;}}
</script>
<template>
<div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
<label for="erp2-oversea-return-file" class="text-xs font-semibold">海外仓回传表：</label>
<input id="erp2-oversea-return-file" type="file" accept=".xlsx,.csv,.txt" :disabled="busy" class="max-w-[245px] text-xs" @change="choose"/>
<button type="button" :disabled="!file||busy" class="rounded border px-3 py-1.5 text-xs" @click="runPreview">上传并预览</button>
<template v-if="preview">
<span class="text-xs">可登记 {{preview.apply_count}} · 重复 {{preview.noop_count}} · 阻断 {{preview.blocked_count}}</span>
<div class="w-full max-h-56 overflow-auto"><table class="w-full text-xs"><thead><tr><th>订单号</th><th>Tracking</th><th>状态</th><th>说明</th></tr></thead><tbody><tr v-for="(r,i) in preview.rows" :key="i"><td>{{r.order_number}}</td><td>{{r.tracking_number}}</td><td>{{r.status}}</td><td>{{r.message}}</td></tr></tbody></table></div>
<button type="button" :disabled="busy||!preview.apply_count" class="rounded border border-indigo-500 px-3 py-1.5 text-xs" @click="apply">确认批量登记</button>
</template>
<p v-if="result" role="status" class="w-full text-xs">{{result}}</p><p v-if="error" role="alert" class="w-full text-xs text-red-700">{{error}}</p>
</div>
</template>
