<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
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
    | 'margin'
    | 'completeness'
    | 'missing'

type Preference = GridPreferenceValue<ColumnKey>

type Kpis = {
    eligible_orders: number
    confirmed_profit_orders: number
    incomplete_orders: number
    completeness_percent: number
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
    { key: 'total_cost', label: '总成本' },
    { key: 'profit', label: '利润' },
    { key: 'margin', label: 'Margin' },
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
const editValues = reactive<Record<string, string>>({})
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

const fieldLabels: Record<string, string> = {
    merchandise_list_amount: '商品原价合计',
    discount_amount: '折扣',
    shipping_revenue: '向客户收取运费',
    amount_paid: '实付金额（旧/手工）',
    refund_amount: '退款',
    product_cost: '产品成本',
    warehouse_cost: '仓库成本',
    first_mile_cost: '头程成本',
    overseas_handling_cost: '海外处理成本',
    last_mile_cost: '尾程 / 配送成本',
    kol_commission: 'KOL / Affiliate 佣金',
    payment_fee: '支付手续费',
    customs_tax: '关税 / 税费成本',
    refund_replacement_loss: '退款 / 补发损失',
    other_variable_cost: '其他变动成本',
}

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

const revenueFields = ['merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount']
const costFields = props.fields.filter(field => !revenueFields.includes(field))

const query = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
const submit = () => router.get('/accounting/order-costs', query(), { preserveState: true, replace: true })
const exportCsv = () => {
    window.location.href = `/accounting/order-costs/export?${new URLSearchParams(query() as Record<string, string>)}`
}
const edit = (row: Row) => {
    editing.value = row.id
    for (const field of props.fields) editValues[field] = row.values[field] ?? ''
}
const save = (row: Row) => router.put(`/accounting/order-costs/${row.id}`, editValues, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null },
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
    product_cost: money(row.summary.cost_breakdown.product_cost, row),
    shipping_cost: money(row.summary.cost_breakdown.logistics_cost, row),
    other_cost: money(row.summary.cost_breakdown.other_cost, row),
    total_cost: money(row.summary.total_cost, row),
    profit: row.summary.profit_status === 'estimated'
        ? `≈ ${money(row.summary.contribution_profit, row)}`
        : money(row.summary.contribution_profit, row),
    margin: margin(row.summary.margin),
    completeness: statusLabel(row.summary.finance_status),
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
                    <p>只显示有来源的事实；成本缺失不会自动补 0，也不会生成 confirmed profit。</p>
                </div>
                <div class="mvp-form-actions page-header-actions">
                    <button class="mvp-button" @click="exportCsv">导出 CSV</button>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card"><span>运营订单</span><strong>{{ kpis.eligible_orders }}</strong></div>
                <div class="kpi-card"><span>确认利润</span><strong>{{ kpis.confirmed_profit_orders }}</strong></div>
                <div class="kpi-card"><span>未确认 / 不完整</span><strong>{{ kpis.incomplete_orders }}</strong></div>
                <div class="kpi-card"><span>利润完整率</span><strong>{{ kpis.completeness_percent }}%</strong></div>
                <div class="kpi-card"><span>USD 汇总待 FX</span><strong>{{ kpis.fx_pending_orders }}</strong></div>
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
                                    <template v-if="column.key === 'order_number'"><Link class="record-link" :href="orderPath(row)">{{ orderNumber(row) }}</Link><small v-if="row.external_order_number">来源订单：{{ row.external_order_number }}</small></template>
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
                                    <button class="mvp-button" @click="edit(row)">
                                        {{ editing === row.id ? '编辑中' : '事实 / 成本' }}
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!orders.length">
                            <td :colspan="visibleColumns.length + 3" class="mvp-empty">暂无符合条件的订单利润事实</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Teleport to="body">
                <div v-if="editingRow" class="finance-drawer-backdrop" @click.self="editing = null">
                    <aside class="finance-drawer" aria-label="订单财务事实编辑" role="dialog" aria-modal="true">
                        <div class="finance-editor-header">
                            <div>
                                <p class="finance-editor-kicker">财务事实 / 成本</p>
                                <h3>{{ orderNumber(editingRow) }}</h3>
                            </div>
                            <button class="finance-drawer-close" type="button" aria-label="关闭" title="关闭" @click="editing = null">×</button>
                        </div>

                        <div class="finance-drawer-scroll">
                            <div class="fact-meta">
                                <span>收入来源：<b>{{ editingRow.revenue_provenance || '未入账' }}</b></span>
                                <span>Woo 状态：<b>{{ editingRow.provider_order_status || '—' }}</b></span>
                                <span>Woo 对账：<b>{{ editingRow.provider_reconciliation_status || '—' }}</b></span>
                                <span>付款时间：<b>{{ editingRow.paid_at || '—' }}</b></span>
                            </div>
                            <p class="editor-note">手工修改收入字段会明确切换为 confirmed_manual；只补成本且收入值未变化时，会保留 Woo provider_import 来源。</p>

                            <div class="finance-editor-columns">
                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">收入事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in revenueFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>

                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">成本事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in costFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div class="row-actions finance-editor-actions">
                            <button class="mvp-button" type="button" @click="editing = null">取消</button>
                            <button class="mvp-button mvp-button-primary" type="button" @click="save(editingRow)">保存财务事实</button>
                        </div>
                    </aside>
                </div>
            </Teleport>

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
.finance-drawer-backdrop { position: fixed; inset: 0; z-index: 1000; background: rgb(18 31 36 / 26%); }
.finance-drawer { position: absolute; top: 0; right: 0; width: min(780px, 94vw); height: 100vh; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: #fff; border-left: 1px solid #cfdadd; box-shadow: -14px 0 36px rgb(20 33 38 / 18%); }
.finance-editor-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 18px; border-bottom: 1px solid #dfe6e8; background: #f7f9f9; }
.finance-editor-header h3 { margin: 2px 0 0; font-size: 18px; color: #18282d; }
.finance-editor-kicker { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .08em; color: #65777c; }
.finance-drawer-close { width: 34px; height: 34px; padding: 0; border: 1px solid #c9d4d7; border-radius: 6px; background: #fff; color: #40545a; font-size: 24px; line-height: 30px; cursor: pointer; }
.finance-drawer-close:hover { border-color: #879aa0; background: #f1f5f5; color: #18282d; }
.finance-drawer-scroll { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.fact-meta { display: flex; flex-wrap: wrap; gap: 8px 24px; padding: 14px 18px 0; font-size: 12px; color: #5f7075; }
.editor-note { margin: 8px 18px 14px; color: #5f7075; font-size: 12px; }
.finance-editor-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: 14px; padding: 0 18px 18px; }
.finance-editor-column { min-width: 0; border: 1px solid #d8e0e3; border-radius: 6px; overflow: hidden; background: #fff; }
.finance-editor-section-title { padding: 10px 12px; border-bottom: 1px solid #d8e0e3; background: #eef3f4; font-size: 13px; font-weight: 700; color: #24383e; text-align: left; }
.finance-editor-table { display: grid; }
.finance-editor-field { display: grid; grid-template-columns: minmax(120px, 42%) minmax(110px, 1fr); align-items: center; min-height: 48px; margin: 0; border-bottom: 1px solid #e6ecee; background: #fff; }
.finance-editor-field:last-child { border-bottom: 0; }
.finance-editor-field > span { align-self: stretch; display: flex; align-items: center; padding: 9px 10px; border-right: 1px solid #e6ecee; background: #fafbfb; color: #40545a; font-size: 12px; text-align: left; }
.finance-editor-field input { width: calc(100% - 16px); min-width: 0; height: 34px; margin: 6px 8px; padding: 6px 9px; border: 1px solid #9fb0b5; border-radius: 4px; background: #fff; color: #18282d; font: inherit; box-sizing: border-box; }
.finance-editor-field input:hover { border-color: #617a82; }
.finance-editor-field input:focus { outline: 2px solid rgba(27, 95, 114, .18); outline-offset: 0; border-color: #1b5f72; }
.row-actions { display: flex; justify-content: flex-end; gap: 8px; }
.finance-editor-actions { padding: 12px 18px; border-top: 1px solid #dfe6e8; background: #f7f9f9; }
.pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
.pagination a { padding: 5px 9px; border: 1px solid #d8e0e3; border-radius: 4px; }
.pagination a.active { background: #18282d; color: #fff; }
.pagination a.disabled { opacity: .45; pointer-events: none; }
@media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
@media (max-width: 720px) { .finance-drawer { width: 100vw; } .finance-editor-columns { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .kpi-grid, .filter-grid { grid-template-columns: 1fr; } }
</style>
