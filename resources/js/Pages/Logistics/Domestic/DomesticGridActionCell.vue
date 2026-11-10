<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'

type Row = { id:number; review_status:string }
type GridHeightNode = { rowHeight:number | null; setRowHeight:(height:number)=>void }
type Params = {
  data: Row
  view: string
  canReview: boolean
  canOperate: boolean
  busy: boolean
  node?: GridHeightNode
  api?: { onRowHeightChanged:()=>void }
  native: (row:Row) => void
  approve: (row:Row) => void
  stockout: (row:Row) => void
  restock: (row:Row) => void
  reopen: (row:Row) => void
  reject: (row:Row,reason:string) => void
}
const props = defineProps<{params:Params}>()
const row = computed(()=>props.params.data)
const pending = computed(()=>['待审核','已通过','缺货'].includes(row.value.review_status))
const review = computed(()=>props.params.canReview && props.params.view==='review' && pending.value)
const stockout = computed(()=>props.params.canReview && props.params.view==='stockout'
  && row.value.review_status==='缺货')
const reopened = computed(()=>props.params.canReview && props.params.view==='pending'
  && row.value.review_status==='已通过')

// Only the clicked order grows by one inline reason row.
// No modal, native-table expansion or navigation is created.
const rejectOpen = ref(false)
const reason = ref('')
const reasonInput = ref<HTMLInputElement|null>(null)
let originalHeight: number|null = null
const updateHeight = (expanded:boolean) => {
  const {node,api} = props.params
  if (!node || !api) return
  if (expanded && originalHeight===null) originalHeight=node.rowHeight ?? 32
  if (originalHeight===null) return
  node.setRowHeight(expanded ? originalHeight+36 : originalHeight)
  api.onRowHeightChanged()
}
const revealReason = () => {
  if (props.params.busy || rejectOpen.value) return
  rejectOpen.value=true
  updateHeight(true)
  void nextTick(()=>reasonInput.value?.focus())
}
const closeReason = () => {
  rejectOpen.value=false
  reason.value=''
  updateHeight(false)
}
const submitReject = () => {
  const value=reason.value.trim()
  if (props.params.busy || value.length<3) return
  const target=row.value
  closeReason()
  props.params.reject(target,value)
}
onBeforeUnmount(()=>{ if (rejectOpen.value) updateHeight(false) })
</script>
<template>
  <div class="domestic-grid-cell-actions" :class="{ 'reason-expanded': rejectOpen }">
    <div class="domestic-grid-cell-main">
      <template v-if="review">
        <button type="button" class="approve" :disabled="params.busy" @click.stop="params.approve(row)">通过</button>
        <button v-if="row.review_status!=='缺货'" type="button" class="stockout"
          :disabled="params.busy" @click.stop="params.stockout(row)">缺货</button>
        <button type="button" class="reject" :disabled="params.busy"
          :aria-expanded="rejectOpen" @click.stop="rejectOpen ? closeReason() : revealReason()">驳回</button>
      </template>
      <button v-else-if="stockout" type="button" class="approve" :disabled="params.busy"
        @click.stop="params.restock(row)">已到货-可发货</button>
      <button v-else-if="reopened" type="button" :disabled="params.busy" @click.stop="params.reopen(row)">重新审核</button>
      <button v-else-if="params.view!=='stockout' && params.canOperate" type="button" class="native"
        @click.stop="params.native(row)">原操作</button>
      <span v-else>—</span>
    </div>
    <div v-if="review && rejectOpen" class="domestic-grid-reject-inline" data-testid="domestic-inline-reject">
      <input ref="reasonInput" v-model="reason" type="text" maxlength="500" aria-label="驳回原因"
        placeholder="驳回原因（至少3字）" @click.stop @keydown.enter.stop.prevent="submitReject"
        @keydown.esc.stop.prevent="closeReason" />
      <button type="button" class="reject" :disabled="params.busy || reason.trim().length<3"
        @click.stop="submitReject">确认驳回</button>
      <button type="button" :disabled="params.busy" @click.stop="closeReason">取消</button>
    </div>
  </div>
</template>
<!-- AG Grid cellRenderer lacks Vue's scoped data-v attribute; use unique class selectors. -->
<style>
.domestic-grid-cell-actions{display:flex;flex-direction:column;justify-content:center;gap:5px;min-height:100%;width:100%;box-sizing:border-box;padding:0 6px;white-space:nowrap}
.domestic-grid-cell-main,.domestic-grid-reject-inline{display:flex;align-items:center;gap:8px;min-width:0}
.domestic-grid-cell-actions button{height:25px;border:1px solid #cbd5e1;border-radius:4px;padding:0 9px;font-size:12px;background:#fff;cursor:pointer;flex:0 0 auto;line-height:23px}
.domestic-grid-cell-actions .approve{background:#008f7c;color:white;border-color:#008f7c}
.domestic-grid-cell-actions .stockout{color:#af5b09;border-color:#edb875}
.domestic-grid-cell-actions .reject{color:#d73038;border-color:#eab4b4}
.domestic-grid-cell-actions .native{color:#325475}
.domestic-grid-cell-actions button:disabled{opacity:.45;cursor:not-allowed}
.domestic-grid-reject-inline input{width:145px;min-width:120px;max-width:145px;box-sizing:border-box;flex:0 1 145px;height:25px;padding:3px 8px;border:1px solid #d0d8e2;border-radius:4px;font-size:11px}
</style>
