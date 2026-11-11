<script setup lang="ts">
import {computed,ref,watch} from 'vue'

type Carrier={value:string;label:string;default_service_value?:string|null;services:{value:string;label:string}[]}
type Source={carrier:string|null;service:string|null;review_status:string;shipment_status:string|null}
type Params={
  data:{id:number};column:{getColId:()=>string}
  carrierOptions:Carrier[];canOperate:boolean
  source:(id:number)=>Source|undefined;carrierBusy:(id:number)=>boolean
  saveCarrier:(id:number,carrier:string,service:string)=>void
}
const props=defineProps<{params:Params}>()
const id=computed(()=>Number(props.params.data.id))
const source=computed(()=>props.params.source(id.value))
const isCarrier=computed(()=>props.params.column.getColId()==='carrier')
const carrier=computed(()=>source.value?.carrier??'')
const service=computed(()=>source.value?.service??'')
const options=computed(()=>props.params.carrierOptions.find(x=>x.value===carrier.value)?.services??[])
const editable=computed(()=>!!props.params.canOperate&&source.value?.review_status==='approved'
  &&source.value?.shipment_status==='pending'&&!props.params.carrierBusy(id.value))
const localValue=ref('')
watch([isCarrier,carrier,service],()=>{localValue.value=isCarrier.value?carrier.value:service.value},{immediate:true})
const update=(event:Event)=>{
  const value=(event.target as HTMLSelectElement).value
  localValue.value=value
  if(!editable.value)return
  if(isCarrier.value){
    const selected=props.params.carrierOptions.find(x=>x.value===value)
    const first=selected?.services.find(x=>x.value===selected.default_service_value)??selected?.services[0]
    if(first&&value!==carrier.value)props.params.saveCarrier(id.value,value,first.value)
  }else if(carrier.value&&value!==service.value){
    props.params.saveCarrier(id.value,carrier.value,value)
  }
}
</script>
<template>
  <select v-if="editable" class="domestic-pending-route-select"
    :aria-label="isCarrier?'物流商':'服务渠道'"
    :value="localValue" @change.stop="update" @click.stop @mousedown.stop>
    <option v-if="!localValue" value="">请选择</option>
    <template v-if="isCarrier">
      <option v-for="c in params.carrierOptions" :key="c.value" :value="c.value">{{c.label}}</option>
    </template>
    <template v-else>
      <option v-for="s in options" :key="s.value" :value="s.value">{{s.label}}</option>
    </template>
  </select>
  <span v-else class="domestic-pending-route-readonly" :title="isCarrier?carrier:service">
    {{ isCarrier?(params.carrierOptions.find(c=>c.value===carrier)?.label??carrier):
      (options.find(s=>s.value===service)?.label??service) || '—' }}
  </span>
</template>
<style>
.domestic-pending-route-select{width:100%;max-width:100%;height:27px;border:1px solid #cbd5e1;border-radius:4px;background:white;color:#334155;font-size:12px;padding:2px 4px;cursor:pointer}
.domestic-pending-route-select:focus{outline:2px solid #99ddd0;outline-offset:0}
.domestic-pending-route-readonly{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;font-size:12px}
</style>
