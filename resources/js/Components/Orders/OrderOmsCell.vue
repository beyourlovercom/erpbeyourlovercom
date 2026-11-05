<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import type { ICellRendererParams } from 'ag-grid-community'
import OrderWorkflowStatusPill from '../../Components/Orders/OrderWorkflowStatusPill.vue'

type Workflow = { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean }
type Option = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Order = {
  id: number; erp_order_id: string | null; order_kind?: string;
  source_label?: string; external_order_number?: string;
  workflow_status_projection?: Workflow; workflow_revision?: number
}
type Params = ICellRendererParams & { mode?: 'number' | 'status' | 'action'; options?: Option[]; canEdit?: boolean }
const props = defineProps<{ params: Params }>()
const order = computed(() => props.params.data as Order | undefined)
const href = computed(() => order.value?.order_kind === 'kol_fulfillment'
  ? '/kol/orders/' + order.value?.id : '/customers/orders/' + order.value?.id)
const label = computed(() => order.value?.erp_order_id || '—')
const sourceTitle = computed(() => order.value?.source_label === 'WooCommerce' && order.value.external_order_number
  ? '来源订单：' + order.value.external_order_number : '')
</script>

<template>
  <div class="oms-order-cell">
    <template v-if="order && params.mode === 'status'">
      <OrderWorkflowStatusPill
        v-if="order.workflow_status_projection"
        :order-id="order.id"
        :projection="order.workflow_status_projection"
        :revision="order.workflow_revision"
        :can-edit="params.canEdit === true"
        :options="params.options ?? []"
      />
      <span v-else>—</span>
    </template>
    <Link v-else-if="order" :href="href" class="oms-order-link" :title="sourceTitle || label">
      {{ params.mode === 'action' ? '查看' : label }}
    </Link>
  </div>
</template>

<style scoped>
.oms-order-cell{display:flex;align-items:center;height:100%;min-width:0;overflow:hidden;white-space:nowrap}
.oms-order-link{color:#155dfb;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.oms-order-link:hover{text-decoration:underline}
</style>
