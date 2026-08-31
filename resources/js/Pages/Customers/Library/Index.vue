<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import { useAccountGridPreferences, type GridColumnDefinition, type GridPreferenceValue } from '../../../Composables/useAccountGridPreferences'

type CustomerAddress = {
    country_code: string | null
    province_state: string | null
    city: string | null
    postal_code: string | null
}

type CustomerRevenue = {
    currency: string
    revenue: number
    order_count: number
    average_order_value: number
}

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
    lifetime_revenue: CustomerRevenue[]
    primary_address: CustomerAddress | null
    archived_at: string | null
    created_at: string | null
    subscription: { matched: boolean; subscription_facts: { provider: string | null; source_site: string | null; status: string | null }[]; subscription_statuses: string[] }
    shipment_count: number
}

type ColumnKey = 'email' | 'phone' | 'registered_at' | 'country_region' | 'city' | 'province_state' | 'postal_code' | 'source' | 'orders_count' | 'shipment_count' | 'total_spend' | 'aov' | 'latest_order' | 'owner' | 'status' | 'subscription'

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
    subscriber_identity_links: number
    pending_support_tickets?: number
}

const props = defineProps<{
    customers: Paginator
    filters: Record<string, string>
    sources: Record<string, string>
    stats: Stats
    canOperate: boolean
    canImport: boolean
    canExport: boolean
    workspace: 'library' | 'groups'
    gridPreferenceNamespace: string
    gridPreference: GridPreferenceValue<ColumnKey> | null
    manualGroups?: { id: number; name: string; description: string | null; member_count: number }[]
    selectedManualGroup?: { id: number; name: string; description: string | null; member_count: number } | null
}>()

const isGroupsWorkspace = computed(() => props.workspace === 'groups')
const workspaceTitle = computed(() => isGroupsWorkspace.value ? '客户分组' : '客户列表')
const workspaceSubtitle = computed(() => isGroupsWorkspace.value
    ? '按系统动态分组查看 Customer；列显示与顺序绑定当前账号，并在不同设备同步。'
    : '统一查看和筛选 Customer 主档；订单只通过明确 Customer ID 关联。')
const workspaceBasePath = computed(() => isGroupsWorkspace.value ? '/customers/groups' : '/customers/library')
const manualGroups = computed(() => props.manualGroups ?? [])
const selectedManualGroup = computed(() => props.selectedManualGroup ?? null)

const groupShortcuts = [
    { key: '', label: '全部客户' },
    { key: 'no_purchase', label: '无购买客户' },
    { key: 'new_customer', label: '新客户' },
    { key: 'repeat_customer', label: '复购客户' },
    { key: 'multi_product_single_order', label: '单次多产品客户' },
    { key: 'has_shipment', label: '已发货客户' },
    { key: 'no_shipment_yet', label: '尚未发货客户' },
    { key: 'active_customer', label: '活跃客户' },
    { key: 'churn_risk', label: '流失风险客户' },
    { key: 'dormant_customer', label: '沉睡客户' },
    { key: 'high_frequency', label: '高频客户' },
    { key: 'high_value', label: '高价值客户' },
    { key: 'vip', label: 'VIP客户' },
]

const groupHref = (group: string): string => {
    const params = new URLSearchParams()
    if (filters.search) params.set('search', filters.search)
    if (filters.country_code) params.set('country_code', filters.country_code)
    if (filters.source_type) params.set('source_type', filters.source_type)
    if (filters.archived) params.set('archived', filters.archived)
    if (group) params.set('group', group)
    const query = params.toString()
    return `${workspaceBasePath.value}${query ? `?${query}` : ''}`
}

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
    group: props.filters.group ?? '',
})

const submit = () => router.get(workspaceBasePath.value, { ...filters, page: 1 }, { preserveState: true, replace: true })

const newGroupName = ref('')
const newGroupDescription = ref('')
const renameGroupName = ref('')
const renameOpen = ref(false)
const memberSearch = ref('')
const memberSearchResults = ref<{ id: number; name: string; email: string | null; phone: string | null }[]>([])
const selectedMemberIds = ref<number[]>([])

const createGroup = () => {
    if (!newGroupName.value.trim()) return
    router.post('/customers/groups', { name: newGroupName.value, description: newGroupDescription.value })
}

const renameGroup = () => {
    if (!selectedManualGroup.value || !renameGroupName.value.trim()) return
    router.patch(`/customers/groups/${selectedManualGroup.value.id}`, { name: renameGroupName.value, description: selectedManualGroup.value.description })
    renameOpen.value = false
}

const beginRename = () => {
    if (!selectedManualGroup.value) return
    renameGroupName.value = selectedManualGroup.value.name
    renameOpen.value = true
}

const deleteGroup = () => {
    if (selectedManualGroup.value && window.confirm(`确认删除客户分组「${selectedManualGroup.value.name}」？客户不会被删除。`)) {
        router.delete(`/customers/groups/${selectedManualGroup.value.id}`)
    }
}

const searchGroupCustomers = async () => {
    if (!selectedManualGroup.value || !memberSearch.value.trim()) return
    const response = await axios.get(`/customers/groups/${selectedManualGroup.value.id}/customers/search`, { params: { search: memberSearch.value } })
    memberSearchResults.value = response.data.customers
}

const addMembers = () => {
    if (!selectedManualGroup.value || selectedMemberIds.value.length === 0) return
    router.post(`/customers/groups/${selectedManualGroup.value.id}/members`, { customer_ids: selectedMemberIds.value })
}

const removeMember = (customer: Customer) => {
    if (selectedManualGroup.value && window.confirm(`确认将「${customer.name}」移出当前分组？`)) {
        router.delete(`/customers/groups/${selectedManualGroup.value.id}/members/${customer.id}`)
    }
}

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

const defaultColumns: GridColumnDefinition<ColumnKey>[] = [
    { key: 'email', label: 'Email' },
    { key: 'phone', label: '电话' },
    { key: 'registered_at', label: '注册日期' },
    { key: 'country_region', label: '国家 / 地区' },
    { key: 'city', label: '城市' },
    { key: 'province_state', label: '州 / 省' },
    { key: 'postal_code', label: '邮编' },
    { key: 'source', label: '来源' },
    { key: 'orders_count', label: '订单数' },
    { key: 'shipment_count', label: 'Shipment count', defaultVisible: false },
    { key: 'total_spend', label: '累计销售额' },
    { key: 'aov', label: 'AOV' },
    { key: 'latest_order', label: '最近下单' },
    { key: 'owner', label: '负责人' },
    { key: 'status', label: '状态' },
    { key: 'subscription', label: '订阅', defaultVisible: false },
]

const columnSettingsOpen = ref(false)
const {
    columns: columnPreferences,
    visibleColumns: visibleGroupColumns,
    syncState: columnPreferenceSyncState,
    toggle: toggleColumn,
    startDrag: startColumnDrag,
    drop: dropColumn,
    reset: resetColumns,
} = useAccountGridPreferences<ColumnKey>({
    namespace: props.gridPreferenceNamespace,
    definitions: defaultColumns,
    serverValue: props.gridPreference,
    legacyCacheKeys: ['customer_groups_grid_columns_v1', 'customer_list_grid_columns_v1'],
})

const dateOnly = (value: string | null) => value ? value.slice(0, 10) : '—'
const customerCountry = (customer: Customer) => customer.country_code ?? customer.primary_address?.country_code ?? '—'
const subscriptionSummary = (customer: Customer): string => {
    if (!customer.email) return '无 Email'
    if (!customer.subscription.matched) return '未匹配 Subscriber'
    if (customer.subscription.subscription_facts.length === 0) return '已匹配身份 · 暂无订阅事实'
    return customer.subscription.subscription_facts.map(fact => {
        const source = [fact.provider, fact.source_site].filter(Boolean).join(' · ') || '未知来源'
        return `${source}: ${fact.status ?? 'unknown'}`
    }).join(' | ')
}
</script>
<template>
    <Head :title="`${workspaceTitle} · BYL ERP`" />
    <AuthenticatedLayout :title="workspaceTitle" :breadcrumbs="['BYL ERP', '客户', workspaceTitle]">
        <div class="erp-form-page">
            <div class="erp-form-content">
                <div class="erp-form-header-row customer-list-header-row">
                    <div class="customer-header-copy">
                        <h1 class="erp-form-title">{{ workspaceTitle }}</h1>
                        <p class="erp-form-subtitle">{{ workspaceSubtitle }}</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions customer-header-actions">
                        <div class="customer-header-leading-actions">
                            <button
                                class="erp-btn erp-btn-secondary compact-toggle"
                                type="button"
                                :title="compact ? '展开统计与筛选' : '收起统计与筛选'"
                                :aria-label="compact ? '展开统计与筛选' : '收起统计与筛选'"
                                @click="toggleCompact"
                            >↕️</button>
                            <button
                                v-if="canExport"
                                class="erp-btn erp-btn-secondary customer-header-utility"
                                type="button"
                                disabled
                                title="客户导出功能（M1.8）尚在规划中，暂不可用"
                            >导出</button>
                            <button
                                v-if="canImport"
                                class="erp-btn erp-btn-secondary customer-header-utility"
                                type="button"
                                disabled
                                title="客户导入功能（M1.8）尚在规划中，暂不可用"
                            >导入</button>
                        </div>
                        <nav v-if="isGroupsWorkspace" class="customer-group-shortcut-rail" aria-label="客户分组快捷筛选">
                            <Link
                                v-for="shortcut in groupShortcuts"
                                :key="shortcut.key || 'all'"
                                class="erp-btn erp-btn-secondary customer-group-shortcut"
                                :class="{ 'customer-group-shortcut-active': filters.group === shortcut.key }"
                                :href="groupHref(shortcut.key)"
                            >{{ shortcut.label }}</Link>
                        </nav>
                        <Link v-if="canOperate" class="erp-btn erp-btn-primary customer-header-create" href="/customers/library/create">新建客户</Link>
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
                            class="erp-form-card customer-kpi-card"
                            title="按 Customer Email 与营销 Subscriber Email 精确匹配的身份链接数；不代表统一营销同意状态"
                            :aria-label="`Subscriber 匹配: ${stats.subscriber_identity_links}。按 Email 精确匹配，不代表统一营销同意状态`"
                        >
                            <p class="kpi-label">Subscriber 匹配</p>
                            <p class="kpi-value">{{ stats.subscriber_identity_links }}</p>
                        </article>
                        <article
                            class="erp-form-card customer-kpi-card kpi-unavailable"
                            :title="`待处理客户支持工单: ${stats.pending_support_tickets ?? 0}`"
                            :aria-label="`待处理工单: ${stats.pending_support_tickets ?? 0}`"
                        >
                            <p class="kpi-label">待处理工单</p>
                            <p class="kpi-value">{{ stats.pending_support_tickets ?? 0 }}</p>
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
                            <div v-if="!isGroupsWorkspace" class="filter-row-field">
                                <label class="erp-form-label" for="filter-group">客户分组</label>
                                <select id="filter-group" v-model="filters.group" class="erp-select filter-select">
                                    <option value="">全部客户</option>
                                    <optgroup label="系统分组">
                                        <option v-for="shortcut in groupShortcuts.slice(1)" :key="shortcut.key" :value="shortcut.key">{{ shortcut.label }}</option>
                                    </optgroup>
                                    <optgroup v-if="manualGroups.length" label="手动分组">
                                        <option v-for="group in manualGroups" :key="`manual:${group.id}`" :value="`manual:${group.id}`">{{ group.name }}</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="filter-row-submit">
                                <span class="erp-form-label" aria-hidden="true">&nbsp;</span>
                                <button class="erp-btn erp-btn-primary" type="submit">筛选</button>
                            </div>
                        </div>
                    </form>
                </template>

                <section v-if="isGroupsWorkspace" class="manual-groups-area" aria-label="手动客户分组">
                    <div class="manual-groups-heading">
                        <div>
                            <h2>手动分组</h2>
                            <p>持久化维护客户成员；系统分组仍由订单、发货和客户事实动态计算。</p>
                        </div>
                        <form v-if="canOperate" class="manual-group-create" @submit.prevent="createGroup">
                            <input v-model="newGroupName" class="erp-input" type="text" maxlength="255" placeholder="新分组名称" aria-label="新分组名称">
                            <button class="erp-btn erp-btn-primary" type="submit">创建分组</button>
                        </form>
                    </div>
                    <div v-if="manualGroups.length" class="manual-group-list">
                        <Link v-for="group in manualGroups" :key="group.id" :href="groupHref(`manual:${group.id}`)" class="manual-group-card" :class="{ 'manual-group-card-active': selectedManualGroup?.id === group.id }">
                            <strong>{{ group.name }}</strong><span>{{ group.member_count }} 位客户</span>
                        </Link>
                    </div>
                    <p v-else class="manual-group-empty">暂无手动分组。使用“创建分组”开始维护固定客户集合。</p>
                    <div v-if="selectedManualGroup" class="manual-group-detail">
                        <div class="manual-group-detail-header">
                            <div><h3>{{ selectedManualGroup.name }}</h3><p>{{ selectedManualGroup.member_count }} 位客户</p></div>
                            <div v-if="canOperate" class="manual-group-actions">
                                <button v-if="!renameOpen" class="erp-btn erp-btn-secondary" type="button" @click="beginRename">重命名</button>
                                <form v-else class="manual-group-rename" @submit.prevent="renameGroup">
                                    <input v-model="renameGroupName" class="erp-input" type="text" maxlength="255" aria-label="新的分组名称">
                                    <button class="erp-btn erp-btn-primary" type="submit">保存</button>
                                    <button class="erp-btn erp-btn-secondary" type="button" @click="renameOpen = false">取消</button>
                                </form>
                                <button class="erp-btn erp-btn-danger" type="button" @click="deleteGroup">删除分组</button>
                            </div>
                        </div>
                        <form v-if="canOperate" class="manual-group-add" @submit.prevent="searchGroupCustomers">
                            <input v-model="memberSearch" class="erp-input" type="search" placeholder="按姓名 / Email / 电话搜索客户" aria-label="搜索可加入客户">
                            <button class="erp-btn erp-btn-secondary" type="submit">搜索</button>
                            <button class="erp-btn erp-btn-primary" type="button" :disabled="selectedMemberIds.length === 0" @click="addMembers">添加客户</button>
                        </form>
                        <div v-if="memberSearchResults.length" class="manual-group-search-results">
                            <label v-for="customer in memberSearchResults" :key="customer.id"><input v-model="selectedMemberIds" type="checkbox" :value="customer.id"> {{ customer.name }} <small>{{ customer.email || customer.phone || '—' }}</small></label>
                        </div>
                        <p v-if="customers.data.length === 0" class="manual-group-empty-state">这个手动分组还没有客户。</p>
                    </div>
                </section>

                <div class="customer-grid-shell">
                    <div class="customer-grid-settings-anchor">
                        <button
                            class="column-settings-trigger"
                            type="button"
                            aria-label="设置客户列表列"
                            title="设置显示列和列顺序"
                            @click="columnSettingsOpen = !columnSettingsOpen"
                        >…</button>
                        <div v-if="columnSettingsOpen" class="column-settings-panel" @click.stop>
                            <div class="column-settings-heading">
                                <strong>列设置</strong>
                                <button type="button" class="column-reset-btn" @click="resetColumns">恢复默认</button>
                            </div>
                            <p class="column-settings-help">勾选显示列；按住拖动柄上下调整列顺序。设置绑定当前账号并跨设备同步。</p>
                            <p v-if="columnPreferenceSyncState !== 'idle'" class="column-sync-state" :data-state="columnPreferenceSyncState">{{ columnPreferenceSyncState === 'saving' ? '正在同步…' : columnPreferenceSyncState === 'error' ? '同步失败，已保留本地缓存' : '已同步到账号' }}</p>
                            <div class="column-setting-row column-setting-pinned">
                                <span class="column-drag-handle column-drag-disabled">⋮⋮</span>
                                <input type="checkbox" checked disabled aria-label="客户编号 / 姓名固定显示">
                                <span>客户编号 / 姓名</span>
                                <small>固定</small>
                            </div>
                            <div
                                v-for="column in columnPreferences"
                                :key="column.key"
                                class="column-setting-row"
                                draggable="true"
                                :data-column-key="column.key"
                                @dragstart="startColumnDrag(column.key)"
                                @dragover.prevent
                                @drop="dropColumn(column.key)"
                            >
                                <span class="column-drag-handle" title="拖动调整顺序">⋮⋮</span>
                                <input
                                    type="checkbox"
                                    :checked="column.visible"
                                    :aria-label="`显示${column.label}`"
                                    @change="toggleColumn(column.key)"
                                >
                                <span>{{ column.label }}</span>
                            </div>
                            <p class="column-settings-unavailable">用户名、网站最近活跃：等待 Woo Customer 数据 authority 接入后开放。</p>
                        </div>
                    </div>
                    <div class="mvp-table-wrap customer-grid-scroll">
                        <table class="mvp-table customer-data-grid">
                            <thead>
                                <tr>
                                    <th class="customer-grid-identity">客户编号 / 姓名</th>
                                    <th v-for="column in visibleGroupColumns" :key="column.key">{{ column.label }}</th>
                                    <th class="customer-grid-action-head"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="customer in customers.data" :key="customer.id">
                                    <td class="customer-grid-identity">
                                        <Link :href="`/customers/library/${customer.id}`" class="customer-name-link">{{ customer.name }}</Link>
                                        <br><span class="customer-number">{{ customer.customer_number }}</span>
                                    </td>
                                    <td v-for="column in visibleGroupColumns" :key="column.key" :class="{ 'td-revenue': column.key === 'total_spend' || column.key === 'aov' }">
                                        <template v-if="column.key === 'email'">{{ customer.email ?? '—' }}</template>
                                        <template v-else-if="column.key === 'phone'">{{ customer.phone ?? '—' }}</template>
                                        <template v-else-if="column.key === 'registered_at'">{{ dateOnly(customer.created_at) }}</template>
                                        <template v-else-if="column.key === 'country_region'">{{ customerCountry(customer) }}</template>
                                        <template v-else-if="column.key === 'city'">{{ customer.primary_address?.city ?? '—' }}</template>
                                        <template v-else-if="column.key === 'province_state'">{{ customer.primary_address?.province_state ?? '—' }}</template>
                                        <template v-else-if="column.key === 'postal_code'">{{ customer.primary_address?.postal_code ?? '—' }}</template>
                                        <template v-else-if="column.key === 'source'">{{ customer.source_label }}</template>
                                        <template v-else-if="column.key === 'orders_count'">{{ customer.orders_count }}</template>
                                        <template v-else-if="column.key === 'shipment_count'">{{ customer.shipment_count }}</template>
                                        <template v-else-if="column.key === 'total_spend'">
                                            <template v-if="customer.lifetime_revenue.length === 0">—</template>
                                            <span v-for="r in customer.lifetime_revenue" v-else :key="r.currency" class="revenue-chip">{{ money(r.revenue, r.currency) }}</span>
                                        </template>
                                        <template v-else-if="column.key === 'aov'">
                                            <template v-if="customer.lifetime_revenue.length === 0">—</template>
                                            <span v-for="r in customer.lifetime_revenue" v-else :key="r.currency" class="revenue-chip">{{ money(r.average_order_value, r.currency) }}</span>
                                        </template>
                                        <template v-else-if="column.key === 'latest_order'">{{ customer.latest_order_date ?? '—' }}</template>
                                        <template v-else-if="column.key === 'owner'">{{ customer.owner?.name ?? '—' }}</template>
                                        <template v-else-if="column.key === 'status'">
                                            <span v-if="customer.archived_at" class="archived-badge">已归档</span>
                                            <span v-else class="active-badge">在用</span>
                                        </template>
                                        <template v-else-if="column.key === 'subscription'">{{ subscriptionSummary(customer) }}</template>
                                    </td>
                                    <td class="customer-grid-action-cell">
                                        <Link :href="`/customers/library/${customer.id}`" class="erp-btn erp-btn-secondary table-action-btn">查看</Link>
                                        <button v-if="canOperate && selectedManualGroup" class="erp-btn erp-btn-secondary table-action-btn" type="button" @click="removeMember(customer)">移出分组</button>
                                        <button v-if="canOperate && customer.archived_at" class="erp-btn erp-btn-secondary table-action-btn" type="button" @click="restore(customer)">恢复</button>
                                    </td>
                                </tr>
                                <tr v-if="customers.data.length === 0">
                                    <td :colspan="visibleGroupColumns.length + 2" class="mvp-empty">暂无符合条件的客户</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
.manual-groups-area { margin-bottom: 16px; padding: 16px; border: 1px solid #DCE5E8; border-radius: 10px; background: #F8FAFA; }
.manual-groups-heading, .manual-group-detail-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.manual-groups-heading h2, .manual-group-detail h3 { margin: 0; color: #142126; }
.manual-groups-heading p, .manual-group-detail-header p { margin: 4px 0 0; color: #7A8C94; font-size: 13px; }
.manual-group-create, .manual-group-add, .manual-group-actions, .manual-group-rename { display: flex; gap: 8px; align-items: center; }
.manual-group-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.manual-group-card { display: flex; flex-direction: column; gap: 3px; min-width: 150px; padding: 10px 12px; border: 1px solid #DCE5E8; border-radius: 8px; background: #fff; color: #142126; text-decoration: none; }
.manual-group-card span { color: #7A8C94; font-size: 12px; }
.manual-group-card-active { border-color: #246F68; box-shadow: 0 0 0 1px #246F68; }
.manual-group-empty, .manual-group-empty-state { margin: 14px 0 0; color: #7A8C94; font-size: 13px; }
.manual-group-detail { margin-top: 16px; padding-top: 14px; border-top: 1px solid #DCE5E8; }
.manual-group-add { margin-top: 12px; }
.manual-group-add .erp-input { min-width: 280px; }
.manual-group-search-results { display: grid; gap: 6px; margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #DCE5E8; border-radius: 8px; }
.manual-group-search-results label { color: #344B53; }
.manual-group-search-results small { color: #7A8C94; }
.erp-btn-danger { color: #A33A3A; border-color: #E8B7B7; background: #FFF7F7; }
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
.customer-list-header-row { align-items: flex-start; }
.customer-header-copy { flex: 0 1 300px; min-width: 220px; }
.customer-header-actions {
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: nowrap;
    gap: 6px;
    min-width: 0;
    max-width: calc(100% - 236px);
}
.customer-header-leading-actions { display: flex; flex: 0 0 auto; align-items: center; gap: 6px; }
.customer-header-utility { flex: 0 0 auto; padding-inline: 10px; }
.customer-group-shortcut-rail {
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    gap: 6px;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.customer-group-shortcut {
    flex: 0 0 auto;
    white-space: nowrap;
    padding: 6px 9px;
    min-height: 34px;
    font-size: 12px;
}
.customer-header-create { flex: 0 0 auto; white-space: nowrap; }
.customer-group-shortcut-active {
    border-color: #142126;
    background: #E9F0F2;
    color: #142126;
    font-weight: 700;
}
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
.active-badge {
    display: inline-block;
    background: #E8F5F1;
    color: #246F68;
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 999px;
}
.customer-grid-shell { position: relative; }
.customer-grid-scroll { overflow-x: auto; scrollbar-width: thin; }
.customer-data-grid { min-width: 1500px; }
.customer-data-grid th,
.customer-data-grid td { white-space: nowrap; }
.customer-grid-identity {
    position: sticky;
    left: 0;
    min-width: 190px;
    background: #fff;
    z-index: 3;
}
thead .customer-grid-identity { z-index: 5; }
.customer-grid-action-head,
.customer-grid-action-cell {
    position: sticky;
    right: 0;
    width: 76px;
    min-width: 76px;
    background: #fff;
    z-index: 4;
}
.customer-grid-settings-anchor {
    position: absolute;
    top: 0;
    right: 0;
    width: 76px;
    height: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border-left: 1px solid #E4EAED;
    border-bottom: 1px solid #E4EAED;
    z-index: 20;
}
.column-settings-trigger {
    width: 42px;
    height: 32px;
    border: 1px solid #DCE5E8;
    border-radius: 8px;
    background: #fff;
    color: #344B53;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
}
.column-settings-trigger:hover { background: #F4F7F8; }
.column-settings-panel {
    position: absolute;
    top: 40px;
    right: 0;
    width: 300px;
    max-height: 520px;
    overflow-y: auto;
    padding: 12px;
    background: #fff;
    border: 1px solid #DCE5E8;
    border-radius: 10px;
    box-shadow: 0 12px 32px rgba(20, 33, 38, 0.14);
    text-align: left;
    z-index: 40;
}
.column-settings-heading { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.column-settings-help,
.column-settings-unavailable { margin: 8px 0; font-size: 11px; line-height: 1.45; color: #7A8C94; }
.column-settings-unavailable { padding-top: 8px; border-top: 1px solid #EEF2F3; }
.column-reset-btn { border: 0; background: transparent; color: #2D8C84; font-size: 12px; cursor: pointer; }
.column-setting-row {
    display: grid;
    grid-template-columns: 22px 22px 1fr auto;
    align-items: center;
    gap: 6px;
    min-height: 34px;
    padding: 4px 5px;
    border-radius: 6px;
    font-size: 13px;
    cursor: grab;
}
.column-setting-row:hover { background: #F4F7F8; }
.column-setting-row:active { cursor: grabbing; }
.column-setting-pinned { background: #F8FAFA; cursor: default; }
.column-setting-row small { color: #7A8C94; font-size: 10px; }
.column-drag-handle { color: #7A8C94; letter-spacing: -2px; user-select: none; }
.column-drag-disabled { opacity: 0.35; }
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
@media (max-width: 1100px) {
    .customer-kpi-row { grid-template-columns: repeat(3, 1fr); }
    .customer-header-copy { flex-basis: 250px; min-width: 190px; }
    .customer-header-actions { max-width: calc(100% - 206px); }
}
@media (max-width: 760px) {
    .customer-list-header-row { width: 100%; }
    .customer-header-copy { width: 100%; min-width: 0; }
    .customer-header-actions { width: 100%; max-width: 100%; }
}
@media (max-width: 700px) { .customer-kpi-row { grid-template-columns: repeat(2, 1fr); } }
</style>
