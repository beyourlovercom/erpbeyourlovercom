<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'

type Params = {
  displayName: string
  filterValue?: string
  progressSort?: (multiSort: boolean) => void
  onServerFilter?: (value: string) => void
}
const props = defineProps<{ params: Params }>()
const value = ref(props.params.filterValue ?? '')
let timer: ReturnType<typeof setTimeout> | undefined
watch(() => props.params.filterValue, next => {
  if ((next ?? '') !== value.value) value.value = next ?? ''
})
const submit = () => {
  if (timer) clearTimeout(timer)
  const next = value.value.trim()
  if (next !== (props.params.filterValue ?? '').trim()) props.params.onServerFilter?.(next)
}
const onInput = () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(submit, 450)
}
const clear = () => { value.value = ''; submit() }
onBeforeUnmount(() => { if (timer) clearTimeout(timer) })
</script>

<template>
  <div class="oms-server-head" @click.stop @keydown.stop>
    <button type="button" class="oms-server-head-title" :title="'按 ' + params.displayName + ' 排序'" @click.stop="params.progressSort?.(false)">{{ params.displayName }}</button>
    <div class="oms-server-head-search">
      <input
        v-model="value"
        type="text"
        :aria-label="'筛选列 ' + params.displayName"
        :placeholder="'搜索' + params.displayName"
        @input="onInput"
        @keydown.enter.stop.prevent="submit"
      />
      <button v-if="value" type="button" :aria-label="'清空' + params.displayName + '筛选'" @click.stop="clear">×</button>
    </div>
  </div>
</template>
<style scoped>
.oms-server-head{display:flex;flex-direction:column;justify-content:center;gap:3px;width:100%;height:100%;min-width:0}
.oms-server-head-title{display:block;align-self:flex-start;width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border:0;background:transparent;padding:0;color:inherit;font-size:12px;font-weight:600;text-align:left;cursor:pointer}
.oms-server-head-search{display:flex;align-items:center;min-width:0;width:100%;height:23px;border:1px solid #d2dae5;border-radius:3px;background:white}
.oms-server-head-search input{border:0;outline:0;min-width:0;width:100%;height:20px;font-size:11px;padding:0 4px;color:#1e293b}
.oms-server-head-search button{border:0;background:white;color:#64748b;padding:0 4px;font-size:13px}
.oms-server-head-search:focus-within{border-color:#2563eb}
</style>
