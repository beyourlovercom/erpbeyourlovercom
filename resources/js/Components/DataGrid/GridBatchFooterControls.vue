<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type GridBatchOption = { value: string; label: string; reasonRequired?: boolean }
const props = withDefaults(defineProps<{
  selectedCount: number
  options: GridBatchOption[]
  busy?: boolean
}>(), {busy:false})
const emit = defineEmits<{
  (event:'apply', data:{ action: string; reason:string }): void
}>()
const action = ref('')
const reason = ref('')
watch(()=>props.selectedCount, value => { if (!value) {action.value=''; reason.value=''} })
const chosen = computed(()=>props.options.find(option=>option.value===action.value))
const canApply = computed(()=>!props.busy && !!chosen.value
  && (!chosen.value.reasonRequired || reason.value.trim().length>=3))
const execute = () => {
  if (!canApply.value || !chosen.value) return
  emit('apply',{action:chosen.value.value, reason:reason.value.trim()})
}
</script>
<template>
  <div v-if="selectedCount>0" class="grid-batch-footer" data-testid="grid-batch-footer">
    <strong>已选 {{ selectedCount }} 条</strong>
    <select v-model="action" aria-label="批量操作">
      <option value="">批量操作</option>
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
    <input v-if="chosen?.reasonRequired" v-model="reason" type="text" minlength="3"
      maxlength="500" placeholder="驳回原因（至少3字）" aria-label="批量驳回原因" />
    <button v-if="chosen" type="button" class="apply-batch" :disabled="!canApply" @click="execute">
      {{ busy?'执行中…':'应用' }}
    </button>
  </div>
</template>
<style scoped>
.grid-batch-footer{display:flex;align-items:center;gap:8px;min-width:0;font-size:12px}
.grid-batch-footer strong{white-space:nowrap;color:#315276;font-size:12px}
.grid-batch-footer select,.grid-batch-footer input{height:29px;border:1px solid #cbd5e1;border-radius:5px;background:white;color:#243b55;font-size:12px;padding:3px 8px}
.grid-batch-footer select{min-width:128px}
.grid-batch-footer input{min-width:140px;max-width:210px}
.grid-batch-footer .apply-batch{height:29px;background:#075bea;color:white;border:0;border-radius:5px;padding:3px 14px;font-weight:600;cursor:pointer}
.grid-batch-footer .apply-batch:disabled{opacity:.5;cursor:not-allowed}
</style>
