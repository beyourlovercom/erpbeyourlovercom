<script setup lang="ts">
import { ref, watch } from 'vue'

type Params = {
  filterValue?: string
  onServerFilter?: (value: string) => void
}
const props = defineProps<{ params: Params }>()
const selected = ref(props.params.filterValue ?? '')
watch(() => props.params.filterValue, value => { selected.value = value ?? '' })
const apply = (value: string) => {
  selected.value = value
  if (value !== (props.params.filterValue ?? '')) props.params.onServerFilter?.(value)
}
</script>

<template>
  <select
    :value="selected"
    class="sync-progress-filter"
    aria-label="筛选列 同步进度"
    title="筛选全部订单的同步进度"
    @mousedown.stop
    @click.stop
    @change="apply(($event.target as HTMLSelectElement).value)"
  >
    <option value="">全部状态</option>
    <option value="unsynced">未同步</option>
    <option value="failed">同步失败</option>
    <option value="synced">已同步</option>
    <option value="notified">已通知</option>
  </select>
</template>

<style scoped>
.sync-progress-filter{width:100%;min-width:0;height:24px;padding:0 2px;
  font-size:11px;border:1px solid #cbd5e1;border-radius:3px;background:#fff;color:#253146}
.sync-progress-filter:focus{outline:1px solid #2563eb}
</style>
