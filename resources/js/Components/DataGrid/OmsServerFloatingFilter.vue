<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'

type Params = {
  filterValue?: string
  onServerFilter?: (value: string) => void
  displayName?: string
  column?: { getColDef?: () => { headerName?: string } }
}
const props = defineProps<{ params: Params }>()
const current = ref(props.params.filterValue ?? '')
const columnLabel = props.params.column?.getColDef?.()?.headerName ?? props.params.displayName ?? '当前列'
let timer: ReturnType<typeof setTimeout> | undefined
watch(() => props.params.filterValue, value => {
  if ((value ?? '') !== current.value) current.value = value ?? ''
})
function submit() {
  if (timer) clearTimeout(timer)
  const value = current.value.trim()
  if (value !== (props.params.filterValue ?? '').trim()) props.params.onServerFilter?.(value)
}
function onInput() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(submit, 450)
}
function clearFilter() {
  current.value = ''
  submit()
}
onBeforeUnmount(() => { if (timer) clearTimeout(timer) })
</script>

<template>
  <div class="oms-server-floating" @click.stop @mousedown.stop @keydown.stop>
    <input
      v-model="current"
      type="text"
      :aria-label="'筛选列 ' + columnLabel"
      :placeholder="'筛选...'"
      autocomplete="off"
      @input="onInput"
      @keydown.enter.stop.prevent="submit"
    />
    <button v-if="current" type="button" :aria-label="'清空' + columnLabel + '筛选'" @click.stop="clearFilter">×</button>
  </div>
</template>

<style scoped>
.oms-server-floating{height:24px;width:100%;min-width:0;display:flex;align-items:center;border:1px solid #cbd5e1;background:#fff;border-radius:3px}
.oms-server-floating:focus-within{border-color:#2563eb}
.oms-server-floating input{width:100%;height:22px;min-width:0;padding:0 5px;border:0;outline:0;background:transparent;font-size:11px;color:#253146}
.oms-server-floating button{font-size:13px;line-height:20px;flex:none;padding:0 5px;background:transparent;border:0;color:#64748b;cursor:pointer}
</style>
