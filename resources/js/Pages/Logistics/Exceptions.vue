<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
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
  paused?: boolean
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow>; statusBar?: { key: string; label: string; url: string; count: number }[]; canOperate: boolean;
  globalGridPilotAvailable?:boolean;globalGridPilotEnabled?:boolean;
  gridSearch?:string;gridSort?:{colId:string;direction:'asc'|'desc'} }>()
const selectedGridRow = ref<L2Row|null>(null)
const toggleGrid = () => {
  selectedGridRow.value=null
  router.get('/logistics/exceptions',{global_grid:props.globalGridPilotEnabled?'0':'1',page:1},
    {preserveScroll:true,preserveState:true,replace:true})
}
type ColumnKey = 'order_number' | 'recipient' | 'country' | 'warehouse' | 'carrier' | 'tracking' | 'shipped_at' | 'reason'
const definitions: GridColumnDefinition<ColumnKey>[] = [{ key: 'order_number', label: '订单号' }, { key: 'recipient', label: '收件人' }, { key: 'country', label: '国家' }, { key: 'warehouse', label: '仓库' }, { key: 'carrier', label: '物流商 / 渠道' }, { key: 'tracking', label: '追踪号' }, { key: 'shipped_at', label: '发货时间' }, { key: 'reason', label: '异常原因' }]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.exceptions.v1', definitions, requiredKeys: ['order_number'] })
const activeAction = ref<{ orderId:number; path:string; label:string } | null>(null)
const notes = reactive<Record<number,string>>({})

const resolve = (row: ShipmentRow) => {
  if (!props.canOperate) return
  router.post(`/logistics/orders/${row.order_id}/resolve-exception`, {}, { preserveScroll: true })
}
const action = (row: ShipmentRow, path: string, label: string) => { if (!props.canOperate) return; activeAction.value = { orderId: row.order_id, path, label }; notes[row.order_id] ??= '' }
const submitAction = (row: ShipmentRow) => { if (!props.canOperate) return; const note = (notes[row.order_id] ?? '').trim(); if (!note) return; router.post(`/logistics/orders/${row.order_id}/${activeAction.value?.path}`, { logistics_note: note }, { preserveScroll: true }); activeAction.value = null }

const goToPage = (page: number) => router.get('/logistics/exceptions', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="异常订单 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '异常订单']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>异常订单</h2><p>物流状态异常的待处理订单。<strong v-if="!props.canOperate"> 当前角色为只读模式。</strong></p></div>
        <div v-if="globalGridPilotAvailable" class="l2-grid-switch"><button
        type="button" class="mvp-button" data-testid="l2-global-grid-toggle" @click="toggleGrid">
        {{ globalGridPilotEnabled?'返回原表':'体验新版表格' }}</button></div>
      </div>
      <LogisticsL2Grid v-if="globalGridPilotEnabled" :mode="'exceptions'" path="/logistics/exceptions" title="异常订单"
        :shipments="shipments" :can-operate="canOperate" :search="gridSearch" :sort="gridSort"
        @open="selectedGridRow=$event" @resolve="resolve($event)" />
      <div v-if="globalGridPilotEnabled && selectedGridRow && canOperate" class="l2-grid-editor" data-testid="l2-grid-editor">
        <strong>{{ selectedGridRow.erp_order_id }} · 处理异常订单</strong>
        <div class="l2-grid-editor-actions">
          <button v-for="(actionDef,i) in [
             ['pause','暂停订单'],['redispatch','重新派件'],['reship','重新发货'],
             ['contact-user','联系用户'],['cancel-exception','取消订单（不退款）']
           ]" :key="i" type="button" class="mvp-button"
            @click="action(selectedGridRow,actionDef[0],actionDef[1])">{{ actionDef[1] }}</button>
          <button type="button" class="mvp-button" @click="resolve(selectedGridRow)">解除异常</button>
        </div>
        <template v-if="activeAction?.orderId===selectedGridRow.order_id">
          <label>{{ activeAction.label }}说明（必填）<textarea
            v-model="notes[selectedGridRow.order_id]" rows="2" required /></label>
          <button type="button" class="mvp-button mvp-button-primary"
            :disabled="!(notes[selectedGridRow.order_id]??'').trim()"
            @click="submitAction(selectedGridRow)">确认{{ activeAction.label }}</button>
        </template>
        <button class="mvp-button" type="button" @click="selectedGridRow=null;activeAction=null">关闭操作</button>
      </div>
      <div v-if="!globalGridPilotEnabled" class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'carrier'">{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</template><template v-else-if="column.key === 'tracking'">{{ row.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'shipped_at'">{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</template><template v-else class="exception-note"><strong v-if="row.paused">已暂停 · </strong>{{ row.logistics_note ?? '—' }}</template></td>
          <td class="mvp-actions"><template v-if="props.canOperate"><button class="mvp-button" type="button" @click="action(row, 'pause', '暂停订单')">暂停订单</button><button class="mvp-button" type="button" @click="action(row, 'redispatch', '重新派件')">重新派件</button><button class="mvp-button" type="button" @click="action(row, 'reship', '重新发货')">重新发货</button><button class="mvp-button" type="button" @click="action(row, 'contact-user', '联系用户')">联系用户</button><button class="mvp-button" type="button" @click="action(row, 'cancel-exception', '取消订单（不退款）')">取消订单（不退款）</button><button class="mvp-button mvp-button-primary" type="button" @click="resolve(row)">解除异常</button></template><span v-else>只读</span></td>
        </tr>
        <tr v-for="row in shipments.data.filter((item) => props.canOperate && activeAction?.orderId === item.order_id)" :key="`${row.shipment_id}-action`"><td :colspan="visibleColumns.length + 2"><label>{{ activeAction?.label }}说明（必填）<textarea v-model="notes[row.order_id]" rows="2" required /></label><button class="mvp-button mvp-button-primary" :disabled="!(notes[row.order_id] ?? '').trim()" @click="submitAction(row)">确认{{ activeAction?.label }}</button><button class="mvp-button" @click="activeAction = null">取消</button></td></tr>
        <tr v-if="shipments.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无异常订单</td></tr>
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
.exception-note { max-width: 220px; white-space: pre-wrap; font-size: 12px; color: #7a5f18; }
</style>

<style scoped>
.l2-grid-switch{margin-left:auto;display:flex;justify-content:flex-end;align-items:center;flex-shrink:0}
@media(max-width:900px){.l2-grid-switch{align-self:flex-end}}
.l2-grid-editor{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin:8px 0;padding:10px;
  border:1px solid #dbe4ea;border-radius:7px;background:#f8fafc;font-size:12px}
.l2-grid-editor>label{display:grid;gap:3px}
.l2-grid-editor textarea{width:min(370px,86vw);min-height:48px;padding:7px;border:1px solid #ccd6dd;border-radius:5px}
.l2-grid-editor-actions{display:flex;gap:5px;flex-wrap:wrap}
</style>
