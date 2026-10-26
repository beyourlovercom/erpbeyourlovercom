<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import { publishGlobalFeedback } from '../../../lib/globalFeedback'

type Attempt = {
    state: string
    oms_order_no: string | null
    failure_code?: string | null
    last_checked_at?: string | null
    oms_status_code?: string | null
    oms_status_checked_at?: string | null
    oms_status_failure_code?: string | null
    oms_tracking_no?: string | null
}
type StatusResult = {
    read_only: boolean
    fresh: boolean
    oms_order_no: string
    oms_status_code: string | null
    oms_status_label: string
    oms_tracking_no: string | null
    oms_status_checked_at: string | null
    oms_status_failure_code: string | null
    erp_status_unchanged: boolean
    woo_status_unchanged: boolean
}
type Preview = {
    order_id: number
    erp_order_id: string | null
    erp_order_status?: string | null
    warehouse: string | null
    remote_bound: boolean
    blockers: string[]
    ready_for_remote_check: boolean
    payload_hash: string
    oms_reference: string
    attempt: Attempt | null
    payload: {
        whCode: string
        logisticsChannel: string
        receiver: string
        addressOne: string
        countryRegionCode: string
        postCode: string
        productList: Array<{ sku: string; quantity: number }>
        isSubmit: number
    }
}
const props = defineProps<{ preview: Preview; canDispatch: boolean }>()
const confirmation = ref('')
const processing = ref(false)
const feedback = ref('')
const statusPending = ref(false)
const statusError = ref('')
const attempt = ref<Attempt | null>(props.preview.attempt ? { ...props.preview.attempt } : null)

const eligible = computed(() =>
    props.canDispatch && props.preview.ready_for_remote_check && !attempt.value
        && confirmation.value === 'SUBMIT_US_SM_ORDER_' + props.preview.order_id,
)
const hasOmsOrder = computed(() => !!attempt.value?.oms_order_no)
const statusLabel = computed(() => {
    const code = attempt.value?.oms_status_code
    if (!code) return '尚未查询'
    if (code === '3') return '已出库'
    if (code === '4') return '已取消'
    return 'OMS 状态 ' + code + '（待核实）'
})
const erpStatusLabel = computed(() => {
    const labels: Record<string, string> = {
        pending_shipment: '待发货', shipped: '已发货', completed: '已完成',
        cancelled: '已取消',
    }
    return labels[props.preview.erp_order_status || ''] || props.preview.erp_order_status || '未取得'
})
const checkedAtLabel = computed(() => {
    const raw = attempt.value?.oms_status_checked_at
    if (!raw) return '尚未查询'
    const d = new Date(raw)
    return Number.isNaN(d.getTime()) ? raw : d.toLocaleString('zh-CN', { hour12: false })
})
const statusFailureLabels: Record<string, string> = {
    OMS_STATUS_NOT_FOUND: 'OMS 尚未找到此出库单，请确认仓库和单号。',
    OMS_STATUS_IDENTITY_MISMATCH: 'OMS 返回的仓库或订单编号不一致，已阻止覆盖状态。',
    OMS_STATUS_QUERY_INVALID: 'OMS 单号格式无效，无法安全查询。',
    OMS_STATUS_NOT_READY: '该订单还没有可供查询的 OMS 出库单号。',
    OMS_STATUS_RESPONSE_INVALID: 'OMS 返回了不完整或异常的数据，已保留上次有效状态。',
    OMS_TRANSPORT_UNCERTAIN: '连接 OMS 失败，请检查网络后再尝试只读查询。',
    OMS_HTTP_REJECTED: 'OMS 拒绝查询，请核对账号权限与连接配置。',
    OMS_SERVER_UNCERTAIN: 'OMS 服务暂不可用，已保留上次有效状态。',
    OMS_BUSINESS_REJECTED: 'OMS 返回业务错误，请检查接口配置。',
    OMS_STATUS_UNEXPECTED_ERROR: '查询失败，已保留上次有效状态。',
}
if (attempt.value?.oms_status_failure_code) {
    statusError.value = statusFailureLabels[attempt.value.oms_status_failure_code]
        || '上一次 OMS 状态查询失败，已保留此前状态。'
}
const blockerLabels: Record<string, string> = {
    wrong_warehouse: '不是世迈仓 US-SM 订单',
    order_not_pending_shipment: '订单并非待发货',
    shipment_not_pending: '发货记录已非待发货',
    warehouse_review_not_approved: 'Dede 仓库审核尚未通过',
    tracking_already_assigned: '已有 Tracking，可能已发货',
    api_credentials_missing: '仓库未保存 API 凭据',
    oms_warehouse_not_bound: '未确认 OMS 远端仓库代码',
    recipient_missing: '缺收件人',
    address_missing: '缺详细地址',
    city_missing: '缺城市',
    postal_code_missing: '缺邮编',
    country_iso2_missing: '国家代码不完整',
    province_missing: '缺州省',
    province_code_not_confirmed: '州省代码需人工核实',
    native_logistics_channel_missing: '缺 OMS 原生物流渠道代码',
    logistics_channel_inactive: '渠道已停用',
    no_valid_order_items: '没有有效 SKU',
    invalid_item: 'SKU 或数量错误',
    inventory_unverified: '库存未核实',
    insufficient_warehouse_stock: '当前仓库库存不足',
    existing_dispatch_attempt: '已经有下发记录，禁止再次创建',
}
const localMessage = (state: 'ERROR' | 'SUCCESS' | 'INFO', title: string, message: string) => {
    publishGlobalFeedback({ state, title, message, copyable: state === 'ERROR' })
}

async function queryOmsStatus() {
    if (!hasOmsOrder.value || statusPending.value || processing.value) return
    statusPending.value = true
    statusError.value = ''
    try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
        if (!token) throw Error('CSRF_MISSING')
        const response = await fetch(
            '/logistics/oversea/us-3sm/orders/' + props.preview.order_id + '/xlwms/status',
            {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: '{}',
            },
        )
        if (!response.ok) {
            statusError.value = response.status === 403
                ? '没有查看 OMS 状态的权限。'
                : response.status === 429
                    ? '查询太频繁，请稍后再试。'
                    : 'OMS 查询未完成（HTTP ' + response.status + '），已保留上次有效状态。'
            localMessage('ERROR', 'OMS 状态查询失败', statusError.value)
            return
        }
        const result = await response.json() as StatusResult
        if (result.oms_order_no !== attempt.value?.oms_order_no) throw Error('OMS_NUMBER_MISMATCH')
        if (!result.read_only || !result.erp_status_unchanged || !result.woo_status_unchanged) {
            throw Error('INVALID_READ_ONLY_RESPONSE')
        }
        if (!result.fresh) {
            statusError.value = statusFailureLabels[result.oms_status_failure_code || '']
                || 'OMS 查询失败，已保留上次有效状态。'
            localMessage('ERROR', 'OMS 状态未能更新', statusError.value)
            return
        }
        // Replace only the verified OMS snapshot. Never change ERP/Woo state.
        if (attempt.value) {
            attempt.value = {
                ...attempt.value,
                oms_status_code: result.oms_status_code,
                oms_tracking_no: result.oms_tracking_no,
                oms_status_checked_at: result.oms_status_checked_at,
                oms_status_failure_code: null,
            }
        }
        localMessage('SUCCESS', 'OMS 状态已更新', result.oms_status_label + ' · 仅更新查询记录')
    } catch {
        statusError.value = '网络或页面异常，OMS 查询结果未确认，已保留上次有效状态。'
        localMessage('ERROR', 'OMS 状态查询失败', statusError.value)
    } finally {
        statusPending.value = false
    }
}

async function action(type: 'send' | 'reconcile') {
    if (processing.value || statusPending.value) return
    processing.value = true
    feedback.value = ''
    try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
        if (!token) throw Error('CSRF_MISSING')
        const response = await fetch(
            '/logistics/oversea/us-3sm/orders/' + props.preview.order_id + '/xlwms/' + type,
            {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(type === 'send'
                    ? { payload_hash: props.preview.payload_hash, confirmation: confirmation.value } : {}),
            },
        )
        if (!response.ok) {
            feedback.value = '操作未完成（HTTP ' + response.status + '），请检查审核、权限或配置，不能直接重复下发。'
            localMessage('ERROR', 'OMS 操作失败', feedback.value)
            return
        }
        const result = await response.json() as {
            state: string
            status_label?: string
            oms_order_no?: string | null
        }
        feedback.value = (result.status_label || result.state)
            + (result.oms_order_no ? ' · OMS ' + result.oms_order_no : '') + '。请核实 OMS 结果。'
        if (result.oms_order_no) {
            attempt.value = attempt.value
                ? { ...attempt.value, state: result.state, oms_order_no: result.oms_order_no }
                : { state: result.state, oms_order_no: result.oms_order_no }
        }
        localMessage('INFO', 'OMS 操作结果', feedback.value)
    } catch {
        feedback.value = '请求结果不明，请先只读查询 OMS，不能重复创建。'
        localMessage('ERROR', 'OMS 结果尚未确认', feedback.value)
    } finally {
        processing.value = false
        confirmation.value = ''
    }
}
</script>

<template>
    <Head title="世迈仓 OMS 单笔出库预检" />
    <AuthenticatedLayout title="物流" :breadcrumbs="['ERP', '物流', '世迈仓', 'API 出库预检']">
        <section class="mvp-page single-order">
            <div class="mvp-page-heading">
                <div>
                    <p class="mvp-kicker">XLWMS · SINGLE REAL ORDER</p>
                    <h2>世迈仓 · API 发货与订单状态</h2>
                    <p>ERP 订单 {{ preview.erp_order_id || preview.order_id }} · API 接单不代表已发货</p>
                </div>
                <Link class="mvp-button" href="/logistics/oversea/us-3sm?view=pending">返回待发货</Link>
            </div>

            <article class="mvp-card oms-status-panel" data-testid="xlwms-status-panel">
                <div class="oms-status-heading">
                    <div>
                        <h3>OMS 最新状态</h3>
                        <p class="oms-status-hint">仅查询并保存 OMS 状态，不修改 ERP、发货记录或 WooCommerce</p>
                    </div>
                    <span class="oms-readonly-badge">只读</span>
                </div>
                <div class="oms-status-grid">
                    <div class="oms-field">
                        <span class="oms-field-label">OMS 出库单号</span>
                        <strong data-testid="xlwms-oms-number">{{ attempt?.oms_order_no || '尚未创建' }}</strong>
                    </div>
                    <div class="oms-field">
                        <span class="oms-field-label">OMS 最新状态</span>
                        <strong data-testid="xlwms-oms-status" :class="{ 'oms-cancelled': attempt?.oms_status_code === '4' }">{{ statusLabel }}</strong>
                    </div>
                    <div class="oms-field">
                        <span class="oms-field-label">物流跟踪号</span>
                        <strong data-testid="xlwms-tracking">{{ attempt?.oms_tracking_no || '暂无' }}</strong>
                    </div>
                    <div class="oms-field">
                        <span class="oms-field-label">ERP 订单状态</span>
                        <strong data-testid="xlwms-erp-status">{{ erpStatusLabel }}</strong>
                    </div>
                </div>
                <div class="oms-status-actions">
                    <div class="oms-status-caption">
                        <span>上次成功查询：{{ checkedAtLabel }}</span>
                        <span v-if="attempt?.oms_status_code === '3'" class="oms-no-auto-write">OMS 显示已出库，但尚未同步 ERP / Woo</span>
                    </div>
                    <button
                        class="mvp-button"
                        type="button"
                        data-testid="xlwms-readonly-refresh"
                        :disabled="!hasOmsOrder || statusPending || processing"
                        @click="queryOmsStatus"
                    >{{ statusPending ? '查询中…' : '查询 OMS 最新状态（只读）' }}</button>
                </div>
                <p v-if="!hasOmsOrder" class="oms-status-hint">尚未取得 OMS 单号，当前不能查询订单状态。</p>
                <p v-if="statusError" role="alert" class="oms-status-error" data-testid="xlwms-status-error">{{ statusError }}</p>
            </article>

            <article class="mvp-card">
                <h3>发货门禁</h3>
                <p v-if="!preview.blockers.length">本地校验通过，但 OMS 已有订单查询必须在发送前完成。</p>
                <ul v-else><li v-for="blocker in preview.blockers" :key="blocker">{{ blockerLabels[blocker] || blockerLabels[blocker.split(':')[0]] || blocker }}</li></ul>
                <p>参考号：{{ preview.oms_reference }} · 远端仓库绑定：{{ preview.remote_bound ? '已配置' : '未配置' }}</p>
                <p>已有尝试：{{ attempt?.state || '无' }} <span v-if="attempt?.oms_order_no"> · {{ attempt.oms_order_no }}</span></p>
            </article>
            <article class="mvp-card">
                <h3>实际提交的字段预览</h3>
                <table><tbody>
                    <tr><th>OMS 仓库</th><td>{{ preview.payload.whCode || '待绑定' }}</td></tr>
                    <tr><th>物流渠道原生代码</th><td>{{ preview.payload.logisticsChannel || '未配置' }}</td></tr>
                    <tr><th>收件人</th><td>{{ preview.payload.receiver }}</td></tr>
                    <tr><th>地址</th><td>{{ preview.payload.addressOne }} · {{ preview.payload.countryRegionCode }} {{ preview.payload.postCode }}</td></tr>
                    <tr><th>SKU × 数量</th><td><span v-for="item in preview.payload.productList" :key="item.sku">{{ item.sku }} × {{ item.quantity }}　</span></td></tr>
                    <tr><th>提交模式</th><td>isSubmit={{ preview.payload.isSubmit }}（直接提交仓库）</td></tr>
                </tbody></table>
            </article>
            <article class="mvp-card">
                <h3>单笔正式发送</h3>
                <p v-if="!canDispatch">此环境真实发单开关未启用，不能创建出库单。</p>
                <p>必须由 Owner 确定真实订单并批准此单。超时后只读查单，不自动重试。</p>
                <label>请输入确认码 SUBMIT_US_SM_ORDER_{{ preview.order_id }}
                    <input v-model="confirmation" autocomplete="off" spellcheck="false" placeholder="手动输入完整确认码" />
                </label>
                <div class="actions">
                    <button class="mvp-button mvp-button-primary" type="button" :disabled="!eligible || processing || statusPending" @click="action('send')">真实提交单笔 OMS 出库</button>
                    <button v-if="attempt" class="mvp-button" type="button" :disabled="processing || statusPending" @click="action('reconcile')">只读核对 OMS</button>
                </div>
                <p role="status" :class="{ 'oms-status-error': feedback.includes('失败') || feedback.includes('不明') }">{{ feedback }}</p>
            </article>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.single-order { max-width: 1020px; }
.actions { display: flex; gap: 10px; margin: 16px 0; }
.single-order input { display: block; margin-top: 9px; padding: 10px; border: 1px solid #cbd5cf; border-radius: 8px; width: min(100%, 440px); }
.single-order th { text-align: left; min-width: 180px; padding: 10px; background: #f8fbf9; }
.single-order td { padding: 10px; word-break: break-word; }
.single-order tr { border-bottom: 1px solid #e8eeea; }
.oms-status-heading, .oms-status-actions { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
.oms-status-heading h3 { margin: 0 0 4px; }
.oms-status-hint, .oms-status-caption { color: #65716e; font-size: 12px; }
.oms-status-hint { margin: 4px 0 0; }
.oms-readonly-badge { padding: 4px 10px; border-radius: 16px; background: #f0f2f1; color: #64736c; font-size: 12px; }
.oms-status-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; padding: 20px 0; margin-top: 12px; border-top: 1px solid #e6ebe8; border-bottom: 1px solid #e6ebe8; }
.oms-field { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-width: 0; }
.oms-field-label { font-size: 12px; color: #66746c; }
.oms-field strong { font-size: 15px; overflow-wrap: anywhere; color: #17251c; font-weight: 600; }
.oms-field strong.oms-cancelled { color: #6b7470; }
.oms-status-actions { padding-top: 18px; }
.oms-status-caption { display: flex; flex-direction: column; gap: 4px; }
.oms-no-auto-write { color: #916822; }
.oms-status-error { color: #c23131; font-size: 13px; margin: 10px 0 0; font-weight: 500; }
</style>
