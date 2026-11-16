<script setup lang="ts">
import { ref, watch } from 'vue'

type Option = { value:string; label:string }
type Params = {
  filterValue?:string
  onServerFilter?:(value:string)=>void
  options?:Option[]
  placeholder?:string
  column?:{getColDef?:()=>{headerName?:string}}
}
const props = defineProps<{params:Params}>()
const current = ref(props.params.filterValue??'')
watch(()=>props.params.filterValue,value=>{current.value=value??''})
function change(value:string){
  current.value=value
  if(value!==(props.params.filterValue??''))props.params.onServerFilter?.(value)
}
</script>

<template>
  <select :value="current" class="domestic-grid-select-filter"
    :aria-label="'筛选列 '+(params.column?.getColDef?.()?.headerName??params.placeholder??'')"
    @mousedown.stop @click.stop @keydown.stop
    @change="change(($event.target as HTMLSelectElement).value)">
    <option value="">{{ params.placeholder??'全部' }}</option>
    <option v-for="option in params.options??[]" :key="option.value" :value="option.value">{{ option.label }}</option>
  </select>
</template>

<style scoped>
.domestic-grid-select-filter{width:100%;height:24px;min-width:0;padding:0 3px;border:1px solid #cbd5e1;border-radius:3px;background:white;color:#253146;font-size:11px}
.domestic-grid-select-filter:focus{outline:1px solid #0f766e}
</style>
