<script setup lang="ts">
import {computed,nextTick,onBeforeUnmount,ref} from 'vue'
type Item={sku:string;quantity:number;barcode_69:string}
type Row={id:number;sku:string;country?:string}
type Params={
  data:Row
  showBarcode:(id:number)=>void
  preview:(id:number)=>Item[]|null
  error:(id:number)=>string
  loading:()=>boolean
}
const props=defineProps<{params:Params}>()
const eu=new Set(['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','GB','UK','NO','CH','IS','LI','AD','MC','SM','VA','AL','BA','ME','MK','RS','MD','UA','TR'])
const eligible=computed(()=>eu.has((props.params.data.country??'').toUpperCase()))
const id=computed(()=>Number(props.params.data.id))
const rows=computed(()=>props.params.preview(id.value))
const problem=computed(()=>props.params.error(id.value))
const open=ref(false)
const trigger=ref<HTMLButtonElement|null>(null)
const floating=ref<HTMLElement|null>(null)
const xy=ref({top:0,left:0})
const width=300
const reposition=()=>{
  if(!open.value||!trigger.value)return
  const r=trigger.value.getBoundingClientRect()
  xy.value={
    left:Math.max(8,Math.min(r.right-width,innerWidth-width-8)),
    top:r.bottom+160<innerHeight?r.bottom+6:Math.max(8,r.top-160),
  }
}
const onOutside=(ev:PointerEvent)=>{
  const t=ev.target as Node
  if(!floating.value?.contains(t)&&!trigger.value?.contains(t))close()
}
const onKey=(ev:KeyboardEvent)=>{if(ev.key==='Escape')close()}
const remove=()=>{
  document.removeEventListener('pointerdown',onOutside,true)
  document.removeEventListener('keydown',onKey,true)
  window.removeEventListener('scroll',reposition,true)
}
const close=()=>{open.value=false;remove()}
const show=()=>{
  if(!eligible.value||props.params.loading())return
  open.value=true
  reposition()
  document.addEventListener('pointerdown',onOutside,true)
  document.addEventListener('keydown',onKey,true)
  window.addEventListener('scroll',reposition,true)
  props.params.showBarcode(id.value)
  void nextTick(reposition)
}
onBeforeUnmount(remove)
</script>
<template>
  <div class="domestic-pending-sku">
    <span :title="params.data.sku">{{ params.data.sku }}</span>
    <button v-if="eligible" ref="trigger" type="button" :disabled="params.loading()" @click.stop="open?close():show()">69条码</button>
    <Teleport to="body">
      <div v-if="open" ref="floating" class="domestic-pending-barcode-popover"
        data-testid="domestic-pending-barcode" role="group" aria-label="69条码"
        :style="{left:xy.left+'px',top:xy.top+'px'}" @pointerdown.stop>
        <strong>69条码</strong>
        <span v-if="params.loading()">读取中…</span>
        <span v-else-if="problem" class="error">{{problem}}</span>
        <span v-else-if="!rows?.length">暂无条码信息</span>
        <div v-for="item in rows" :key="item.sku">{{item.sku}} × {{item.quantity}}：{{item.barcode_69}}</div>
        <button type="button" @click.stop="close">关闭</button>
      </div>
    </Teleport>
  </div>
</template>
<style>
.domestic-pending-sku{height:100%;display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden}
.domestic-pending-sku>span{overflow:hidden;text-overflow:ellipsis}
.domestic-pending-sku button{flex:0 0 auto;border:1px solid #cbd5e1;border-radius:4px;background:#fff;color:#0f766e;height:24px;font-size:11px;padding:0 5px;cursor:pointer}
.domestic-pending-barcode-popover{position:fixed;z-index:12058;width:300px;max-width:calc(100vw - 16px);box-sizing:border-box;padding:12px;background:white;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 12px 32px rgba(15,23,42,.24);display:flex;flex-direction:column;gap:9px;font-size:12px;color:#334155}
.domestic-pending-barcode-popover button{align-self:flex-end;padding:4px 10px;background:white;border:1px solid #cbd5e1;border-radius:4px}
.domestic-pending-barcode-popover .error{color:#b91c1c}
</style>
