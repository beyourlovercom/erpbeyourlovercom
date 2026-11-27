<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ params: { label: string; current: () => string; setStatus: (value: string) => void } }>()
const value = computed(() => props.params.current())
const options = [
  ['', '全部库存'], ['zero', '缺货（0）'], ['low', '低库存（1–20）'],
  ['mid', '库存 21–50'], ['high', '库存 >50'], ['incoming', '有海运在途'],
  ['noincoming', '无海运在途'], ['risk', '缺货且无在途'],
  ['pending', '已收货待激活'], ['unknown', '库存未记录'],
]
function change(event: Event) { props.params.setStatus((event.target as HTMLSelectElement).value) }
// Numeric filters in the column menu are independent of the status dropdown.
function onParentModelChanged() {}
defineExpose({ onParentModelChanged })
</script>

<template>
  <select class="matrix-status-filter" :aria-label="params.label + '库存筛选'" :value="value" @change="change">
    <option v-for="[key,label] in options" :key="key" :value="key">{{ label }}</option>
  </select>
</template>

<style scoped>
.matrix-status-filter{width:100%;min-width:0;height:25px;border:1px solid #dbe2e8;border-radius:4px;background:var(--ag-background-color,#fff);color:inherit;font-size:11px}
</style>
