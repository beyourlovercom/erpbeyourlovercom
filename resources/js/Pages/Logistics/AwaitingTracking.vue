<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import LogisticsL2Grid, { type L2Row } from './LogisticsL2Grid.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type ShipmentRow = {
  shipment_id: number
  order_id: number
  external_order_number: string | null
  erp_order_id: string | null
  recipient_name: string | null
  country: string | null
  warehouse_name: string | null
  carrier: string | null
  service: string | null
  tracking_number: string | null
  shipped_at: string | null
  logistics_note: string | null
  shipment_status: string
  order_url: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow>; statusBar?: { key: string; label: string; url: string; count: number }[]; canOperate: boolean;
  globalGridPilotAvailable?:boolean;globalGridPilotEnabled?:boolean;
  gridSearch?:string;gridSort?:{colId:string;direction:'asc'|'desc'} }>()
const selectedGridRow = ref<L2Row|null>(null)
const toggleGrid = () => {
  selectedGridRow.value=null
  router.get('/logistics/awaiting-tracking',{global_grid:props.globalGridPilotEnabled?'0':'1',page:1},
    {preserveScroll:true,preserveState:true,replace:true})
}
type ColumnKey = 'order_number' | 'recipient' | 'country' | 'warehouse' | 'carrier' | 'shipped_at' | 'tracking'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: '订单号' },
  { key: 'recipient', label: '收件人' }, { key: 'country', label: '国家' }, { key: 'warehouse', label: '仓库' },
  { key: 'carrier', label: '物流商 / 渠道' }, { key: 'shipped_at', label: '发货时间' }, { key: 'tracking', label: '追踪号' },
]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.awaiting-tracking.v1', definitions, requiredKeys: ['order_number'] })
const trackingInputs = ref<Record<number, string>>({})
const getTracking = (row: ShipmentRow) => trackingInputs.value[row.order_id] ?? ''
const setTracking = (row: ShipmentRow, v: string) => { trackingInputs.value[row.order_id] = v }

const saveTracking = (row: ShipmentRow) => {
  if (!props.canOperate) return
  const t = (trackingInputs.value[row.order_id] ?? '').trim()
  if (!t) return
  router.post(`/logistics/orders/${row.order_id}/update-tracking`, { tracking_number: t }, { preserveScroll: true })
}

const goToPage = (page: number) => router.get('/logistics/awaiting-tracking', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="已发待单号 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '已发待单号']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>已发待单号</h2><p>已确认发货但追踪号尚未填入的物流单。<strong v-if="!props.canOperate"> 当前角色为只读模式。</strong></p></div></div>
      <div v-if="globalGridPilotAvailable" class="l2-grid-switch"><button
        type="button" class="mvp-button" data-testid="l2-global-grid-toggle" @click="toggleGrid">
        {{ globalGridPilotEnabled?'返回原表':'体验新版表格' }}</button></div>
      <LogisticsL2Grid v-if="globalGridPilotEnabled" :mode="'awaiting'" path="/logistics/awaiting-tracking" title="已发待单号"
        :shipments="shipments" :can-operate="canOperate" :search="gridSearch" :sort="gridSort"
        @open="selectedGridRow=$event" />
      <div v-if="globalGridPilotEnabled && selectedGridRow && canOperate" class="l2-grid-editor" data-testid="l2-grid-editor">
        <strong>{{ selectedGridRow.erp_order_id }} · 填写真实 Tracking</strong>
        <input class="tracking-input" :value="getTracking(selectedGridRow)"
          placeholder="填入追踪号" @input="setTracking(selectedGridRow,($event.target as HTMLInputElement).value)" />
        <button class="mvp-button mvp-button-primary" type="button" :disabled="!getTracking(selectedGridRow).trim()"
          @click="saveTracking(selectedGridRow)">保存 Tracking</button>
        <button class="mvp-button" type="button" @click="selectedGridRow=null">取消</button>
      </div>
      <div v-if="!globalGridPilotEnabled" class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'carrier'">{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</template><template v-else-if="column.key === 'shipped_at'">{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</template><template v-else-if="column.key === 'tracking'"><input v-if="props.canOperate" class="tracking-input" :value="getTracking(row)" @input="setTracking(row, ($event.target as HTMLInputElement).value)" placeholder="填入追踪号" /><span v-else>待回填</span></template></td>
          <td class="mvp-actions"><button v-if="props.canOperate" class="mvp-button mvp-button-primary" type="button" @click="saveTracking(row)">保存</button><span v-else>只读</span></td>
        </tr>
        <tr v-if="shipments.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无已发待单号订单</td></tr>
      </tbody></table></div>
      <div v-if="!globalGridPilotEnabled && shipments.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" :disabled="shipments.current_page <= 1" @click="goToPage(shipments.current_page - 1)">上一页</button>
        <span>第 {{ shipments.current_page }} / {{ shipments.last_page }} 页 · 共 {{ shipments.total }} 条</span>
        <button class="mvp-button" :disabled="shipments.current_page >= shipments.last_page" @click="goToPage(shipments.current_page + 1)">下一页</button>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.tracking-input { border: 1px solid #cfdade; border-radius: 7px; padding: 6px 10px; background: #fff; color: #142126; width: 180px; }
</style>

<style scoped>
.l2-grid-switch{margin:4px 0 8px}
.l2-grid-editor{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin:8px 0;padding:10px;
  border:1px solid #dbe4ea;border-radius:7px;background:#f8fafc;font-size:12px}
.l2-grid-editor>label{display:grid;gap:3px}
.l2-grid-editor textarea{width:min(370px,86vw);min-height:48px;padding:7px;border:1px solid #ccd6dd;border-radius:5px}
.l2-grid-editor-actions{display:flex;gap:5px;flex-wrap:wrap}
</style>
