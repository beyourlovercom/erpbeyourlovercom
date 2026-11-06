<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import type { ICellRendererParams } from 'ag-grid-community'
import OrderWorkflowStatusPill from '../../Components/Orders/OrderWorkflowStatusPill.vue'

type Workflow = { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean }
type Option = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Order = {
  _is_additional_item?: boolean;
  id: number; erp_order_id: string | null; order_kind?: string;
  source_label?: string; external_order_number?: string;
  workflow_status_projection?: Workflow; workflow_revision?: number; tracking_sync_status?: string | null; tracking_sync_label?: string | null; tracking_sync_reason?: string | null
}
type Params = ICellRendererParams & { mode?: 'number' | 'status' | 'action' | 'sync'; options?: Option[]; canEdit?: boolean }
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
    <template v-if="order && order._is_additional_item && params.mode !== 'number'"><span aria-label="同订单附加SKU行" class="oms-item-followup">·</span></template>
    <template v-else-if="order && params.mode === 'status'">
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
    <template v-else-if="order && params.mode === 'sync'"><span v-if="order.tracking_sync_status" class="oms-sync-pill" :class="order.tracking_sync_status" :title="order.tracking_sync_reason ?? ''">{{ order.tracking_sync_label ?? '—' }}</span><span v-else>—</span></template>
    <Link v-else-if="order" :href="href" class="oms-order-link" :title="sourceTitle || label">
      {{ params.mode === 'action' ? '查看' : label }}
    </Link>
  </div>
</template>

<style scoped>
.oms-order-cell{display:flex;align-items:center;height:100%;min-width:0;overflow:hidden;white-space:nowrap}
.oms-order-link{color:#155dfb;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.oms-order-link:hover{text-decoration:underline}
.oms-item-followup{color:#cbd5e1}
.oms-sync-pill{display:inline-flex;align-items:center;padding:3px 7px;border-radius:5px;font-size:11px;font-weight:700;background:#fff1d6;color:#92400e;white-space:nowrap}.oms-sync-pill.completed{background:#dcfce7;color:#166534}.oms-sync-pill.failed{background:#fee2e2;color:#991b1b}.oms-sync-pill.retry_wait{background:#fef3c7;color:#92400e}.oms-sync-pill.processing{background:#dbeafe;color:#1d4ed8}.oms-sync-pill.partial{background:#fef3c7;color:#92400e}
</style>
