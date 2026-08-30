<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type RevenueByCurrency = { currency: string; revenue: number }

const props = defineProps<{
    metrics: {
        total_customers: number
        new_this_month: number
        orders_this_month: number
        revenue_this_month: RevenueByCurrency[]
        subscriber_identity_links: number
    }
    canOperate: boolean
    canImport: boolean
    canExport: boolean
}>()

const money = (value: number, currency: string) =>
    new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)
</script>
<template>
    <Head title="客户总览 · BYL ERP" />
    <AuthenticatedLayout title="客户" :breadcrumbs="['BYL ERP', '客户总览']">
        <div class="erp-form-page">
            <div class="erp-form-content">
                <div class="erp-form-header-row">
                    <div>
                        <h1 class="erp-form-title">客户总览</h1>
                        <p class="erp-form-subtitle">Customer Master — 一个客户一条主档，订单按 Customer ID 明确关联。</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <button
                            v-if="canExport"
                            class="erp-btn erp-btn-secondary"
                            type="button"
                            disabled
                            title="客户导出功能（M1.8）尚在规划中，暂不可用"
                        >导出客户</button>
                        <button
                            v-if="canImport"
                            class="erp-btn erp-btn-secondary"
                            type="button"
                            disabled
                            title="客户导入功能（M1.8）尚在规划中，暂不可用"
                        >导入客户</button>
                        <Link v-if="canOperate" class="erp-btn erp-btn-primary" href="/customers/library/create">新建客户</Link>
                        <Link class="erp-btn erp-btn-secondary" href="/customers/library">客户列表</Link>
                    </div>
                </div>

                <div class="customer-kpi-grid">
                    <article class="erp-form-card customer-kpi-card">
                        <p class="kpi-label">客户总数</p>
                        <p class="kpi-value">{{ metrics.total_customers }}</p>
                    </article>
                    <article class="erp-form-card customer-kpi-card" :title="'按 Customer.email 与 Marketing Subscriber.email 精确匹配的身份链接数；不代表统一同意状态'">
                        <p class="kpi-label">Subscriber 匹配</p>
                        <p class="kpi-value">{{ metrics.subscriber_identity_links }}</p>
                    </article>
                    <article class="erp-form-card customer-kpi-card">
                        <p class="kpi-label">本月新增客户</p>
                        <p class="kpi-value">{{ metrics.new_this_month }}</p>
                    </article>
                    <article class="erp-form-card customer-kpi-card">
                        <p class="kpi-label">本月客户订单</p>
                        <p class="kpi-value">{{ metrics.orders_this_month }}</p>
                    </article>
                    <article class="erp-form-card customer-kpi-card">
                        <p class="kpi-label">本月客户收入</p>
                        <div v-if="metrics.revenue_this_month.length === 0" class="kpi-value kpi-muted">暂无数据</div>
                        <div v-for="row in metrics.revenue_this_month" :key="row.currency">
                            <p class="kpi-value">{{ money(row.revenue, row.currency) }}</p>
                            <p class="kpi-currency-label">{{ row.currency }}</p>
                        </div>
                    </article>
                </div>

                <div class="customer-integration-grid">
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">订阅者</h2>
                        <p class="integration-unavailable">已匹配 Marketing Subscriber 身份 {{ metrics.subscriber_identity_links }} 个；不推断全局同意状态。</p>
                    </article>
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">客户支持工单</h2>
                        <p class="integration-unavailable">Support 模块（M1.4）尚未集成，暂无数据。</p>
                    </article>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
<style scoped>
.customer-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.customer-kpi-card { display: flex; flex-direction: column; gap: 8px; }
.kpi-label { font-size: 13px; color: #7A8C94; margin: 0; }
.kpi-value { font-size: 1.75rem; font-weight: 700; color: #142126; margin: 0; line-height: 1.2; }
.kpi-currency-label { font-size: 11px; color: #7A8C94; margin: 0; }
.kpi-muted { font-size: 1rem; font-weight: 500; color: #7A8C94; }
.customer-integration-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.integration-unavailable { font-size: 13px; color: #7A8C94; font-style: italic; margin: 0; }
@media (max-width: 900px) { .customer-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .customer-kpi-grid { grid-template-columns: 1fr; } .customer-integration-grid { grid-template-columns: 1fr; } }
</style>
