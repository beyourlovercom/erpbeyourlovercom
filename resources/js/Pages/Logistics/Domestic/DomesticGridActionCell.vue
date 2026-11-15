<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'

type Row = { id:number; review_status:string; shipment_status?:string; tracking_sync_code?:string; tracking_sync_reason?:string }
type Params = {
  data: Row
  view: string
  canReview: boolean
  canOperate: boolean
  busy: boolean
  canTrackingSync?:boolean
  syncEligible?:(id:number)=>boolean
  syncBusy?:()=>boolean
  syncTracking?:(id:number)=>void
  native: (row:Row) => void
  approve: (row:Row) => void
  stockout: (row:Row) => void
  restock: (row:Row) => void
  returnAllocation: (row:Row) => void
  reopen: (row:Row) => void
  reject: (row:Row,reason:string) => void
}
const props = defineProps<{params:Params}>()
const row = computed(()=>props.params.data)
const pending = computed(()=>['待审核','已通过','缺货'].includes(row.value.review_status))
const review = computed(()=>props.params.canReview && props.params.view==='review' && pending.value)
const stockout = computed(()=>props.params.canReview && props.params.view==='stockout'
  && row.value.review_status==='缺货' && row.value.shipment_status==='pending')
const reopened = computed(()=>props.params.canReview && props.params.view==='pending'
  && row.value.review_status==='已通过')
const dailyProvider = computed(()=>['today','today-4px','today-weship'].includes(props.params.view))
const todayReopen = computed(()=>dailyProvider.value && props.params.canReview
  && row.value.review_status==='已通过' && row.value.shipment_status==='pending')
const todaySync = computed(()=>dailyProvider.value && props.params.canTrackingSync
  && !!props.params.syncEligible?.(row.value.id))
const todayRetry = computed(()=>['failed','retry_wait'].includes(row.value.tracking_sync_code??'')
  || !!row.value.tracking_sync_reason)

const rejectOpen = ref(false)
const reason = ref('')
const reasonInput = ref<HTMLInputElement|null>(null)
const rejectTrigger = ref<HTMLButtonElement|null>(null)
const popover = ref<HTMLElement|null>(null)
const overlay = ref({top:0,left:0})
const popoverWidth = 318
const popoverHeight = 111
const reposition = () => {
  if (!rejectOpen.value || !rejectTrigger.value) return
  const rect = rejectTrigger.value.getBoundingClientRect()
  if (rect.bottom<0 || rect.top>window.innerHeight) { closeReason(); return }
  overlay.value={
    left:Math.max(8,Math.min(rect.right-popoverWidth+16,window.innerWidth-popoverWidth-8)),
    top:rect.bottom+popoverHeight+8<=window.innerHeight
      ? rect.bottom+8
      : Math.max(8,rect.top-popoverHeight-8),
  }
}
const onOutside = (event:PointerEvent) => {
  if (!rejectOpen.value) return
  const target=event.target as Node
  if (popover.value?.contains(target) || rejectTrigger.value?.contains(target)) return
  closeReason()
}
const onKeydown = (event:KeyboardEvent) => {
  if (event.key==='Escape') {event.preventDefault();closeReason()}
}
const removeListeners = () => {
  document.removeEventListener('pointerdown',onOutside,true)
  document.removeEventListener('keydown',onKeydown,true)
  window.removeEventListener('resize',reposition)
  window.removeEventListener('scroll',reposition,true)
}
const revealReason = () => {
  if (props.params.busy || rejectOpen.value) return
  rejectOpen.value=true
  reposition()
  document.addEventListener('pointerdown',onOutside,true)
  document.addEventListener('keydown',onKeydown,true)
  window.addEventListener('resize',reposition)
  window.addEventListener('scroll',reposition,true)
  void nextTick(()=>reasonInput.value?.focus())
}
const closeReason = () => {
  rejectOpen.value=false
  reason.value=''
  removeListeners()
}
const submitReject = () => {
  const value=reason.value.trim()
  if (props.params.busy || value.length<3) return
  const target=row.value
  closeReason()
  props.params.reject(target,value)
}
onBeforeUnmount(removeListeners)
</script>
<template>
  <div class="domestic-grid-cell-actions">
    <div class="domestic-grid-cell-main">
      <template v-if="review">
        <button type="button" class="approve" :disabled="params.busy" @click.stop="params.approve(row)">通过</button>
        <button v-if="row.review_status!=='缺货'" type="button" class="stockout"
          :disabled="params.busy" @click.stop="params.stockout(row)">缺货</button>
        <button ref="rejectTrigger" type="button" class="reject" :disabled="params.busy"
          :aria-expanded="rejectOpen" @click.stop="rejectOpen ? closeReason() : revealReason()">驳回</button>
      </template>
      <template v-else-if="stockout">
        <button type="button" class="approve" :disabled="params.busy" @click.stop="params.restock(row)">已到货-可发货</button>
        <button type="button" :disabled="params.busy" @click.stop="params.returnAllocation(row)">进待分仓</button>
      </template>
      <button v-else-if="reopened" type="button" :disabled="params.busy" @click.stop="params.reopen(row)">重新审核</button>
      <template v-else-if="dailyProvider">
        <button v-if="todayReopen" type="button" :disabled="params.busy"
          @click.stop="params.reopen(row)">重新审核</button>
        <button v-if="todaySync" type="button" class="native"
          :disabled="params.syncBusy?.()" @click.stop="params.syncTracking?.(row.id)">{{ todayRetry?'重新同步':'同步官网物流' }}</button>
        <span v-if="!todayReopen && !todaySync">—</span>
      </template>
      <button v-else-if="params.view!=='stockout' && params.canOperate" type="button" class="native"
        @click.stop="params.native(row)">原操作</button>
      <span v-else>—</span>
    </div>
    <Teleport to="body">
      <div v-if="review && rejectOpen" ref="popover" class="domestic-grid-reject-popover"
        data-testid="domestic-reject-popover" role="group" aria-label="驳回订单"
        :style="{top:overlay.top+'px',left:overlay.left+'px'}" @pointerdown.stop>
        <div class="domestic-grid-reject-title">填写驳回原因</div>
        <input ref="reasonInput" v-model="reason" type="text" maxlength="500" aria-label="驳回原因"
          placeholder="驳回原因（至少3字）" @keydown.enter.stop.prevent="submitReject" />
        <div class="domestic-grid-reject-controls">
          <button type="button" :disabled="params.busy" @click.stop="closeReason">取消</button>
          <button type="button" class="confirm-reject" :disabled="params.busy || reason.trim().length<3"
            @click.stop="submitReject">确认驳回</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
<!-- AG Grid's dynamic renderer does not inherit Vue scoped attributes; all selectors are namespaced. -->
<style>
.domestic-grid-cell-actions{display:flex;align-items:center;min-height:100%;width:100%;box-sizing:border-box;padding:0 3px;white-space:nowrap}
.domestic-grid-cell-main{display:flex;align-items:center;gap:8px;min-width:0}
.domestic-grid-cell-actions button{height:25px;border:1px solid #cbd5e1;border-radius:4px;padding:0 9px;font-size:12px;background:#fff;cursor:pointer;flex:0 0 auto;line-height:23px}
.domestic-grid-cell-actions .approve{background:#008f7c;color:white;border-color:#008f7c}
.domestic-grid-cell-actions .stockout{color:#af5b09;border-color:#edb875}
.domestic-grid-cell-actions .reject{color:#d73038;border-color:#eab4b4}
.domestic-grid-cell-actions .native{color:#325475}
.domestic-grid-cell-actions button:disabled,.domestic-grid-reject-popover button:disabled{opacity:.45;cursor:not-allowed}
.domestic-grid-reject-popover{position:fixed;z-index:12050;width:318px;box-sizing:border-box;padding:10px 12px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 12px 32px rgba(15,23,42,.24);display:flex;flex-direction:column;gap:7px;color:#26364a;font-size:12px}
.domestic-grid-reject-title{font-weight:600}
.domestic-grid-reject-popover input{box-sizing:border-box;width:100%;height:29px;padding:4px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px}
.domestic-grid-reject-controls{display:flex;justify-content:flex-end;gap:8px}
.domestic-grid-reject-controls button{height:27px;padding:0 10px;border:1px solid #cbd5e1;border-radius:5px;background:white;color:#334155;cursor:pointer;font-size:12px}
.domestic-grid-reject-controls .confirm-reject{border-color:#e1a7a7;color:#b3242d}
</style>
