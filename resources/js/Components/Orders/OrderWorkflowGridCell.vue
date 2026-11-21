<script setup lang="ts">
import { computed } from 'vue'
import OrderWorkflowStatusPill from './OrderWorkflowStatusPill.vue'

type Option = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Projection = { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean }
type GridRow = {
  id?: number
  order_id?: number
  workflow_status_projection?: Projection | null
  workflow_revision?: number
  can_edit_workflow_status?: boolean
  workflow_status_options?: Option[]
  order_kind?: string
}
type CellParams = {
  data?: GridRow
  workflowStatusOptions?: Option[]
  canEditWorkflowStatus?: boolean
}
const props = defineProps<{ params: CellParams }>()
const row = computed(() => props.params.data)
const id = computed(() => Number(row.value?.order_id ?? row.value?.id ?? 0))
const projection = computed(() => row.value?.workflow_status_projection ?? null)
const canEdit = computed(() => Boolean(id.value && row.value?.can_edit_workflow_status))
const availableOptions = computed(() => row.value?.workflow_status_options ?? props.params.workflowStatusOptions ?? [])
</script>

<template>
  <span class="erp-order-status-grid-cell" @click.stop @mousedown.stop @keydown.stop>
    <OrderWorkflowStatusPill
      v-if="id && projection"
      :key="`${id}:${row?.workflow_revision ?? 0}`"
      :order-id="id"
      :projection="projection"
      :revision="row?.workflow_revision ?? 0"
      :can-edit="canEdit"
      :options="availableOptions"
    />
    <span v-else>—</span>
  </span>
</template>
<style>
.erp-order-status-grid-cell { display:inline-flex; align-items:center; min-height:100%; min-width:0; max-width:100%; }
.erp-order-status-grid-cell .order-workflow-status select { max-width:100%; }
</style>
