<script setup lang="ts">
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'

type Auxiliary = { code: string; en: string; zh: string; color: string }
type Option = { code: string; en: string; zh: string; color: string; mutable: boolean; source?: string }
const props = withDefaults(defineProps<{
  orderId: number
  projection: { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean; auxiliary?: Auxiliary[] }
  revision?: number
  canEdit?: boolean
  canManageAuxiliary?: boolean
  options?: Option[]
}>(), { revision: 0, canEdit: false, canManageAuxiliary: false, options: () => [] })
const code = ref(props.projection.code)
const revision = ref(props.revision)
const auxiliary = ref<Auxiliary[]>(props.projection.auxiliary ?? [])
const saving = ref(false)
const error = ref('')
const select = ref<HTMLSelectElement | null>(null)
const stageNumber: Record<string, string> = {
  pending_allocation: '0.0', pending_review: '0.1', partially_shipped: '1.4', sending: '1.1', sent: '1.2', resent: '1.3', missing_info: '1.5', returned: '1.6',
  shipping: '2.1', shared: '2.2', arrived: '2.3', creating: '2.4', published: '2.5',
  paying: '2.6', paid: '2.7', cancel: '2.8', refunded: '2.9', finished: '3.0',
  returned_follow_up: '3.1', claims: '3.2',
  freight_product_cost_follow_up: '3.3', out_of_stock: '3.4', renew: '3.5', exception: '3.6', cancelled: '2.8',
}
const optionCaption = (option: Option) => `${stageNumber[option.code] ? stageNumber[option.code] + ' ' : ''}${option.en} · ${option.zh}`
watch(() => props.projection, (next) => {
  code.value = next.code
  auxiliary.value = next.auxiliary ?? []
  error.value = ''
}, { deep: true })
watch(() => props.revision, (next) => { revision.value = next })
const current = computed(() => props.options.find(option => option.code === code.value) ?? props.projection)
const visibleOptions = computed<Option[]>(() => [
  ...props.options.filter(option => (option.code !== 'allocated' || code.value === 'allocated')
    && (option.code !== 'cancelled' || props.projection.source === 'canonical_projection')),
  // Missing-info exceptions clear via their own "信息缺失" tag once the user
  // supplies details. Generic exception resolution must not bypass that hold.
  ...(code.value === 'exception' && !auxiliary.value.some(aux => aux.code === 'missing_info')
    ? [{ code:'resolve_exception', en:'Resolve Exception', zh:'解除异常', color:'amber', mutable:true }] : []),
  ...(code.value === 'out_of_stock' ? [{ code:'resolve_stockout', en:'Restock', zh:'解除缺货', color:'amber', mutable:true }] : []),
])
const update = async (next: string) => {
  if (!props.canEdit || saving.value || next === code.value) return
  const previous = code.value
  let reason: string | undefined
  if (next === 'cancelled') {
    if (!window.confirm('确认在 ERP 中真正取消此订单？不会自动向 WooCommerce 取消，也不会退款。')) {
      if (select.value) select.value.value = previous
      return
    }
    const supplied = window.prompt('请输入取消原因（至少 3 个字）；不会自动退款或更改网站状态。')
    if (!supplied || supplied.trim().length < 3) {
      if (select.value) select.value.value = previous
      return
    }
    reason = supplied.trim()
  }
  saving.value = true; error.value = ''
  try {
    const response = await axios.patch(`/orders/${props.orderId}/workflow-status`, {
      workflow_status: next,
      expected_workflow_revision: revision.value,
      ...(reason ? { reason } : {}),
    })
    code.value = response.data.workflow_status.code
    revision.value = response.data.order.workflow_revision
    auxiliary.value = response.data.workflow_status.auxiliary ?? []
    router.reload()
  } catch (e: any) {
    code.value = previous
    if (select.value) select.value.value = previous
    error.value = e?.response?.status === 409 ? '状态已变化，请刷新后重试' : (e?.response?.data?.message ?? '保存失败')
  } finally {
    saving.value = false
    await nextTick()
    if (select.value) select.value.value = code.value
  }
}
const removeAuxiliary = async (aux: Auxiliary) => {
  if (!props.canManageAuxiliary || saving.value) return
  saving.value = true
  error.value = ''
  try {
    const response = await axios.delete(`/orders/${props.orderId}/auxiliary-status/${aux.code}`, {
      data: { expected_workflow_revision: revision.value },
    })
    code.value = response.data.workflow_status.code
    revision.value = response.data.order.workflow_revision
    auxiliary.value = response.data.workflow_status.auxiliary ?? []
    // Removing an information-deficiency hold must also refresh order actions
    // and the primary queue projection; not just erase the auxiliary chip.
    router.reload()
  } catch (e: any) {
    error.value = e?.response?.status === 409 ? '状态已变化，请刷新后重试' : (e?.response?.data?.message ?? '移除失败')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <span class="order-workflow-status" :class="`status-color-${current.color}`" :data-color="current.color">
    <select v-if="canEdit && options.length && !['cancelled', 'archived', 'allocated', 'progress_conflict', 'partially_shipped', 'completed'].includes(current.code)" ref="select" :value="code" :disabled="saving" :aria-label="`订单工作状态 ${current.zh}`" :title="`工作进度选项按编号显示；${current.zh}`" @change="update(($event.target as HTMLSelectElement).value)">
      <option v-for="option in visibleOptions" :key="option.code" :value="option.code" :disabled="!option.mutable">{{ optionCaption(option) }}</option>
    </select>
    <span v-else class="mvp-status" :title="`${current.en} · ${current.source === 'canonical_projection' ? '来自订单真实状态的只读投影' : '员工工作进度'}`" :aria-label="`订单状态 ${current.zh}`">{{ current.zh }}</span>
    <span v-for="aux in auxiliary" :key="aux.code" class="auxiliary-tag">
      {{ aux.zh }}
      <button v-if="canManageAuxiliary" type="button" :disabled="saving" :aria-label="`移除${aux.zh}`" @click="removeAuxiliary(aux)">×</button>
    </span>
    <small v-if="error" role="alert">{{ error }}</small>
  </span>
</template>

<style scoped>
.order-workflow-status { display:inline-flex; align-items:center; gap:5px; max-width:100%; }
.order-workflow-status select, .order-workflow-status .mvp-status { border:1px solid #d9e3dd; border-radius:999px; padding:4px 24px 4px 8px; font-size:11px; font-weight:800; line-height:1.2; }
.order-workflow-status select { min-width:132px; cursor:pointer; background:#fff; }
.order-workflow-status.status-color-neutral select, .order-workflow-status.status-color-neutral .mvp-status { color:#596861; background:#f1f4f2; }
.order-workflow-status.status-color-blue select, .order-workflow-status.status-color-blue .mvp-status { color:#1768a5; background:#eaf4fc; }
.order-workflow-status.status-color-amber select, .order-workflow-status.status-color-amber .mvp-status { color:#936012; background:#fff4df; }
.order-workflow-status.status-color-green select, .order-workflow-status.status-color-green .mvp-status { color:#17633b; background:#e5f3e9; }
.order-workflow-status.status-color-red select, .order-workflow-status.status-color-red .mvp-status { color:#9b3030; background:#fce9e9; }
.order-workflow-status .auxiliary-tag { border:1px solid #ebdba8; border-radius:999px; padding:2px 6px; font-size:10px; white-space:nowrap; background:#fff8e7; color:#8c631b; }
.order-workflow-status .auxiliary-tag button { border:0; background:transparent; font-weight:700; color:inherit; cursor:pointer; }
.order-workflow-status small { color:#a33a3a; font-size:10px; }
</style>
