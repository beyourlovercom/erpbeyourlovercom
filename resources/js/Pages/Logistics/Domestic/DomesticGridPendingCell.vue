<script setup lang="ts">
import {computed,nextTick,onBeforeUnmount,ref} from 'vue'

type Row={id:number;order_number:string;review_status:string;shipment_status?:string}
type Carrier={value:string;label:string;services:{value:string;label:string;name_zh?:string|null}[]}
type Source={order_id:number;review_status:string;shipment_status:string|null;carrier:string|null;service:string|null;carrier_selection_source?:string|null;carrier_ready?:boolean;tracking_number:string|null}
type Draft={carrier:string;service:string;tracking:string}
type Params={
  data:Row;canReview:boolean;canOperate:boolean;carrierOptions:Carrier[]
  compactActions?:boolean
  source:(id:number)=>Source|undefined
  carrierBusy:(id:number)=>boolean
  trackingBusy:(id:number)=>boolean
  feedback:(id:number)=>string
  saveCarrier:(id:number,carrier:string,service:string)=>void
  saveTracking:(id:number,tracking:string)=>void
  confirmShip:(id:number,tracking:string,outboundReference?:string)=>void
  requiresOutboundReference?:(id:number)=>boolean
  reopenPending:(id:number)=>void
  canTrackingSync:boolean
  syncEligible:(id:number)=>boolean
  syncBusy:()=>boolean
  syncTracking:(id:number)=>void
}
const props=defineProps<{params:Params}>()
const id=computed(()=>Number(props.params.data.id))
const source=computed(()=>props.params.source(id.value))
const canShip=computed(()=>!!props.params.canOperate && source.value?.review_status==='approved' && source.value.shipment_status==='pending')
const canReopen=computed(()=>!!props.params.canReview && source.value?.review_status==='approved' && source.value.shipment_status==='pending')
const carrierConfirmed=computed(()=>source.value?.carrier_ready===true)
const busy=computed(()=>props.params.carrierBusy(id.value)||props.params.trackingBusy(id.value))
const compactOpen=ref(false)
const compactEl=ref<HTMLElement|null>(null)
const compactXY=ref({top:0,left:0})
const hasActions=computed(()=>canShip.value||canReopen.value||(props.params.canTrackingSync&&props.params.syncEligible(id.value)))
const open=ref(false)
const trigger=ref<HTMLButtonElement|null>(null)
const floating=ref<HTMLElement|null>(null)
const carrierEl=ref<HTMLSelectElement|null>(null)
const draft=ref<Draft>({carrier:'',service:'',tracking:''})
const outbound=ref('')
const needsOutbound=computed(()=>props.params.requiresOutboundReference?.(id.value)===true)
const xy=ref({top:0,left:0})
const width=354
const services=computed(()=>props.params.carrierOptions.find(c=>c.value===draft.value.carrier)?.services??[])
const serviceLabel=(s:{value:string;label:string;name_zh?:string|null})=>draft.value.carrier==='4PX'?(s.name_zh?.trim()||s.value):s.label
const carrierDirty=computed(()=>!!source.value && (draft.value.carrier!==(source.value.carrier??'') || draft.value.service!==(source.value.service??'')))
const trackDirty=computed(()=>draft.value.tracking.trim()!==(source.value?.tracking_number??''))
const canSubmitShip=computed(()=>canShip.value&&carrierConfirmed.value&&!carrierDirty.value&&!trackDirty.value&&!!draft.value.tracking.trim()&&!busy.value&&(!needsOutbound.value||outbound.value.trim().length>=3))
const reposition=()=>{
  if(!open.value||!trigger.value)return
  const b=trigger.value.getBoundingClientRect()
  if(b.bottom<0||b.top>window.innerHeight){close();return}
  const estimatedHeight=267
  xy.value={
    left:Math.max(8,Math.min(b.right-width,window.innerWidth-width-8)),
    top:b.bottom+estimatedHeight+8<=window.innerHeight?b.bottom+8:Math.max(8,b.top-estimatedHeight-8),
  }
}
const outside=(ev:PointerEvent)=>{
  if(!open.value&&!compactOpen.value)return
  const t=ev.target as Node
  if(floating.value?.contains(t)||compactEl.value?.contains(t)||trigger.value?.contains(t))return
  close()
}
const esc=(ev:KeyboardEvent)=>{if(ev.key==='Escape'){ev.preventDefault();close()}}
const remove=()=>{
  document.removeEventListener('pointerdown',outside,true)
  document.removeEventListener('keydown',esc,true)
  window.removeEventListener('resize',reposition)
  window.removeEventListener('scroll',reposition,true)
  window.removeEventListener('resize',close)
  window.removeEventListener('scroll',close,true)
}
const toggleCompact=()=>{
  if(compactOpen.value){close();return}
  if(!trigger.value||!hasActions.value)return
  const rect=trigger.value.getBoundingClientRect()
  const height=(Number(canShip.value)*2+Number(canReopen.value)+Number(props.params.canTrackingSync&&props.params.syncEligible(id.value)))*32+10
  compactXY.value={
    left:Math.max(6,Math.min(rect.left,window.innerWidth-154)),
    top:rect.bottom+height+6<=window.innerHeight?rect.bottom+4:Math.max(6,rect.top-height-4),
  }
  compactOpen.value=true
  document.addEventListener('pointerdown',outside,true)
  document.addEventListener('keydown',esc,true)
  window.addEventListener('resize',close)
  window.addEventListener('scroll',close,true)
}
const reveal=()=>{
  if(!canShip.value||busy.value||open.value)return
  draft.value={
    carrier:source.value?.carrier??'',
    service:source.value?.service??'',
    tracking:source.value?.tracking_number??'',
  }
  // Keep main's compact-menu listener cleanup while opening the editor.
  if(compactOpen.value){
    compactOpen.value=false
    window.removeEventListener('resize',close)
    window.removeEventListener('scroll',close,true)
  }
  outbound.value=''
  open.value=true
  reposition()
  document.addEventListener('pointerdown',outside,true)
  document.addEventListener('keydown',esc,true)
  window.addEventListener('resize',reposition)
  window.addEventListener('scroll',reposition,true)
  void nextTick(()=>carrierEl.value?.focus())
}
const close=()=>{open.value=false;compactOpen.value=false;remove()}
const confirmCarrier=()=>{
  if(!canShip.value||busy.value||!draft.value.carrier||!draft.value.service||!carrierDirty.value)return
  props.params.saveCarrier(id.value,draft.value.carrier,draft.value.service)
}
const saveTracking=()=>{
  if(!canShip.value||busy.value||!trackDirty.value)return
  props.params.saveTracking(id.value,draft.value.tracking.trim())
}
const confirm=()=>{
  if(!canSubmitShip.value)return
  props.params.confirmShip(id.value,draft.value.tracking.trim(),outbound.value.trim())
  close()
}
onBeforeUnmount(remove)
</script>

<template>
  <div class="domestic-pending-actions">
    <template v-if="params.compactActions">
      <button v-if="hasActions" ref="trigger" type="button" class="pending-compact-trigger"
        aria-label="操作" :aria-expanded="compactOpen" @click.stop="toggleCompact">操作 ▾</button>
      <span v-else class="pending-compact-empty">—</span>
      <Teleport to="body">
        <div v-if="compactOpen" ref="compactEl" class="domestic-pending-compact-menu" role="group"
          aria-label="订单操作" :style="{top:compactXY.top+'px',left:compactXY.left+'px'}" @pointerdown.stop>
          <button v-if="canShip" type="button" :disabled="busy" @click.stop="reveal">填写物流</button>
          <button v-if="canShip" type="button" :disabled="needsOutbound||!carrierConfirmed||!(source?.tracking_number??'').trim()||busy"
            @click.stop="close();params.confirmShip(id,(source?.tracking_number??'').trim())">确认发货</button>
          <button v-if="canReopen" type="button" :disabled="busy"
            @click.stop="close();params.reopenPending(id)">重新审核</button>
          <button v-if="params.canTrackingSync&&params.syncEligible(id)" type="button" :disabled="params.syncBusy()"
            @click.stop="close();params.syncTracking(id)">同步官网物流</button>
        </div>
      </Teleport>
    </template>
    <template v-else>
    <template v-if="canShip">
      <button ref="trigger" type="button" class="pending-edit" :disabled="busy" :aria-expanded="open"
        @click.stop="open?close():reveal()">填写物流</button>
      <button type="button" class="pending-confirm" :disabled="needsOutbound||!carrierConfirmed||!(source?.tracking_number??'').trim()||busy"
        @click.stop="params.confirmShip(id,(source?.tracking_number??'').trim())">确认发货</button>
    </template>
    <button v-if="canReopen" type="button" class="pending-reopen" :disabled="busy"
      @click.stop="params.reopenPending(id)">重新审核</button>
    <button v-if="params.canTrackingSync&&params.syncEligible(id)" type="button"
      :disabled="params.syncBusy()" @click.stop="params.syncTracking(id)">同步官网物流</button>
    <span v-if="!canShip&&!canReopen&&!(params.canTrackingSync&&params.syncEligible(id))">—</span>
    </template>
    <Teleport to="body">
      <div v-if="open&&canShip" ref="floating" class="domestic-pending-popover"
        data-testid="domestic-pending-popover" role="group" :aria-label="'填写物流 '+params.data.order_number"
        :style="{left:xy.left+'px',top:xy.top+'px'}" @pointerdown.stop>
        <strong>{{params.data.order_number}} · 填写物流</strong>
        <label>物流商
          <select ref="carrierEl" v-model="draft.carrier" aria-label="物流商"
            :disabled="busy" @change="draft.service=''">
            <option value="">请选择物流商</option>
            <option v-for="c in params.carrierOptions" :key="c.value" :value="c.value">{{c.label}}</option>
          </select>
        </label>
        <label>服务类型
          <select v-model="draft.service" aria-label="服务类型" :disabled="busy||!draft.carrier">
            <option value="">请选择服务类型</option>
            <option v-for="s in services" :key="s.value" :value="s.value">{{serviceLabel(s)}}</option>
          </select>
        </label>
        <button type="button" :disabled="busy||!carrierDirty||!draft.carrier||!draft.service"
          @click.stop="confirmCarrier">确认物流商及服务</button>
        <label>Tracking
          <input v-model="draft.tracking" aria-label="Tracking" maxlength="128" placeholder="填写真实物流单号"
            :disabled="busy" />
        </label>
        <label v-if="needsOutbound">真实出库单号
          <input v-model="outbound" aria-label="真实出库单号" maxlength="90" placeholder="MorePlus 仓库真实出库参考号" :disabled="busy" />
        </label>
        <div class="domestic-pending-popover-buttons">
          <button type="button" :disabled="busy||!trackDirty" @click.stop="saveTracking">暂存单号</button>
          <button type="button" class="pending-confirm" :disabled="!canSubmitShip" @click.stop="confirm">确认发货</button>
          <button type="button" @click.stop="close">取消</button>
        </div>
        <small v-if="params.feedback(id)" role="status">{{params.feedback(id)}}</small>
        <small v-else>{{needsOutbound?'MorePlus 需核实 OMS 原单及真实出库单号；暂存不发货。':'物流商及单号均需先确认保存；暂存不会发货。'}}</small>
      </div>
    </Teleport>
  </div>
</template>

<!-- AG Grid's dynamic Vue renderers do not preserve scoped style attributes. -->
<style>
.domestic-pending-actions{display:flex;align-items:center;gap:7px;min-width:0;width:100%;height:100%;white-space:nowrap}
.domestic-pending-actions button{height:25px;min-height:25px;flex:0 0 auto;border:1px solid #cbd5e1;border-radius:5px;background:white;color:#334155;padding:0 7px;font-size:12px;cursor:pointer}
.domestic-pending-actions .pending-compact-trigger{color:#176b51;padding:0 8px}
.domestic-pending-actions .pending-compact-empty{color:#94a3b8;padding:0 8px}
.domestic-pending-compact-menu{position:fixed;z-index:12065;width:148px;max-width:calc(100vw - 10px);
 display:flex;flex-direction:column;box-sizing:border-box;background:white;border:1px solid #cbd5e1;
 border-radius:6px;padding:4px;box-shadow:0 12px 24px rgba(15,23,42,.2)}
.domestic-pending-compact-menu button{min-height:31px;border:0;background:transparent;color:#176b51;
 border-radius:4px;text-align:left;font-size:12px;padding:5px 9px;cursor:pointer}
.domestic-pending-compact-menu button:disabled{opacity:.45;cursor:not-allowed}
.domestic-pending-compact-menu button:hover:not(:disabled){background:#f1f6f4}
.domestic-pending-actions .pending-edit{color:#0f766e;border-color:#8ccdc2}
.domestic-pending-actions .pending-confirm{color:white;background:#008f7c;border-color:#008f7c}
.domestic-pending-actions .pending-reopen{color:#a16207;border-color:#f0c783}
.domestic-pending-actions button:disabled,.domestic-pending-popover button:disabled{opacity:.45;cursor:not-allowed}
.domestic-pending-popover{position:fixed;z-index:12055;width:354px;max-width:calc(100vw - 16px);box-sizing:border-box;display:flex;flex-direction:column;gap:7px;padding:12px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 12px 32px rgba(15,23,42,.24);font-size:12px;color:#334155}
.domestic-pending-popover label{display:flex;align-items:center;justify-content:space-between;gap:8px}
.domestic-pending-popover input,.domestic-pending-popover select{width:248px;max-width:75%;height:28px;padding:2px 6px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:4px;background:white;color:#334155}
.domestic-pending-popover button{min-height:26px;padding:2px 8px;border-radius:4px;border:1px solid #b8c6cc;background:white;color:#334155;cursor:pointer;font-size:12px}
.domestic-pending-popover .pending-confirm{background:#008f7c;color:white;border-color:#008f7c}
.domestic-pending-popover-buttons{display:flex;align-items:center;gap:7px}
.domestic-pending-popover small{color:#64748b}
</style>
