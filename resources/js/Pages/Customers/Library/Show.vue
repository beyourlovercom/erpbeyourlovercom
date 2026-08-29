<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Customer = {
    id: number
    customer_number: string
    name: string
    email: string | null
    phone: string | null
    country_code: string | null
    language_code: string | null
    source_label: string
    owner: { name: string } | null
    notes: string | null
    archived_at: string | null
    created_at: string | null
}
type Address = {
    name: string | null
    company: string | null
    phone: string | null
    country_code: string | null
    province_state: string | null
    city: string | null
    address_line_1: string | null
    address_line_2: string | null
    postal_code: string | null
} | null
type RevenueByCurrency = {
    currency: string
    revenue: number
    average_order_value: number
    order_count: number
}
type Metrics = {
    order_count: number
    first_order_date: string | null
    last_order_date: string | null
    revenue_by_currency: RevenueByCurrency[]
    shipment_count: number
}
type Order = {
    id: number
    number: string
    order_date: string | null
    status: string
    revenue: number
    currency: string
    shipment_status: string | null
}
type Shipment = {
    id: number
    order_number: string | null
    order_id: number
    carrier: string | null
    tracking_number: string | null
    shipped_at: string | null
    status: string | null
}

const props = defineProps<{
    customer: Customer
    billingAddress: Address
    shippingAddress: Address
    metrics: Metrics
    recentOrders: Order[]
    shipments: Shipment[]
    audit: { action: string; actor_id: number | null; created_at: string | null }[]
    canOperate: boolean
}>()

const money = (value: number, currency: string) =>
    new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)

const initials = computed(() => {
    const name = props.customer.name.trim()
    const parts = name.split(/\s+/)
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    return name.slice(0, 2).toUpperCase()
})

const archive = () => {
    if (window.confirm(`确认归档客户「${props.customer.name}」？`)) {
        router.post(`/customers/library/${props.customer.id}/archive`)
    }
}
const restore = () => {
    if (window.confirm(`确认恢复客户「${props.customer.name}」？`)) {
        router.post(`/customers/library/${props.customer.id}/restore`)
    }
}

function addressLines(addr: Address): string[] {
    if (!addr) return []
    const parts = [
        addr.name, addr.company, addr.phone,
        [addr.address_line_1, addr.address_line_2].filter(Boolean).join(', '),
        [addr.city, addr.province_state].filter(Boolean).join(', '),
        [addr.country_code, addr.postal_code].filter(Boolean).join(' '),
    ]
    return parts.filter((p): p is string => typeof p === 'string' && p.trim() !== '')
}
</script>
<template>
    <Head :title="`${customer.name} · BYL ERP`" />
    <AuthenticatedLayout title="客户档案" :breadcrumbs="['BYL ERP', '客户', '客户库', customer.name]">
        <div class="erp-form-page">
            <div class="erp-form-content">

                <!-- Page header + action zone -->
                <div class="erp-form-header-row">
                    <div>
                        <p class="erp-form-subtitle" style="margin-bottom: 4px;">{{ customer.customer_number }}</p>
                        <h1 class="erp-form-title">{{ customer.name }}</h1>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <Link class="erp-btn erp-btn-secondary" href="/customers/library">返回列表</Link>
                        <button v-if="canOperate && customer.archived_at" class="erp-btn erp-btn-secondary" type="button" @click="restore">恢复</button>
                        <button v-if="canOperate && !customer.archived_at" class="erp-btn erp-btn-danger" type="button" @click="archive">归档</button>
                        <Link v-if="canOperate && !customer.archived_at" class="erp-btn erp-btn-primary" :href="`/customers/library/${customer.id}/edit`">编辑</Link>
                    </div>
                </div>

                <!-- Profile hero -->
                <article class="erp-form-card profile-hero">
                    <div class="profile-avatar">{{ initials }}</div>
                    <div class="profile-details">
                        <h2 class="profile-name">{{ customer.name }}</h2>
                        <p class="profile-sub">{{ customer.customer_number }}<span v-if="customer.archived_at" class="archived-badge">已归档</span></p>
                        <div class="profile-meta-row">
                            <span v-if="customer.email">✉ {{ customer.email }}</span>
                            <span v-if="customer.phone">📞 {{ customer.phone }}</span>
                            <span v-if="customer.country_code">🌏 {{ customer.country_code }}</span>
                            <span v-if="customer.language_code">🗣 {{ customer.language_code }}</span>
                            <span>来源：{{ customer.source_label }}</span>
                            <span v-if="customer.owner">负责人：{{ customer.owner.name }}</span>
                        </div>
                        <p v-if="customer.notes" class="profile-notes">{{ customer.notes }}</p>
                    </div>
                </article>

                <!-- Six KPI cards -->
                <div class="c360-kpi-grid">
                    <!-- 1. 累计订单 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">累计订单</p>
                        <p class="kpi-value">{{ metrics.order_count }}</p>
                        <p class="kpi-sublabel">首购 {{ metrics.first_order_date ?? '—' }}</p>
                    </article>

                    <!-- 2. 累计收入（按币种） -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">累计消费</p>
                        <template v-if="metrics.revenue_by_currency.length === 0">
                            <p class="kpi-value kpi-muted">—</p>
                        </template>
                        <template v-else>
                            <div v-for="row in metrics.revenue_by_currency" :key="row.currency" class="kpi-currency-block">
                                <p class="kpi-value kpi-value-md">{{ money(row.revenue, row.currency) }}</p>
                                <p class="kpi-sublabel">{{ row.currency }}</p>
                            </div>
                        </template>
                    </article>

                    <!-- 3. 平均客单价（按币种） -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">平均客单价</p>
                        <template v-if="metrics.revenue_by_currency.length === 0">
                            <p class="kpi-value kpi-muted">—</p>
                        </template>
                        <template v-else>
                            <div v-for="row in metrics.revenue_by_currency" :key="row.currency" class="kpi-currency-block">
                                <p class="kpi-value kpi-value-md">{{ money(row.average_order_value, row.currency) }}</p>
                                <p class="kpi-sublabel">{{ row.currency }} · {{ row.order_count }} 笔</p>
                            </div>
                        </template>
                    </article>

                    <!-- 4. 最近购买 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">最近购买</p>
                        <p class="kpi-value kpi-date">{{ metrics.last_order_date ?? '—' }}</p>
                    </article>

                    <!-- 5. 发货次数 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">发货次数</p>
                        <p class="kpi-value">{{ metrics.shipment_count }}</p>
                    </article>

                    <!-- 6. 客服工单（暂不可用） -->
                    <article class="erp-form-card c360-kpi-card kpi-unavailable">
                        <p class="kpi-label">待处理工单</p>
                        <p class="kpi-value kpi-muted">—</p>
                        <p class="kpi-sublabel">Support 模块（M1.4）尚未集成</p>
                    </article>
                </div>

                <!-- 账单地址 / 收货地址 -->
                <div class="c360-address-grid">
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">账单地址</h2>
                        <template v-if="billingAddress">
                            <p v-for="line in addressLines(billingAddress)" :key="line" class="address-line">{{ line }}</p>
                        </template>
                        <p v-else class="address-empty">未设置账单地址</p>
                    </article>
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">收货地址</h2>
                        <template v-if="shippingAddress">
                            <p v-for="line in addressLines(shippingAddress)" :key="line" class="address-line">{{ line }}</p>
                        </template>
                        <p v-else class="address-empty">未设置收货地址</p>
                    </article>
                </div>

                <!-- 采购历史 -->
                <article class="erp-form-card">
                    <h2 class="erp-form-card-title">历史购买记录</h2>
                    <div class="mvp-table-wrap">
                        <table class="mvp-table">
                            <thead>
                                <tr><th>订单号</th><th>日期</th><th>金额</th><th>订单状态</th><th>物流状态</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="order in recentOrders" :key="order.id">
                                    <td>
                                        <Link :href="`/customers/orders/${order.id}`" class="mvp-link">{{ order.number }}</Link>
                                    </td>
                                    <td>{{ order.order_date ?? '—' }}</td>
                                    <td>{{ money(order.revenue, order.currency) }}</td>
                                    <td>{{ order.status }}</td>
                                    <td>{{ order.shipment_status ?? '—' }}</td>
                                </tr>
                                <tr v-if="recentOrders.length === 0">
                                    <td colspan="5" class="mvp-empty">暂无已明确关联的订单</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- 发货记录 -->
                <article class="erp-form-card">
                    <h2 class="erp-form-card-title">发货记录</h2>
                    <div class="mvp-table-wrap">
                        <table class="mvp-table">
                            <thead>
                                <tr><th>关联订单</th><th>承运商</th><th>追踪号</th><th>发货日期</th><th>状态</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in shipments" :key="s.id">
                                    <td>
                                        <Link v-if="s.order_number" :href="`/customers/orders/${s.order_id}`" class="mvp-link">{{ s.order_number }}</Link>
                                        <span v-else>—</span>
                                    </td>
                                    <td>{{ s.carrier ?? '—' }}</td>
                                    <td>{{ s.tracking_number ?? '—' }}</td>
                                    <td>{{ s.shipped_at ?? '—' }}</td>
                                    <td>{{ s.status ?? '—' }}</td>
                                </tr>
                                <tr v-if="shipments.length === 0">
                                    <td colspan="5" class="mvp-empty">暂无发货记录</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- 订阅 / 支持（暂不可用） -->
                <div class="c360-integration-grid">
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">订阅记录</h2>
                        <p class="integration-unavailable">Subscriber 模块（M1.3）尚未集成，暂无订阅数据。</p>
                    </article>
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">客户支持工单</h2>
                        <p class="integration-unavailable">Support 模块（M1.4）尚未集成，暂无工单数据。</p>
                    </article>
                </div>

                <!-- 操作审计 -->
                <article class="erp-form-card">
                    <h2 class="erp-form-card-title">操作审计</h2>
                    <ul class="c360-audit-list">
                        <li v-for="event in audit" :key="(event.created_at ?? '') + event.action">
                            <span class="audit-action">{{ event.action }}</span>
                            <span class="audit-meta">{{ event.created_at }} · 操作人 {{ event.actor_id ?? 'system' }}</span>
                        </li>
                        <li v-if="audit.length === 0" class="audit-empty">暂无审计记录</li>
                    </ul>
                </article>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
<style scoped>
/* Profile hero */
.profile-hero { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 16px; }
.profile-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    background: #2D8C84; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 700; flex-shrink: 0;
}
.profile-details { flex: 1; }
.profile-name { font-size: 1.3rem; font-weight: 700; color: #142126; margin: 0 0 2px; }
.profile-sub { font-size: 12px; color: #7A8C94; margin: 0 0 6px; }
.profile-meta-row { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; color: #344B53; margin-bottom: 6px; }
.profile-notes { font-size: 13px; color: #7A8C94; margin: 0; font-style: italic; }
.archived-badge {
    display: inline-block; margin-left: 8px;
    background: #FEF3CD; color: #856404;
    font-size: 10px; padding: 1px 6px; border-radius: 4px; vertical-align: middle;
}

/* KPI grid - 6 cards */
.c360-kpi-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.c360-kpi-card { display: flex; flex-direction: column; gap: 4px; }
.kpi-unavailable { opacity: 0.6; }
.kpi-label { font-size: 12px; color: #7A8C94; margin: 0; }
.kpi-value { font-size: 1.5rem; font-weight: 700; color: #142126; margin: 0; line-height: 1.2; }
.kpi-value-md { font-size: 1.1rem; }
.kpi-date { font-size: 1.0rem; }
.kpi-muted { font-size: 1rem; font-weight: 500; color: #7A8C94; }
.kpi-sublabel { font-size: 11px; color: #7A8C94; margin: 0; }
.kpi-currency-block { margin-top: 2px; }

/* Addresses */
.c360-address-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.address-line { font-size: 14px; color: #142126; margin: 2px 0; }
.address-empty { font-size: 13px; color: #7A8C94; font-style: italic; margin: 0; }

/* Integration panels */
.c360-integration-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.integration-unavailable { font-size: 13px; color: #7A8C94; font-style: italic; margin: 0; }

/* Audit */
.c360-audit-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.c360-audit-list li { display: flex; flex-direction: column; gap: 2px; }
.audit-action { font-size: 13px; color: #142126; font-weight: 500; }
.audit-meta { font-size: 11px; color: #7A8C94; }
.audit-empty { font-size: 13px; color: #7A8C94; font-style: italic; }

.erp-btn-danger { background: #D9534F; color: #fff; border-color: #D9534F; }
.erp-btn-danger:hover { background: #c9302c; }

@media (max-width: 1100px) { .c360-kpi-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) {
    .c360-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .c360-address-grid { grid-template-columns: 1fr; }
    .c360-integration-grid { grid-template-columns: 1fr; }
    .profile-hero { flex-direction: column; }
}
@media (max-width: 440px) { .c360-kpi-grid { grid-template-columns: 1fr; } }
</style>
