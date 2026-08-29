<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Customer = {
    id: number
    customer_number: string
    name: string
    email: string | null
    phone: string | null
    country_code: string | null
    source_label: string
    owner: { name: string } | null
    orders_count: number
    latest_order_date: string | null
    lifetime_revenue: { currency: string; revenue: number }[]
    archived_at: string | null
}

type Paginator = {
    data: Customer[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
    links: { url: string | null; label: string; active: boolean }[]
}

type RevenueRow = { currency: string; revenue: number }

type RmbConversion = {
    original_currency: string
    original_amount: number
    rate_to_cny: number
    cny_amount: number
}

type RmbEstimate =
    | { available: true; amount_cny: number; source: string; reference_date: string | null; stale: boolean; conversions: RmbConversion[] }
    | { available: false; reason: string }

type Stats = {
    total_customers: number
    new_this_month: number
    orders_this_month: number
    revenue_this_month: RevenueRow[]
    rmb_estimate: RmbEstimate
    subscriber_count_available: boolean
    open_support_tickets_available: boolean
}

const props = defineProps<{
    customers: Paginator
    filters: Record<string, string>
    sources: Record<string, string>
    stats: Stats
    canOperate: boolean
    canImport: boolean
    canExport: boolean
}>()

const COMPACT_KEY = 'customer_list_compact_mode'
const compact = ref(localStorage.getItem(COMPACT_KEY) === '1')

function toggleCompact() {
    compact.value = !compact.value
    localStorage.setItem(COMPACT_KEY, compact.value ? '1' : '0')
}

const filters = reactive({
    search: props.filters.search ?? '',
    country_code: props.filters.country_code ?? '',
    source_type: props.filters.source_type ?? '',
    archived: props.filters.archived ?? '',
})

const submit = () => router.get('/customers/library', { ...filters, page: 1 }, { preserveState: true, replace: true })

const restore = (customer: Customer) => {
    if (window.confirm(`确认恢复客户「${customer.name}」？`)) {
        router.post(`/customers/library/${customer.id}/restore`)
    }
}

const money = (value: number, currency: string) =>
    new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)

const cny = (value: number) =>
    new Intl.NumberFormat('zh-CN', {
        style: 'currency',
        currency: 'CNY',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value)

const salesKpiValue = computed((): string => {
    const est = props.stats.rmb_estimate
    if (!est.available) return '—'
    if (est.amount_cny === 0 && props.stats.revenue_this_month.length === 0) return '暂无数据'
    return cny(est.amount_cny)
})

const salesKpiTooltip = computed((): string => {
    const est = props.stats.rmb_estimate
    if (!est.available) return '无法获取人民币汇率估算'
    const breakdown = props.stats.revenue_this_month
        .map(r => money(r.revenue, r.currency))
        .join(' + ')
    const dateStr = est.reference_date ? `ECB ${est.reference_date}` : 'ECB'
    const staleNote = est.stale ? '（汇率数据已过期）' : ''
    return `原始: ${breakdown || '暂无'} | 来源: ${dateStr}${staleNote}`
})
</script>
<template>
    <Head title="客户库 · BYL ERP" />
    <AuthenticatedLayout title="客户库" :breadcrumbs="['BYL ERP', '客户', '客户库']">
        <div class="erp-form-page">
            <div class="erp-form-content">
                <div class="erp-form-header-row">
                    <div>
                        <h1 class="erp-form-title">客户库</h1>
                        <p class="erp-form-subtitle">一个客户一条主档；订单后续统一关联到 Customer ID。</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <button
                            class="erp-btn erp-btn-secondary compact-toggle"
                            type="button"
                            :title="compact ? '展开统计与筛选' : '收起统计与筛选'"
                            :aria-label="compact ? '展开统计与筛选' : '收起统计与筛选'"
                            @click="toggleCompact"
                        >↕️</button>
                        <button
                            v-if="canExport"
                            class="erp-btn erp-btn-secondary"
                            type="button"
                            disabled
                            title="客户导出功能（M1.8）尚在规划中，暂不可用"
                        >导出</button>
                        <button
                            v-if="canImport"
                            class="erp-btn erp-btn-secondary"
                            type="button"
                            disabled
                            title="客户导入功能（M1.8）尚在规划中，暂不可用"
                        >导入</button>
                        <Link v-if="canOperate" class="erp-btn erp-btn-primary" href="/customers/library/create">新建客户</Link>
                    </div>
                </div>

                <template v-if="!compact">
                    <!-- KPI Statistics — each card: label + value only (≤2 visible rows); details in title tooltip -->
                    <div class="customer-kpi-row" data-testid="kpi-strip">
                        <article class="erp-form-card customer-kpi-card">
                            <p class="kpi-label">客户总数</p>
                            <p class="kpi-value">{{ stats.total_customers }}</p>
                        </article>
                        <article class="erp-form-card customer-kpi-card">
                            <p class="kpi-label">本月新增</p>
                            <p class="kpi-value">{{ stats.new_this_month }}</p>
                        </article>
                        <article class="erp-form-card customer-kpi-card">
                            <p class="kpi-label">本月订单</p>
                            <p class="kpi-value">{{ stats.orders_this_month }}</p>
                        </article>
                        <article
                            class="erp-form-card customer-kpi-card"
                            :title="salesKpiTooltip"
                            :aria-label="`本月销售额: ${salesKpiValue}. ${salesKpiTooltip}`"
                        >
                            <p class="kpi-label">本月销售额</p>
                            <p class="kpi-value" :class="{ 'kpi-muted': !stats.rmb_estimate.available || stats.revenue_this_month.length === 0 }">{{ salesKpiValue }}</p>
                        </article>
                        <article
                            class="erp-form-card customer-kpi-card kpi-unavailable"
                            title="Subscriber 模块（M1.3）尚未集成，暂无数据"
                            aria-label="订阅者数: 暂不可用。Subscriber 模块（M1.3）尚未集成"
                        >
                            <p class="kpi-label">订阅者数</p>
                            <p class="kpi-value kpi-muted">—</p>
                        </article>
                        <article
                            class="erp-form-card customer-kpi-card kpi-unavailable"
                            title="Support 模块（M1.4）尚未集成，暂无数据"
                            aria-label="待处理工单: 暂不可用。Support 模块（M1.4）尚未集成"
                        >
                            <p class="kpi-label">待处理工单</p>
                            <p class="kpi-value kpi-muted">—</p>
                        </article>
                    </div>

                    <!-- Filter form — single horizontal row; overflow scrolls, never wraps -->
                    <form class="erp-form-card filter-row-form" @submit.prevent="submit">
                        <div class="filter-row-scroll" role="group" aria-label="筛选条件">
                            <div class="filter-row-field">
                                <label class="erp-form-label" for="filter-search">搜索</label>
                                <input id="filter-search" v-model="filters.search" class="erp-input filter-input" type="text" placeholder="姓名 / Email / 电话">
                            </div>
                            <div class="filter-row-field">
                                <label class="erp-form-label" for="filter-country">国家代码</label>
                                <input id="filter-country" v-model="filters.country_code" class="erp-input filter-input filter-input-sm" type="text" maxlength="2" placeholder="US">
                            </div>
                            <div class="filter-row-field">
                                <label class="erp-form-label" for="filter-source">来源</label>
                                <select id="filter-source" v-model="filters.source_type" class="erp-select filter-select">
                                    <option value="">全部来源</option>
                                    <option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <div class="filter-row-field">
                                <label class="erp-form-label" for="filter-archived">状态</label>
                                <select id="filter-archived" v-model="filters.archived" class="erp-select filter-select">
                                    <option value="">仅在用</option>
                                    <option value="only">仅已归档</option>
                                    <option value="all">全部</option>
                                </select>
                            </div>
                            <!-- M1.2 reserved: customer group filter (not yet implemented) -->
                            <div class="filter-row-field">
                                <label class="erp-form-label" for="filter-group">客户分组（M1.2）</label>
                                <select id="filter-group" class="erp-select filter-select" disabled title="客户分组筛选（M1.2）尚未实现">
                                    <option value="">全部分组</option>
                                </select>
                            </div>
                            <div class="filter-row-submit">
                                <span class="erp-form-label" aria-hidden="true">&nbsp;</span>
                                <button class="erp-btn erp-btn-primary" type="submit">筛选</button>
                            </div>
                        </div>
                    </form>
                </template>

                <div class="mvp-table-wrap">
                    <table class="mvp-table">
                        <thead>
                            <tr>
                                <th>客户编号 / 姓名</th>
                                <th>Email</th>
                                <th>电话</th>
                                <th>国家</th>
                                <th>来源</th>
                                <th>订单数</th>
                                <th>累计销售额</th>
                                <th>最近下单</th>
                                <th>负责人</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="customer in customers.data" :key="customer.id">
                                <td>
                                    <Link :href="`/customers/library/${customer.id}`" class="customer-name-link">{{ customer.name }}</Link>
                                    <br><span class="customer-number">{{ customer.customer_number }}</span>
                                    <span v-if="customer.archived_at" class="archived-badge">已归档</span>
                                </td>
                                <td>{{ customer.email ?? '—' }}</td>
                                <td>{{ customer.phone ?? '—' }}</td>
                                <td>{{ customer.country_code ?? '—' }}</td>
                                <td>{{ customer.source_label }}</td>
                                <td>{{ customer.orders_count }}</td>
                                <td class="td-revenue">
                                    <template v-if="customer.lifetime_revenue.length === 0">—</template>
                                    <template v-else>
                                        <span v-for="r in customer.lifetime_revenue" :key="r.currency" class="revenue-chip">
                                            {{ money(r.revenue, r.currency) }}
                                        </span>
                                    </template>
                                </td>
                                <td>{{ customer.latest_order_date ?? '—' }}</td>
                                <td>{{ customer.owner?.name ?? '—' }}</td>
                                <td>
                                    <Link :href="`/customers/library/${customer.id}`" class="erp-btn erp-btn-secondary table-action-btn">查看</Link>
                                    <button v-if="canOperate && customer.archived_at" class="erp-btn erp-btn-secondary table-action-btn" type="button" @click="restore(customer)">恢复</button>
                                </td>
                            </tr>
                            <tr v-if="customers.data.length === 0">
                                <td colspan="10" class="mvp-empty">暂无符合条件的客户</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="customers.last_page > 1" class="pagination-row">
                    <template v-for="link in customers.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="pagination-btn"
                            :class="{ 'pagination-btn-active': link.active }"
                            v-html="link.label"
                        />
                        <span v-else class="pagination-btn pagination-btn-disabled" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
<style scoped>
.customer-kpi-row {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.customer-kpi-card {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
    min-height: 58px;
    padding: 10px 14px;
    margin-bottom: 0;
}
.kpi-unavailable { opacity: 0.6; }
.kpi-label { font-size: 12px; color: #7A8C94; margin: 0; }
.kpi-value { font-size: 1.4rem; font-weight: 700; color: #142126; margin: 0; line-height: 1.2; }
.kpi-muted { font-size: 1rem; font-weight: 500; color: #7A8C94; }
.filter-row-form { margin-bottom: 16px; padding: 10px 14px; }
.filter-row-scroll {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 12px;
    overflow-x: auto;
    overflow-y: visible;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.filter-row-field { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
.filter-row-submit { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
.filter-input { min-width: 180px; }
.filter-input-sm { min-width: 80px; }
.filter-select { min-width: 140px; }
.compact-toggle { font-size: 16px; padding: 6px 10px; }
.customer-name-link { font-weight: 600; color: #142126; text-decoration: none; }
.customer-name-link:hover { text-decoration: underline; }
.customer-number { font-size: 11px; color: #7A8C94; }
.archived-badge {
    display: inline-block;
    margin-left: 6px;
    background: #FEF3CD;
    color: #856404;
    font-size: 10px;
    padding: 1px 6px;
    border-radius: 4px;
    vertical-align: middle;
}
.td-revenue { white-space: nowrap; }
.revenue-chip { display: block; font-size: 12px; color: #344B53; }
.table-action-btn { font-size: 12px; padding: 4px 10px; }
.pagination-row {
    display: flex;
    gap: 4px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 16px;
}
.pagination-btn {
    display: inline-block;
    padding: 6px 12px;
    border: 1px solid #E4EAED;
    border-radius: 8px;
    font-size: 13px;
    color: #344B53;
    text-decoration: none;
    background: #fff;
}
.pagination-btn-active { background: #2D8C84; color: #fff; border-color: #2D8C84; }
.pagination-btn-disabled { color: #7A8C94; cursor: not-allowed; }
@media (max-width: 1100px) { .customer-kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) { .customer-kpi-row { grid-template-columns: repeat(2, 1fr); } }
</style>
