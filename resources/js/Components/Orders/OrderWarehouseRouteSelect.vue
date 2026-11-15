<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

type Route = { code:string; name:string; source:string; revision:number }
type Option = { code:string; name:string }
const props = withDefaults(defineProps<{
  orderId:number; projection:Route; options:Option[]; canEdit?:boolean
}>(), { canEdit:false })
const selected = ref(props.projection.code)
const saving = ref(false)
const error = ref('')
watch(() => [props.projection.code, props.projection.revision], () => {
  if (!saving.value) selected.value = props.projection.code
})
const save = async (code:string) => {
  if (!props.canEdit || saving.value || code === props.projection.code) return
  saving.value = true
  error.value = ''
  try {
    const response = await axios.patch('/orders/' + props.orderId + '/warehouse-route', {
      warehouse_code: code, expected_routing_revision: props.projection.revision,
    })
    selected.value = response.data.routing_warehouse.code
    // A successful assignment must leave the old workbench, not only repaint
    // the dropdown in a stale AG Grid row. Navigate to its true status + route.
    const target = String(response.data.target_url ?? '').trim()
    if (target.startsWith('/') && !target.startsWith('//')) {
      router.visit(target, { replace: true, preserveState: false, preserveScroll: false })
    } else {
      router.reload()
    }
  } catch (e:any) {
    selected.value = props.projection.code
    error.value = e?.response?.data?.message ?? '所属分仓保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <span class="erp-order-route-select" @click.stop @mousedown.stop @keydown.stop>
    <select v-if="canEdit" :value="selected" :disabled="saving"
      aria-label="订单所属分仓" @change="save(($event.target as HTMLSelectElement).value)">
      <option v-if="!options.some(o=>o.code===selected)" :value="selected">{{ projection.name }}</option>
      <option v-for="option in options" :key="option.code" :value="option.code">{{ option.name }}</option>
    </select>
    <span v-else :title="projection.source==='manual'?'人工指定':'现有仓库记录'">{{ projection.name }}</span>
    <small v-if="error" class="erp-route-error" role="alert">{{ error }}</small>
  </span>
</template>
<style>
.erp-order-route-select { display:inline-flex; align-items:center; max-width:100%; min-width:0; gap:4px; }
.erp-order-route-select select { max-width:100%; width:100%; padding:6px 26px 6px 9px; border:1px solid #c9d5d9; border-radius:9px; background:#fff; color:#20334a; font:inherit; font-size:12px; }
.erp-order-route-select select:focus { outline:2px solid #0f766e; outline-offset:1px; }
.erp-route-error { color:#b91c1c; font-size:11px; }
</style>
