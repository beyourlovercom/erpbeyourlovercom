<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onMounted, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import FinanceFactsDrawer from './FinanceFactsDrawer.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import {
    useAccountGridPreferences,
    type GridColumnDefinition,
    type GridPreferenceValue,
} from '../../Composables/useAccountGridPreferences'

type CostBreakdown = {
    product_cost: string | null
    logistics_cost: string | null
    other_cost: string | null
}

type Summary = {
    completeness: string
    finance_status: string
    revenue_status: string
    cost_status: string
    profit_status: string
    missing_facts: string[]
    aggregation_missing_facts: string[]
    fx_status: string
    base_currency_aggregate_eligible: boolean
    commercial_revenue_before_refund: string | null
    net_revenue: string | null
    known_cost: string | null
    current_stage_profit: string | null
    current_stage_margin: string | null
    posted_cost_count: number
    confirmed_cost_count: number
    estimated_cost_count: number
    required_cost_count: number
    p82_estimated_profit_base_ready: boolean
    p82_required_cost_count: number
    p82_required_cost_covered_count: number
    p82_missing_required_costs: string[]
    canonical_estimated_ready: boolean
    canonical_estimated_total_cost: string | null
    canonical_estimated_cost_breakdown: {
        product_cost: string
        logistics_cost: string
        other_cost: string
    }
    canonical_estimated_profit: string | null
    canonical_estimated_margin: string | null
    posted_estimated_cost_count: number
    fallback_estimated_cost_count: number
    projection_confidence: string
    total_cost: string | null
    contribution_profit: string | null
    margin: string | null
    cost_breakdown: CostBreakdown
}

type ProductRef = {
    product_id: number | null
    sku: string | null
    name: string | null
}

type Row = {
    id: number
    erp_order_id: string | null
    external_order_number: string | null
    order_date: string | null
    currency: string
    source_type: string
    country: string | null
    recipient_name: string | null
    products: ProductRef[]
    revenue_provenance: string | null
    provider_order_status: string | null
    provider_reconciliation_status: string | null
    paid_at: string | null
    summary: Summary
    values: Record<string, string | null>
}

type Pagination = {
    current_page: number
    last_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}

type ColumnKey =
    | 'order_number'
    | 'date'
    | 'source'
    | 'country'
    | 'products'
    | 'currency'
    | 'revenue'
    | 'refund'
    | 'net_revenue'
    | 'product_cost'
    | 'shipping_cost'
    | 'other_cost'
    | 'total_cost'
    | 'profit'
    | 'final_profit'
    | 'margin'
    | 'completeness'
    | 'missing'

type Preference = GridPreferenceValue<ColumnKey>

type Kpis = {
    eligible_orders: number
    revenue_ready_orders: number
    estimated_profit_orders: number
    estimated_profit_percent: number
    p82_required_cost_ready_orders: number
    p82_required_cost_percent: number
    confirmed_profit_orders: number
    incomplete_orders: number
    completeness_percent: number
    final_profit_percent_of_revenue_ready: number
    fx_pending_orders: number
}

const props = defineProps<{
    orders: Row[]
    pagination: Pagination
    fields: string[]
    filters: Record<string, string>
    kpis: Kpis
    gridPreferenceNamespace: string
    gridPreference: Preference | null
}>()

const definitions: GridColumnDefinition<ColumnKey>[] = [
    { key: 'order_number', label: 'ERP订单号' },
    { key: 'date', label: '日期' },
    { key: 'source', label: '来源' },
    { key: 'country', label: '国家' },
    { key: 'products', label: 'SKU / 产品' },
    { key: 'currency', label: '币种' },
    { key: 'revenue', label: '销售额' },
    { key: 'refund', label: '退款' },
    { key: 'net_revenue', label: '净收入' },
    { key: 'product_cost', label: '产品成本' },
    { key: 'shipping_cost', label: '物流成本' },
    { key: 'other_cost', label: '其他成本' },
    { key: 'total_cost', label: '预估总成本' },
    { key: 'profit', label: '预估利润' },
    { key: 'final_profit', label: '最终确认利润' },
    { key: 'margin', label: '预估 Margin' },
    { key: 'completeness', label: '完整性' },
    { key: 'missing', label: '缺失项' },
]

const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({
    namespace: props.gridPreferenceNamespace,
    definitions,
    requiredKeys: ['order_number'],
    serverValue: props.gridPreference,
})

const editing = ref<number | null>(null)
const editingRow = computed(() => props.orders.find(row => row.id === editing.value) ?? null)
const filters = reactive({
    search: props.filters.search ?? '',
    source_type: props.filters.source_type ?? '',
    country: props.filters.country ?? '',
    product: props.filters.product ?? props.filters.sku ?? '',
    fx_status: props.filters.fx_status ?? '',
    country_missing: props.filters.country_missing ?? '',
    product_missing: props.filters.product_missing ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    completeness: props.filters.completeness ?? '',
    profit_status: props.filters.profit_status ?? '',
    sort: props.filters.sort ?? 'order_date',
    direction: props.filters.direction ?? 'desc',
})

const missingLabels: Record<string, string> = {
    settlement_components: '收入结算事实',
    merchandise_list_amount: '商品收入',
    discount_amount: '折扣',
    shipping_revenue: '运费收入',
    refund_amount: '退款',
    provider_order_total: 'Woo 订单总额',
    tax_collected: '已收税额',
    payment_settlement: '付款结算证据',
    provider_order_status_not_revenue_eligible: '订单尚未进入可确认收入状态',
    provider_reconciliation: 'Woo 收入对账',
    revenue_provenance: '收入来源',
    product_cost: '产品成本',
    warehouse_cost: '仓库成本',
    first_mile_cost: '头程成本',
    overseas_handling_cost: '海外处理成本',
    last_mile_cost: '尾程成本',
    kol_commission: 'KOL / Affiliate 佣金',
    payment_fee: '支付手续费',
    customs_tax: '关税 / 税费成本',
    refund_replacement_loss: '退款 / 补发损失',
    other_variable_cost: '其他变动成本',
    transaction_fx_snapshot: 'USD 汇总 FX 快照',
}

const query = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
const submit = () => router.get('/accounting/order-costs', query(), { preserveState: true, replace: true })
const exportCsv = () => {
    window.location.href = `/accounting/order-costs/export?${new URLSearchParams(query() as Record<string, string>)}`
}
const edit = (row: Row) => { editing.value = row.id }

onMounted(() => {
    const orderId = Number(props.filters.edit_order)
    if (!Number.isSafeInteger(orderId) || orderId <= 0) return
    const row = props.orders.find(candidate => candidate.id === orderId)
    if (row) edit(row)
})

const orderPath = (row: Row) => `/customers/orders/${row.id}`
const productPath = (product: ProductRef) => product.product_id ? `/products/${product.product_id}` : null
const orderNumber = (row: Row) => row.erp_order_id ?? '—'
const money = (value: string | null, row: Row) => value === null ? '—' : `${row.currency} ${value}`
const margin = (value: string | null) => value === null ? '—' : `${(Number(value) * 100).toFixed(2)}%`
const statusLabel = (status: string) => ({
    UNPOSTED: '未入账',
    INCOMPLETE: '不完整',
    ESTIMATED: '估算',
    CONFIRMED: '已确认',
    RECONCILED: '已对账',
}[status] ?? status)
const missingText = (row: Row) => {
    const all = [...row.summary.missing_facts, ...row.summary.aggregation_missing_facts]
    return all.length ? all.map(item => missingLabels[item] ?? item.replace(/_/g, ' ')).join('、') : '—'
}
const cell = (row: Row, key: Exclude<ColumnKey, 'products'>) => ({
    order_number: orderNumber(row),
    date: row.order_date ?? '—',
    source: row.source_type,
    country: row.country ?? '—',
    currency: row.currency,
    revenue: money(row.summary.commercial_revenue_before_refund, row),
    refund: money(row.values.refund_amount ?? null, row),
    net_revenue: money(row.summary.net_revenue, row),
    product_cost: money(row.summary.canonical_estimated_cost_breakdown.product_cost, row),
    shipping_cost: money(row.summary.canonical_estimated_cost_breakdown.logistics_cost, row),
    other_cost: money(row.summary.canonical_estimated_cost_breakdown.other_cost, row),
    total_cost: money(row.summary.canonical_estimated_total_cost, row),
    profit: money(row.summary.canonical_estimated_profit, row),
    final_profit: row.summary.profit_status === 'confirmed' ? money(row.summary.contribution_profit, row) : '待确认',
    margin: margin(row.summary.canonical_estimated_margin),
    completeness: `预估 ${row.summary.canonical_estimated_ready ? '已计算' : '待收入'} · fallback ${row.summary.fallback_estimated_cost_count}/10；最终 ${statusLabel(row.summary.finance_status)} · ${row.summary.confirmed_cost_count}/${row.summary.required_cost_count}`,
    missing: missingText(row),
}[key])
</script>

<template>
    <Head title="订单利润 · BYL ERP" />
    <AuthenticatedLayout title="订单利润" :breadcrumbs="['BYL ERP', '会计', '订单利润']">
        <section class="mvp-page">
            <div class="mvp-page-heading">
                <div>
                    <p class="mvp-kicker">FINANCE / ORDER PROFIT</p>
                    <h2>订单利润</h2>
                    <p>收入一旦就绪就先计算预估利润：真实成本优先，缺失项使用只读 fallback 估算且不写入账本；真实证据逐项替换估算，10/10 成本全部确认后自动升级为最终利润。</p>
                </div>
                <div class="mvp-form-actions page-header-actions">
                    <button class="mvp-button" @click="exportCsv">导出 CSV</button>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card"><span>运营订单</span><strong>{{ kpis.eligible_orders }}</strong></div>
                <div class="kpi-card"><span>收入就绪</span><strong>{{ kpis.revenue_ready_orders }}</strong></div>
                <div class="kpi-card"><span>已有预估利润</span><strong>{{ kpis.estimated_profit_orders }}</strong><small>{{ kpis.estimated_profit_percent }}% · 收入就绪即计算</small></div>
                <div class="kpi-card"><span>6 项成本已有直接证据</span><strong>{{ kpis.p82_required_cost_ready_orders }}</strong><small>{{ kpis.p82_required_cost_percent }}% · 其余使用 fallback</small></div>
                <div class="kpi-card"><span>最终利润已确认</span><strong>{{ kpis.confirmed_profit_orders }}</strong><small>{{ kpis.final_profit_percent_of_revenue_ready }}% · 10/10 实际确认</small></div>
                <div class="kpi-card"><span>USD 汇总待 FX</span><strong>{{ kpis.fx_pending_orders }}</strong></div>
            </div>

            <div class="profit-formula-strip">
                <div><strong>当前阶段利润</strong><span>= 净收入 − 当前已入账成本</span></div>
                <div><strong>最终确认利润</strong><span>= 净收入 − 产品成本 − 仓库成本 − 头程成本 − 海外仓操作费 − 尾程物流 − 支付手续费 − KOL佣金 − 关税 − 退款/补发损失 − 其他变动成本</span></div>
                <p>成本缺失不会阻断利润计算：系统先给 projection-only 预估值；真实成本逐项到账后自动替换，全部确认后显示最终利润。</p>
            </div>

            <form class="mvp-toolbar filter-grid" @submit.prevent="submit">
                <input v-model="filters.search" aria-label="搜索订单" placeholder="订单号 / 收件人 / SKU">
                <select v-model="filters.source_type" aria-label="来源">
                    <option value="">全部来源</option>
                    <option value="manual">manual</option>
                    <option value="woocommerce">woocommerce</option>
                    <option value="alibaba">alibaba</option>
                    <option value="shopify">shopify</option>
                    <option value="email">email</option>
                    <option value="whatsapp">whatsapp</option>
                    <option value="api">api</option>
                    <option value="other">other</option>
                </select>
                <input v-model="filters.country" aria-label="国家" placeholder="国家代码，如 US">
                <input v-model="filters.product" aria-label="产品或 SKU" placeholder="SKU / 产品名称">
                <input v-model="filters.date_from" aria-label="开始日期" type="date">
                <input v-model="filters.date_to" aria-label="结束日期" type="date">
                <select v-model="filters.fx_status" aria-label="USD 汇总 FX 状态">
                    <option value="">全部 FX 状态</option>
                    <option value="aggregate_ready">USD 汇总可用</option>
                    <option value="pending">FX 待处理</option>
                </select>
                <select v-model="filters.completeness" aria-label="完整性">
                    <option value="">全部财务状态</option>
                    <option value="UNPOSTED">未入账</option>
                    <option value="INCOMPLETE">不完整</option>
                    <option value="ESTIMATED">估算</option>
                    <option value="CONFIRMED">已确认</option>
                    <option value="RECONCILED">已对账</option>
                </select>
                <select v-model="filters.profit_status" aria-label="利润状态">
                    <option value="">全部利润状态</option>
                    <option value="loss">亏损订单</option>
                    <option value="confirmed">已确认利润</option>
                    <option value="not_confirmed">未确认利润</option>
                </select>
                <select v-model="filters.sort" aria-label="排序字段">
                    <option value="order_date">按日期</option>
                    <option value="order_number">按订单号</option>
                    <option value="source">按来源</option>
                    <option value="currency">按币种</option>
                </select>
                <select v-model="filters.direction" aria-label="排序方向">
                    <option value="desc">降序</option>
                    <option value="asc">升序</option>
                </select>
                <button class="mvp-button" type="submit">筛选</button>
            </form>

            <div class="grid-toolbar"><span>共 {{ pagination.total }} 条运营订单事实</span></div>

            <div class="mvp-table-wrap order-profit-grid">
                <table class="mvp-table">
                    <thead>
                        <tr>
                            <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th>
                            <th class="action-column">操作</th>
                            <ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in orders" :key="row.id">
                            <tr>
                                <td v-for="column in visibleColumns" :key="column.key">
                                    <template v-if="column.key === 'order_number'"><Link class="record-link" :href="orderPath(row)">{{ orderNumber(row) }}</Link></template>
                                    <template v-if="column.key === 'products'">
                                        <div v-if="row.products.length" class="product-links">
                                            <template v-for="product in row.products" :key="`${product.product_id}-${product.sku}-${product.name}`">
                                                <Link v-if="productPath(product)" class="record-link" :href="productPath(product) as string">
                                                    {{ product.sku || product.name || `Product #${product.product_id}` }}
                                                </Link>
                                                <span v-else>{{ product.sku || product.name || '未关联产品' }}</span>
                                            </template>
                                        </div>
                                        <span v-else class="missing">—</span>
                                    </template>
                                    <span
                                        v-else
                                        :class="{
                                            missing: cell(row, column.key as Exclude<ColumnKey, 'products'>) === '—',
                                            'status-pill': column.key === 'completeness',
                                            [`status-${row.summary.finance_status.toLowerCase()}`]: column.key === 'completeness',
                                        }"
                                    >
                                        {{ cell(row, column.key as Exclude<ColumnKey, 'products'>) }}
                                    </span>
                                </td>
                                <td class="action-column">
                                    <div class="row-actions">
                                        <Link class="mvp-button" :href="`/accounting/order-costs/${row.id}`">成本公式</Link>
                                        <button class="mvp-button" @click="edit(row)">{{ editing === row.id ? '查看中' : '订单事实' }}</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!orders.length">
                            <td :colspan="visibleColumns.length + 3" class="mvp-empty">暂无符合条件的订单利润事实</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <FinanceFactsDrawer :order="editingRow" :fields="fields" @close="editing = null" />

            <nav v-if="pagination.last_page > 1" class="pagination" aria-label="分页">
                <Link
                    v-for="link in pagination.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    :class="{ active: link.active, disabled: !link.url }"
                    preserve-scroll
                    v-html="link.label"
                />
            </nav>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
.profit-formula-strip { margin: 0 0 16px; padding: 13px 15px; border: 1px solid #dfe7ea; border-radius: 8px; background: #f7faff; display: grid; gap: 7px; }
.profit-formula-strip div { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 10px; align-items: start; font-size: 12px; color: #40555c; }
.profit-formula-strip strong { color: #18282d; }
.profit-formula-strip p { margin: 2px 0 0; color: #71848b; font-size: 11px; }
.kpi-card { border: 1px solid #e1e8e8; background: #f7f9f9; border-radius: 8px; padding: 14px; display: grid; gap: 4px; }
.kpi-card span { color: #64757a; font-size: 12px; }
.kpi-card strong { font-size: 22px; color: #18282d; }
.filter-grid { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)); gap: 8px; }
.grid-toolbar { display: flex; justify-content: space-between; align-items: center; margin: 14px 0 8px; }
.column-settings { position: relative; }
.column-trigger { min-width: 38px; font-weight: 700; }
.column-menu { position: absolute; right: 0; z-index: 20; min-width: 220px; max-height: 420px; overflow: auto; background: #fff; border: 1px solid #d8e0e3; border-radius: 8px; padding: 10px; box-shadow: 0 10px 28px rgb(20 33 38 / 14%); }
.column-menu p { margin: 0 0 6px; font-size: 12px; color: #65777c; }
.column-menu label { display: flex; gap: 8px; align-items: center; padding: 5px; cursor: grab; }
.column-menu button { margin-top: 8px; }
.order-profit-grid { overflow-x: auto; }
.order-profit-grid .mvp-table { min-width: 1780px; }
.identity-column { position: sticky; left: 0; z-index: 3; min-width: 150px; background: #fff; }
.action-column { position: sticky; right: 0; z-index: 3; min-width: 120px; background: #fff; }
thead .identity-column, thead .action-column { z-index: 4; }
.record-link { color: #1b5f72; font-weight: 600; text-decoration: none; }
.record-link:hover { text-decoration: underline; }
.product-links { display: flex; flex-direction: column; gap: 4px; min-width: 130px; }
.missing { color: #8a989c; }
.status-pill { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; background: #eef2f3; white-space: nowrap; }
.status-confirmed, .status-reconciled { background: #e7f5ec; }
.status-estimated { background: #fff4d6; }
.status-incomplete, .status-unposted { background: #f2f3f4; }
.row-actions { display: flex; justify-content: flex-end; gap: 8px; }
.pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
.pagination a { padding: 5px 9px; border: 1px solid #d8e0e3; border-radius: 4px; }
.pagination a.active { background: #18282d; color: #fff; }
.pagination a.disabled { opacity: .45; pointer-events: none; }
@media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
@media (max-width: 640px) { .kpi-grid, .filter-grid { grid-template-columns: 1fr; } }
</style>
