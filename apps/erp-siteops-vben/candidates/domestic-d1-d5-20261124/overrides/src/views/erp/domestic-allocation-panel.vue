<script setup lang="ts">
import {computed,ref} from 'vue';
import {submitOriginalAllocation,type AllocationAssignment,type AllocationPlan,type AllocationWarehouse} from '#/api/erp-logistics';
const props=defineProps<{orderId:number;plan:AllocationPlan;warehouses:AllocationWarehouse[]}>();
const emit=defineEmits<{completed:[]}>();
const choices=ref<Record<number,string>>({}),gifts=ref<Record<number,number>>({}),note=ref(''),ack=ref(false),busy=ref(false),error=ref('');
function choose(id:number){delete gifts.value[id];}
const assignments=computed<AllocationAssignment[]>(()=>props.plan.items.map(i=>{
 const source=choices.value[i.id]||'',route=props.plan.routes[source];
 return {order_item_id:i.id,source_code:source,gift_mapping_id:i.is_gift?gifts.value[i.id]||null:null,carrier:source==='FACTORY'||source==='OUTSOURCE'?null:route?.carrier||null,service:source==='FACTORY'||source==='OUTSOURCE'?null:route?.service||null};
}));
const valid=computed(()=>props.plan.items.length>0&&assignments.value.every(a=>a.source_code&&(!props.plan.items.find(i=>i.id===a.order_item_id)?.is_gift||a.gift_mapping_id)));
async function submit(mode:'manual'|'recommended'){
 if(busy.value||mode==='manual'&&!valid.value)return;
 if(!window.confirm(mode==='manual'?'确认提交人工分仓？':'确认采用原 ERP 推荐方案？'))return;
 busy.value=true;error.value='';
 try{await submitOriginalAllocation({orderId:props.orderId,plan:props.plan,warehouses:props.warehouses,mode,assignments:assignments.value,note:note.value,acknowledged:ack.value});emit('completed')}
 catch(e){error.value=e instanceof Error?e.message:'分仓未确认';}finally{busy.value=false}
}
</script>
<template>
<section class="mt-6 space-y-3 rounded border border-indigo-200 p-3" data-testid="p0-allocation-panel">
<h3 class="text-sm font-semibold">原 ERP 人工分仓 / 推荐方案</h3>
<p v-if="plan.usable" class="text-xs">推荐仓：{{plan.warehouses.join(' / ')}}</p>
<button v-if="plan.usable && plan.key" type="button" :disabled="busy" class="rounded border px-3 py-2 text-xs" @click="submit('recommended')">采用系统推荐</button>
<div v-for="item in plan.items" :key="item.id" class="space-y-2 rounded border p-2 text-xs">
<strong>{{item.sku}} × {{item.quantity}}</strong>
<label class="block">发货仓<select v-model="choices[item.id]" :aria-label="'分仓 '+item.sku" :disabled="busy" class="w-full rounded border p-2" @change="choose(item.id)"><option value="">选择仓库</option><option v-for="w in warehouses" :key="w.code" :value="w.code">{{w.name}}</option></select></label>
<label v-if="item.is_gift" class="block">礼品真实 SKU<select v-model.number="gifts[item.id]" :aria-label="'礼品 '+item.sku" :disabled="busy" class="w-full rounded border p-2"><option :value="0">选择真实 SKU</option><option v-for="g in item.gifts[choices[item.id]||'']||[]" :key="g.mapping_id" :value="g.mapping_id">{{g.fulfillment_sku}} · 可用 {{g.quantity_available??'未知'}}</option></select></label>
</div>
<label class="block text-xs">分仓备注<textarea v-model="note" maxlength="1000" :disabled="busy" class="w-full rounded border p-2"/></label>
<label class="block text-xs"><input v-model="ack" type="checkbox" :disabled="busy"/> 已人工确认库存例外（仅原 ERP 需要时使用）</label>
<button type="button" data-testid="p0-manual-allocate" :disabled="!valid||busy" class="rounded bg-indigo-600 px-3 py-2 text-xs text-white disabled:opacity-50" @click="submit('manual')">确认人工分仓</button>
<p v-if="error" role="alert" class="text-xs text-red-700">{{error}}</p>
</section>
</template>