<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'

type CurrencyCost = { currency: string; total: number; average: number | null; shipments: number }
type Summary = {
  shipment_count: number
  chargeable_weight_kg: number | null
  chargeable_weight_coverage: number
  costs_by_currency: CurrencyCost[]
  this_month_count: number
  delivery_status_coverage: number
  in_transit_count: number
  delivered_count: number
  exception_count: number
  last_shipped_at: string | null
}
type Item = { sku: string | null; product_summary: string | null; product_url?: string | null; quantity: number }
type LedgerRow = {
  id: number; shipped_at: string | null; external_order_number: string | null; erp_order_id: string | null; internal_order_number: string | null
  order_kind: string | null; order_url?: string | null; warehouse_name: string | null; destination_country: string | null; carrier: string | null
  service: string | null; tracking_number: string | null; items: Item[]; chargeable_weight_kg: string | number | null
  actual_cost: string | number | null; currency: string | null; shipment_status: string | null; delivery_status: string | null
  handler_name: string | null; recipient_name: string | null; billing_document_number: string | null; provider_reference: string | null
  data_source: string | null; billed_at: string | null; raw_evidence: Record<string, unknown> | unknown[] | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }
type ColumnKey = 'order_number' | 'shipped_at' | 'order_kind' | 'warehouse' | 'destination' | 'channel' | 'tracking' | 'items' | 'weight' | 'cost' | 'shipment_status' | 'delivery_status' | 'handler' | 'recipient' | 'billing' | 'source' | 'evidence'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'shipped_at', label: '发货日期' }, { key: 'order_kind', label: '订单类型' }, { key: 'warehouse', label: '发货仓库' },
  { key: 'destination', label: '目的地' }, { key: 'channel', label: '物流渠道' }, { key: 'tracking', label: 'Tracking' }, { key: 'items', label: '产品/数量' },
  { key: 'weight', label: '计费重量' }, { key: 'cost', label: '物流费用' }, { key: 'shipment_status', label: 'Shipment状态' }, { key: 'delivery_status', label: '签收状态' },
  { key: 'handler', label: '发货人' }, { key: 'recipient', label: '收件人' }, { key: 'billing', label: '账单/单据' }, { key: 'source', label: '数据来源' }, { key: 'evidence', label: '原始凭证' },
]
const grid = reactive(useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.carriers.show.v1', definitions, requiredKeys: ['order_number'] }))

const props = defineProps<{ carrier: string; carrierDisplayName: string; summary: Summary; shipments: Paginated<LedgerRow>; statusBar?: {key:string;label:string;url:string;count:number}[] }>()
const dateTime = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
const money = (costs: CurrencyCost[], field: 'total' | 'average') => costs.length === 0 ? '—' : costs.map((row) => {
  const value = row[field]
  return `${row.currency === 'UNSPECIFIED' ? '未标币种' : row.currency} ${value === null ? '—' : value.toFixed(2)}`
}).join(' / ')
const rowMoney = (row: LedgerRow) => row.actual_cost === null ? '—' : `${row.currency || '未标币种'} ${Number(row.actual_cost).toFixed(2)}`
const orderKind = (value: string | null) => value === 'customer' ? '客户订单' : value === 'kol_fulfillment' ? '网红履约订单' : (value ?? '—')
const itemText = (items: Item[]) => items.length === 0 ? '—' : items.map((item) => `${item.sku || item.product_summary || '未命名'} × ${item.quantity}`).join('；')
const deliveryCount = (value: number) => props.summary.delivery_status_coverage === 0 ? '—' : String(value)
const goToPage = (page: number) => router.get(`/logistics/carriers/${encodeURIComponent(props.carrier)}`, { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head :title="`${carrierDisplayName} · 承运商 · BYL ERP`" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '承运商', carrierDisplayName]">
    <section class="mvp-page carrier-detail-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading carrier-heading">
        <div><p class="mvp-kicker">Carrier Shipment Ledger</p><h2>{{ carrierDisplayName }}</h2><p>真实 Shipment 历史、计费重量、实际物流费用与 Provider raw evidence。</p></div>
        <Link class="mvp-button" href="/logistics/carriers">返回承运商</Link>
      </div>

      <div class="carrier-kpis">
        <div><span>总发货票数</span><strong>{{ summary.shipment_count }}</strong></div>
        <div><span>总计费重量</span><strong>{{ summary.chargeable_weight_kg === null ? '—' : `${summary.chargeable_weight_kg.toFixed(3)} kg` }}</strong></div>
        <div><span>总物流费用</span><strong>{{ money(summary.costs_by_currency, 'total') }}</strong></div>
        <div><span>平均每票费用</span><strong>{{ money(summary.costs_by_currency, 'average') }}</strong></div>
        <div><span>本月发货数</span><strong>{{ summary.this_month_count }}</strong></div>
        <div><span>运输中</span><strong>{{ deliveryCount(summary.in_transit_count) }}</strong></div>
        <div><span>已签收</span><strong>{{ deliveryCount(summary.delivered_count) }}</strong></div>
        <div><span>异常</span><strong>{{ deliveryCount(summary.exception_count) }}</strong></div>
        <div><span>最后一票发货</span><strong>{{ dateTime(summary.last_shipped_at) }}</strong></div>
      </div>

      <div class="mvp-table-wrap ledger-wrap">
        <table class="mvp-table ledger-table">
          <thead><tr><th v-for="column in grid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="grid.columns" :required-keys="['order_number']" :sync-state="grid.syncState" @toggle="grid.toggle" @start-drag="grid.startDrag" @drop="grid.drop" @reset="grid.reset" /></tr></thead>
          <tbody>
            <tr v-for="row in shipments.data" :key="row.id">
              <td v-for="column in grid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link"><strong>{{ row.erp_order_id ?? '—' }}</strong></Link><span v-else><strong>{{ row.erp_order_id ?? '—' }}</strong></span></template><template v-else-if="column.key === 'shipped_at'">{{ dateTime(row.shipped_at) }}</template><template v-else-if="column.key === 'order_kind'">{{ orderKind(row.order_kind) }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'destination'">{{ row.destination_country ?? '—' }}</template><template v-else-if="column.key === 'channel'">{{ row.service ?? '—' }}</template><template v-else-if="column.key === 'tracking'">{{ row.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'items'" class="items-cell"><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku || item.product_summary || '未命名' }}</Link><span v-else>{{ item.sku || item.product_summary || '未命名' }}</span> × {{ item.quantity }}；</template></template><template v-else-if="column.key === 'weight'">{{ row.chargeable_weight_kg === null ? '—' : `${Number(row.chargeable_weight_kg).toFixed(3)} kg` }}</template><template v-else-if="column.key === 'cost'">{{ rowMoney(row) }}</template><template v-else-if="column.key === 'shipment_status'">{{ row.shipment_status ?? '—' }}</template><template v-else-if="column.key === 'delivery_status'">{{ row.delivery_status ?? '—' }}</template><template v-else-if="column.key === 'handler'">{{ row.handler_name ?? '—' }}</template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'billing'">{{ row.billing_document_number ?? row.provider_reference ?? '—' }}</template><template v-else-if="column.key === 'source'">{{ row.data_source ?? '—' }}</template><template v-else><details v-if="row.raw_evidence"><summary>查看</summary><pre>{{ JSON.stringify(row.raw_evidence, null, 2) }}</pre></details><span v-else>—</span></template></td>
            </tr>
            <tr v-if="shipments.data.length === 0"><td :colspan="grid.visibleColumns.length" class="mvp-empty">暂无该承运商物流记录。</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="shipments.last_page > 1" class="mvp-pagination"><button class="mvp-button" :disabled="shipments.current_page <= 1" @click="goToPage(shipments.current_page - 1)">上一页</button><span>第 {{ shipments.current_page }} / {{ shipments.last_page }} 页 · 共 {{ shipments.total }} 条</span><button class="mvp-button" :disabled="shipments.current_page >= shipments.last_page" @click="goToPage(shipments.current_page + 1)">下一页</button></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.carrier-heading { align-items: flex-start; display: flex; justify-content: space-between; gap: 16px; }
.carrier-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
.carrier-kpis > div { border: 1px solid var(--byl-color-border, #d8e0e3); border-radius: 9px; padding: 12px; background: var(--byl-color-surface, #fff); }
.carrier-kpis span { display: block; font-size: 12px; color: var(--byl-color-muted, #6b7f87); margin-bottom: 6px; }
.carrier-kpis strong { font-size: 15px; }
.ledger-wrap { overflow-x: auto; }
.ledger-table { min-width: 1900px; }
.ledger-table small { display: block; opacity: .65; }
.items-cell { min-width: 210px; white-space: normal; }
details pre { max-width: 360px; max-height: 220px; overflow: auto; font-size: 11px; white-space: pre-wrap; }
</style>
