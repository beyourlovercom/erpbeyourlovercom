<script setup lang="ts">
import { computed, ref } from 'vue'
type Row = { id:number; review_status:string }
type Params = {
  data: Row
  view: string
  canReview: boolean
  canOperate: boolean
  busy: boolean
  native: (row:Row) => void
  approve: (row:Row) => void
  stockout: (row:Row) => void
  restock: (row:Row) => void
  reopen: (row:Row) => void
  reject: (row:Row,reason:string) => void
}
const props = defineProps<{params:Params}>()
const reason = ref('')
const row = computed(()=>props.params.data)
const pending = computed(()=>['待审核','已通过','缺货'].includes(row.value.review_status))
const review = computed(()=>props.params.canReview && props.params.view==='review' && pending.value)
const stockout = computed(()=>props.params.canReview && props.params.view==='stockout'
  && row.value.review_status==='缺货')
const reopened = computed(()=>props.params.canReview && props.params.view==='pending'
  && row.value.review_status==='已通过')
</script>
<template>
  <div class="domestic-grid-cell-actions">
    <template v-if="review">
      <button type="button" class="approve" :disabled="params.busy" @click.stop="params.approve(row)">通过</button>
      <button v-if="row.review_status!=='缺货'" type="button" class="stockout"
        :disabled="params.busy" @click.stop="params.stockout(row)">缺货</button>
      <button type="button" class="reject" :disabled="params.busy || reason.trim().length<3"
        @click.stop="params.reject(row,reason.trim())">驳回</button>
      <input v-model="reason" type="text" maxlength="500" aria-label="驳回原因"
        placeholder="驳回原因（至少3字）" @click.stop />
    </template>
    <button v-else-if="stockout" type="button" class="approve" :disabled="params.busy"
      @click.stop="params.restock(row)">已到货-可发货</button>
    <button v-else-if="reopened" type="button" :disabled="params.busy" @click.stop="params.reopen(row)">重新审核</button>
    <button v-else-if="params.view!=='stockout' && params.canOperate" type="button" class="native"
      @click.stop="params.native(row)">原操作</button>
    <span v-else>—</span>
  </div>
</template>
<style scoped>
.domestic-grid-cell-actions{display:flex;align-items:center;gap:5px;min-height:100%;white-space:nowrap;padding:0 4px}
.domestic-grid-cell-actions button{height:25px;border:1px solid #cbd5e1;border-radius:4px;padding:0 8px;font-size:12px;background:#fff;cursor:pointer;flex-shrink:0}
.domestic-grid-cell-actions .approve{background:#008f7c;color:white;border-color:#008f7c}
.domestic-grid-cell-actions .stockout{color:#af5b09;border-color:#edb875}
.domestic-grid-cell-actions .reject{color:#d73038;border-color:#eab4b4}
.domestic-grid-cell-actions .native{color:#325475}
.domestic-grid-cell-actions button:disabled{opacity:.45;cursor:not-allowed}
.domestic-grid-cell-actions input{width:132px;min-width:0;height:25px;padding:3px 6px;border:1px solid #d0d8e2;border-radius:4px;font-size:11px}
</style>
