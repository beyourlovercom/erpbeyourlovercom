<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Availability = {
  warehouse_id: number
  warehouse_slug: string
  warehouse_name: string
  inventory_state: string
  can_auto_allocate: boolean
  quantity_on_hand: number | null
  quantity_reserved: number | null
  quantity_available: number | null
}
type Item = {
  id: number
  product_id: number | null
  product_url: string | null
  sku: string | null
  product_summary: string | null
  quantity: number
  availability: Availability[]
}
type PlanItem = { product_id: number | null; quantity: number; sku?: string | null; product_summary?: string | null }
type Segment = {
  source_type: 'factory' | 'warehouse'
  source_code: string
  warehouse_id: number | null
  warehouse_name: string
  carrier?: string | null
  service?: string | null
  items: PlanItem[]
}
type PlanCost = { status: string; amount: number | null; currency: string | null; reason_code?: string | null; reason?: string | null }
type Plan = {
  plan_key: string
  plan_type: 'single' | 'factory' | 'split'
  is_split: boolean
  shipment_count: number
  label: string
  segments: Segment[]
  cost: PlanCost
  effective_cost?: number | null
  execution_status: string
}
type AllocationDecision = {
  status: 'system_recommendation' | 'manual_review'
  status_label: string
  reason_code: string
  manual_reasons: string[]
  recommended_plan_key: string | null
  recommended_plan: Plan | null
  baseline_plan: Plan | null
  estimated_cost: number | null
  currency: string | null
  estimated_saving: number | null
  plans: Plan[]
  can_use_recommendation: boolean
  policy: {
    system_is_advisory: boolean
    human_confirmation_required: boolean
    missing_data_falls_back_to_manual: boolean
    same_order_item_quantity_split_allowed: boolean
    max_sources: number
    split_pattern: string
  }
}
type DerivedOrderRecord = {
  order_id: number
  erp_order_id: string | null
  derived: boolean
  suffix: string | null
  warehouse_id: number | null
  warehouse_code: string | null
  warehouse_name: string | null
  items: Array<{ sku: string | null; product_summary: string | null; quantity: number }>
}
type ExistingAllocation = {
  status: string
  decision_source: 'system_recommendation' | 'manual'
  selected_plan_key: string | null
  decision_note: string | null
  plan_snapshot: (Plan & { derived_orders?: DerivedOrderRecord[] }) | null
  submitted_at: string | null
}
type Order = {
  id: number
  erp_order_id: string | null
  order_url: string | null
  recipient_name: string | null
  country: string | null
  item_count: number
  items: Item[]
  allocation_decision: AllocationDecision
  fulfillment_allocation: ExistingAllocation | null
}
type Warehouse = {
  id: number
  slug: string
  code: string
  name: string
  country_code: string | null
  can_auto_allocate: boolean
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
type TabKey = 'pending' | 'recommendation' | 'manual' | 'processed'

const props = defineProps<{
  orders: Paginated<Order>
  warehouses: Warehouse[]
  pendingTotal: number
  canOperate: boolean
}>()

const activeTab = ref<TabKey>('pending')
const selectedOrderId = ref<number | null>(null)
const manualMode = ref(false)
const decisionNote = ref('')
const submitError = ref('')
const submitting = ref(false)
const localSubmitted = reactive<Record<number, { source: 'system_recommendation' | 'manual'; submittedAt: string }>>({})
const manualAssignments = reactive<Record<number, Record<number, string>>>({})

const selectedOrder = computed(() => props.orders.data.find(order => order.id === selectedOrderId.value) ?? null)
const processed = (order: Order) => Boolean(order.fulfillment_allocation || localSubmitted[order.id])

const counts = computed(() => ({
  pending: props.orders.data.filter(order => !processed(order)).length,
  recommendation: props.orders.data.filter(order => !processed(order) && order.allocation_decision.status === 'system_recommendation').length,
  manual: props.orders.data.filter(order => !processed(order) && order.allocation_decision.status === 'manual_review').length,
  processed: props.orders.data.filter(processed).length,
}))

const filteredOrders = computed(() => props.orders.data.filter(order => {
  if (activeTab.value === 'processed') return processed(order)
  if (processed(order)) return false
  if (activeTab.value === 'recommendation') return order.allocation_decision.status === 'system_recommendation'
  if (activeTab.value === 'manual') return order.allocation_decision.status === 'manual_review'
  return true
}))

const sourceLabel = (code: string) => props.warehouses.find(warehouse => warehouse.code === code)?.name ?? code
const warehouseOptions = computed(() => props.warehouses.filter(warehouse => ['FACTORY', 'US-NY', 'US-LA', 'US-SJ', 'DE', 'CA'].includes(warehouse.code)))

function money(value: number | null | undefined, currency: string | null | undefined): string {
  if (value == null || !currency) return '—'
  const symbol = currency === 'CNY' ? '¥' : `${currency} `
  return `${symbol}${Number(value).toFixed(2)}`
}

function itemSummary(order: Order): string {
  return order.items.map(item => `${item.sku ?? item.product_summary ?? '无 SKU'} × ${item.quantity}`).join('、')
}

function csrfHeaders(): Record<string, string> {
  const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  if (metaToken) return { 'X-CSRF-TOKEN': metaToken }
  const xsrf = decodeURIComponent(document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
  return xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}
}

function ensureManualAssignments(order: Order): void {
  if (!manualAssignments[order.id]) manualAssignments[order.id] = {}
  for (const item of order.items) {
    if (!(item.id in manualAssignments[order.id])) manualAssignments[order.id][item.id] = ''
  }
}

function openPlan(order: Order): void {
  selectedOrderId.value = order.id
  manualMode.value = !processed(order) && order.allocation_decision.status === 'manual_review'
  decisionNote.value = order.fulfillment_allocation?.decision_note ?? ''
  submitError.value = ''
  ensureManualAssignments(order)
}

function closePlan(): void {
  selectedOrderId.value = null
  manualMode.value = false
  submitError.value = ''
}

function enterManual(): void {
  const order = selectedOrder.value
  if (!order) return
  ensureManualAssignments(order)
  manualMode.value = true
  submitError.value = ''
}

function availabilityFor(item: Item, warehouse: Warehouse): Availability | undefined {
  return item.availability.find(row => row.warehouse_id === warehouse.id)
}

function availabilityLabel(item: Item, warehouse: Warehouse): string {
  if (warehouse.code === 'FACTORY') return '国内工厂'
  const row = availabilityFor(item, warehouse)
  if (!row || row.quantity_available == null) return '库存未知'
  return row.quantity_available >= item.quantity ? `有货 ${row.quantity_available}` : `不足 ${row.quantity_available}`
}

function availabilityClass(item: Item, warehouse: Warehouse): string {
  if (warehouse.code === 'FACTORY') return 'stock-neutral'
  const row = availabilityFor(item, warehouse)
  if (!row || row.quantity_available == null) return 'stock-unknown'
  return row.quantity_available >= item.quantity ? 'stock-ok' : 'stock-short'
}

function splitConstraintError(order: Order): string | null {
  const values = Object.values(manualAssignments[order.id] ?? {}).filter(Boolean)
  if (values.length !== order.items.length) return '请为每个 SKU 选择发货仓库'
  const sources = [...new Set(values)]
  if (sources.length > 2) return 'V1 最多允许两个履约来源'
  if (sources.length === 2 && !sources.includes('FACTORY')) return 'V1 拆单只允许「一个海外仓 + 中国工厂」'
  return null
}

async function submitRecommended(): Promise<void> {
  const order = selectedOrder.value
  const decision = order?.allocation_decision
  if (!order || !decision?.recommended_plan_key || !decision.can_use_recommendation || !props.canOperate) return
  submitting.value = true
  submitError.value = ''
  try {
    const response = await fetch(`/logistics/orders/${order.id}/fulfillment-allocation/recommended`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
      body: JSON.stringify({ plan_key: decision.recommended_plan_key, decision_note: decisionNote.value }),
    })
    const body = await response.json().catch(() => ({})) as { message?: string; decision_source?: 'system_recommendation'; submitted_at?: string }
    if (!response.ok) throw new Error(body.message ?? `提交失败（${response.status}）`)
    localSubmitted[order.id] = { source: 'system_recommendation', submittedAt: body.submitted_at ?? new Date().toISOString() }
    closePlan()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '提交失败，请重试'
  } finally {
    submitting.value = false
  }
}

async function submitManual(): Promise<void> {
  const order = selectedOrder.value
  if (!order || !props.canOperate) return
  const validation = splitConstraintError(order)
  if (validation) { submitError.value = validation; return }
  const assignments = order.items.map(item => ({ order_item_id: item.id, source_code: manualAssignments[order.id][item.id] }))
  submitting.value = true
  submitError.value = ''
  try {
    const response = await fetch(`/logistics/orders/${order.id}/fulfillment-allocation/manual`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
      body: JSON.stringify({ assignments, decision_note: decisionNote.value }),
    })
    const body = await response.json().catch(() => ({})) as { message?: string; decision_source?: 'manual'; submitted_at?: string }
    if (!response.ok) throw new Error(body.message ?? `提交失败（${response.status}）`)
    localSubmitted[order.id] = { source: 'manual', submittedAt: body.submitted_at ?? new Date().toISOString() }
    closePlan()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '提交失败，请重试'
  } finally {
    submitting.value = false
  }
}

watch(selectedOrder, order => {
  if (!order) return
  ensureManualAssignments(order)
})
</script>

<template>
  <Head title="运营分仓 · BYL ERP" />
  <AuthenticatedLayout title="运营分仓" :breadcrumbs="['BYL ERP', '物流', '待分仓']">
    <section class="allocation-page">
      <header class="page-heading">
        <div>
          <p class="eyebrow">Fulfillment Allocation V1</p>
          <h1>运营分仓</h1>
          <p>系统根据库存与可用成本证据提供辅助建议；数据不足时直接转人工判断，最终方案始终由 Vita 确认。</p>
        </div>
        <div class="principle"><strong>System Assisted</strong><span>Human Confirmed</span></div>
      </header>

      <nav class="tabs" aria-label="分仓状态">
        <button :class="{ active: activeTab === 'pending' }" @click="activeTab = 'pending'">待分仓 <span>{{ counts.pending }}</span></button>
        <button :class="{ active: activeTab === 'recommendation' }" @click="activeTab = 'recommendation'">系统推荐 <span>{{ counts.recommendation }}</span></button>
        <button :class="{ active: activeTab === 'manual' }" @click="activeTab = 'manual'">需要人工判断 <span>{{ counts.manual }}</span></button>
        <button :class="{ active: activeTab === 'processed' }" @click="activeTab = 'processed'">已处理 <span>{{ counts.processed }}</span></button>
      </nav>

      <div class="rule-strip">
        <span>系统建议仅供辅助，不自动提交</span>
        <span>缺关键数据 → 人工判断</span>
        <span>V1 最多 1 个海外仓 + 中国工厂</span>
        <span>同一 SKU 数量不拆仓</span>
      </div>

      <div class="table-wrap">
        <table class="allocation-table">
          <thead><tr><th>#</th><th>订单号</th><th>国家/地区</th><th>SKU / 数量</th><th>系统建议</th><th>预计成本</th><th>预计节省</th><th>状态</th><th class="sticky-action">操作</th></tr></thead>
          <tbody>
            <tr v-for="(order, index) in filteredOrders" :key="order.id">
              <td>{{ index + 1 }}</td>
              <td><Link v-if="order.order_url" class="order-link" :href="order.order_url">{{ order.erp_order_id ?? '—' }}</Link><span v-else>{{ order.erp_order_id ?? '—' }}</span></td>
              <td>{{ order.country ?? '—' }}</td>
              <td class="sku-cell" :title="itemSummary(order)">{{ itemSummary(order) }}</td>
              <td>
                <span v-if="order.allocation_decision.status === 'system_recommendation'" class="plan-chip good">{{ order.allocation_decision.recommended_plan?.label ?? '系统推荐' }}</span>
                <span v-else class="plan-chip warn">需要人工判断</span>
              </td>
              <td>{{ money(order.allocation_decision.estimated_cost, order.allocation_decision.currency) }}</td>
              <td><strong v-if="order.allocation_decision.estimated_saving != null && order.allocation_decision.estimated_saving > 0" class="saving">{{ money(order.allocation_decision.estimated_saving, order.allocation_decision.currency) }}</strong><span v-else>—</span></td>
              <td>
                <span v-if="processed(order)" class="status-chip done">已提交</span>
                <span v-else-if="order.allocation_decision.status === 'system_recommendation'" class="status-chip recommend">系统推荐</span>
                <span v-else class="status-chip manual">数据不足</span>
              </td>
              <td class="sticky-action"><button class="primary compact" type="button" @click="openPlan(order)">{{ processed(order) ? '查看记录' : '查看方案' }}</button></td>
            </tr>
            <tr v-if="filteredOrders.length === 0"><td colspan="9" class="empty">当前分类暂无订单</td></tr>
          </tbody>
        </table>
      </div>

      <footer class="page-foot">当前页面共 {{ props.orders.total }} 条候选订单；系统无法证明“更省钱”时不会给出强制推荐。</footer>
    </section>

    <Teleport to="body">
      <div v-if="selectedOrder" class="modal-backdrop" @click.self="closePlan">
        <section class="plan-modal" role="dialog" aria-modal="true" aria-label="履约方案详情">
          <header class="modal-header">
            <div><p class="eyebrow">Fulfillment Plan</p><h2>履约方案详情</h2></div>
            <button class="icon-button" type="button" aria-label="关闭" @click="closePlan">×</button>
          </header>

          <div class="order-meta">
            <div><small>订单号</small><strong>{{ selectedOrder.erp_order_id ?? '—' }}</strong></div>
            <div><small>国家/地区</small><strong>{{ selectedOrder.country ?? '—' }}</strong></div>
            <div><small>收件人</small><strong>{{ selectedOrder.recipient_name ?? '—' }}</strong></div>
            <div><small>决策状态</small><strong :class="selectedOrder.allocation_decision.status === 'system_recommendation' ? 'text-good' : 'text-warn'">{{ selectedOrder.allocation_decision.status_label }}</strong></div>
          </div>

          <template v-if="processed(selectedOrder)">
            <div class="record-banner">
              <div><strong>分仓已完成</strong><span>待分仓环节已经把物流执行对象固定下来；后续物流只处理下面的订单，不再重新理解原订单的拆分逻辑。</span></div>
              <span class="status-chip done">{{ selectedOrder.fulfillment_allocation?.decision_source === 'manual' ? '人工确认' : '采用系统推荐' }}</span>
            </div>

            <section class="derived-orders">
              <h3>{{ (selectedOrder.fulfillment_allocation?.plan_snapshot?.derived_orders?.length ?? 0) > 1 ? '衍生物流订单' : '物流执行订单' }}</h3>
              <div class="segment-grid">
                <article v-for="child in selectedOrder.fulfillment_allocation?.plan_snapshot?.derived_orders ?? []" :key="child.erp_order_id ?? child.order_id" class="segment-card derived-card">
                  <header>
                    <div><small>{{ child.derived ? `衍生订单 ${child.suffix ?? ''}` : '原订单直接履约' }}</small><h3>{{ child.erp_order_id ?? '—' }}</h3></div>
                    <span>{{ child.warehouse_name ?? child.warehouse_code ?? '—' }}</span>
                  </header>
                  <table><thead><tr><th>SKU</th><th>数量</th><th>发货仓</th></tr></thead><tbody><tr v-for="item in child.items" :key="`${child.order_id}-${item.sku ?? item.product_summary}`"><td>{{ item.sku ?? item.product_summary ?? '无 SKU' }}</td><td>{{ item.quantity }}</td><td>{{ child.warehouse_name ?? child.warehouse_code ?? '—' }}</td></tr></tbody></table>
                </article>
              </div>
              <p v-if="!(selectedOrder.fulfillment_allocation?.plan_snapshot?.derived_orders?.length)" class="record-warning">旧记录尚未生成物流执行订单，请重新提交该分仓方案。</p>
            </section>
          </template>

          <template v-else-if="!manualMode && selectedOrder.allocation_decision.recommended_plan">
            <div class="recommendation-banner">
              <div><strong>系统推荐：{{ selectedOrder.allocation_decision.recommended_plan.label }}</strong><span>基于当前库存覆盖与已具备的完整履约成本证据计算，仍需 Vita 人工确认。</span></div>
              <strong v-if="selectedOrder.allocation_decision.estimated_saving != null && selectedOrder.allocation_decision.estimated_saving > 0" class="saving">预计节省 {{ money(selectedOrder.allocation_decision.estimated_saving, selectedOrder.allocation_decision.currency) }}</strong>
            </div>

            <div class="segment-grid">
              <article v-for="segment in selectedOrder.allocation_decision.recommended_plan.segments" :key="segment.source_code" class="segment-card">
                <header><div><small>{{ segment.source_type === 'factory' ? '国内履约' : '海外履约' }}</small><h3>{{ segment.warehouse_name || sourceLabel(segment.source_code) }}</h3></div><span>{{ segment.source_code }}</span></header>
                <table><thead><tr><th>SKU</th><th>数量</th></tr></thead><tbody><tr v-for="item in segment.items" :key="`${segment.source_code}-${item.product_id}`"><td>{{ item.sku ?? item.product_summary ?? '无 SKU' }}</td><td>{{ item.quantity }}</td></tr></tbody></table>
              </article>
            </div>

            <section class="cost-comparison">
              <div><small>推荐方案成本</small><strong>{{ money(selectedOrder.allocation_decision.estimated_cost, selectedOrder.allocation_decision.currency) }}</strong></div>
              <div><small>对比方案</small><strong>{{ selectedOrder.allocation_decision.baseline_plan?.label ?? '—' }}</strong></div>
              <div><small>对比方案成本</small><strong>{{ money(selectedOrder.allocation_decision.baseline_plan?.effective_cost ?? selectedOrder.allocation_decision.baseline_plan?.cost.amount, selectedOrder.allocation_decision.baseline_plan?.cost.currency) }}</strong></div>
              <div><small>预计节省</small><strong class="saving">{{ money(selectedOrder.allocation_decision.estimated_saving, selectedOrder.allocation_decision.currency) }}</strong></div>
            </section>

            <section class="reason-box"><strong>推荐依据</strong><ol><li>所有 SKU 均按整行数量校验库存覆盖，不拆同一 SKU 的数量。</li><li>只在可比较的完整成本证据下排序；任何关键数据缺失都会退出系统推荐。</li><li v-if="selectedOrder.allocation_decision.recommended_plan.is_split">拆单方案仅允许一个海外仓 + 中国工厂，并计入已冻结的拆单附加成本。</li></ol></section>
          </template>

          <template v-else>
            <div class="manual-banner">
              <strong>需要人工判断</strong>
              <span>系统当前没有足够证据可靠判断最省钱方案。以下原因不会被按 0 元或默认值代替：</span>
              <ul><li v-for="reason in selectedOrder.allocation_decision.manual_reasons" :key="reason">{{ reason }}</li></ul>
            </div>

            <section class="manual-allocation">
              <h3>SKU 履约分配</h3>
              <p>请为每一个订单商品选择完整发货仓。V1 不允许把同一个 SKU 的数量拆到两个仓。</p>
              <div class="manual-table-wrap">
                <table>
                  <thead><tr><th>SKU</th><th>数量</th><th>库存参考</th><th>选择发货仓</th></tr></thead>
                  <tbody>
                    <tr v-for="item in selectedOrder.items" :key="item.id">
                      <td><strong>{{ item.sku ?? item.product_summary ?? '无 SKU' }}</strong></td>
                      <td>{{ item.quantity }}</td>
                      <td><div class="stock-list"><span v-for="warehouse in warehouseOptions" :key="warehouse.code" :class="availabilityClass(item, warehouse)">{{ warehouse.name }}：{{ availabilityLabel(item, warehouse) }}</span></div></td>
                      <td><select v-model="manualAssignments[selectedOrder.id][item.id]" :aria-label="`${item.sku ?? item.id} 发货仓`"><option value="">请选择</option><option v-for="warehouse in warehouseOptions" :key="warehouse.code" :value="warehouse.code">{{ warehouse.name }}</option></select></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p class="constraint">限制：最多两个履约来源；若拆单，只允许「一个海外仓 + 中国工厂」。</p>
            </section>
          </template>

          <label v-if="!processed(selectedOrder)" class="note-field">运营备注（选填）<textarea v-model="decisionNote" rows="2" placeholder="例如客户特殊要求、临时仓库情况、人工调整原因…" /></label>
          <div v-else-if="selectedOrder.fulfillment_allocation?.decision_note" class="record-note"><strong>运营备注</strong><span>{{ selectedOrder.fulfillment_allocation.decision_note }}</span></div>
          <p v-if="submitError" class="submit-error" role="alert">{{ submitError }}</p>

          <footer class="modal-actions">
            <button class="secondary" type="button" :disabled="submitting" @click="closePlan">关闭</button>
            <template v-if="!processed(selectedOrder) && props.canOperate">
              <button v-if="!manualMode" class="secondary blue" type="button" :disabled="submitting" @click="enterManual">调整方案</button>
              <button v-if="!manualMode && selectedOrder.allocation_decision.can_use_recommendation" class="primary" type="button" :disabled="submitting" @click="submitRecommended">{{ submitting ? '提交中…' : '采用推荐并提交分仓' }}</button>
              <button v-else class="primary" type="button" :disabled="submitting" @click="submitManual">{{ submitting ? '提交中…' : '确认人工分配并提交' }}</button>
            </template>
            <span v-else-if="processed(selectedOrder)" class="submitted-note">该订单分仓决策已提交</span>
          </footer>
        </section>
      </div>
    </Teleport>
  </AuthenticatedLayout>
</template>

<style scoped>
.allocation-page{display:grid;gap:14px;padding:18px 20px 28px;color:#172033}.page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.page-heading h1{margin:2px 0 5px;font-size:24px}.page-heading p{margin:0;color:#61708a;font-size:13px}.eyebrow{margin:0!important;color:#2563eb!important;font-size:11px!important;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.principle{display:grid;gap:2px;padding:9px 13px;border:1px solid #dbe7f5;border-radius:9px;background:#f7fbff;text-align:right;font-size:12px}.principle span{color:#64748b}.tabs{display:flex;gap:6px;border-bottom:1px solid #e6ebf1}.tabs button{border:0;background:transparent;padding:9px 13px;color:#59677c;font-size:13px;cursor:pointer;border-bottom:2px solid transparent}.tabs button span{margin-left:5px;color:#8a96a8}.tabs button.active{color:#1261d7;font-weight:700;border-bottom-color:#1d72f2}.rule-strip{display:flex;gap:20px;flex-wrap:wrap;padding:9px 12px;border:1px solid #e5ebf2;border-radius:8px;background:#fbfcfe;color:#536176;font-size:12px}.rule-strip span::before{content:'✓';margin-right:5px;color:#16a36a;font-weight:800}.table-wrap{overflow:auto;border:1px solid #e1e7ef;border-radius:9px;background:#fff}.allocation-table{width:100%;min-width:1080px;border-collapse:collapse;font-size:12px}.allocation-table th,.allocation-table td{padding:8px 10px;border-bottom:1px solid #edf0f4;text-align:left;white-space:nowrap}.allocation-table th{background:#f5f8fb;color:#526076;font-weight:700}.allocation-table tbody tr:hover td{background:#fbfdff}.sku-cell{max-width:280px;overflow:hidden;text-overflow:ellipsis}.order-link{color:#1261d7;font-weight:700;text-decoration:none}.plan-chip,.status-chip{display:inline-flex;align-items:center;min-height:24px;padding:2px 9px;border-radius:6px;font-weight:700}.plan-chip.good{background:#e8f8ef;color:#11834f}.plan-chip.warn{background:#fff3dd;color:#b8650b}.status-chip.recommend{background:#e8f1ff;color:#1764ce}.status-chip.manual{background:#fff1dc;color:#b86100}.status-chip.done{background:#e9f8ef;color:#16814f}.saving{color:#09945b}.sticky-action{position:sticky;right:0;z-index:2;background:#fff!important;box-shadow:-6px 0 8px rgba(24,39,75,.035)}th.sticky-action{z-index:3;background:#f5f8fb!important}.primary,.secondary{min-height:34px;padding:7px 15px;border-radius:7px;border:1px solid transparent;font-weight:700;font-size:12px;cursor:pointer}.primary{background:#126cf3;color:#fff}.primary.compact{min-height:28px;padding:4px 14px}.primary:disabled,.secondary:disabled{opacity:.55;cursor:not-allowed}.secondary{background:#fff;border-color:#d5dde8;color:#36445b}.secondary.blue{color:#1261d7;border-color:#a9c8f6}.empty{text-align:center!important;color:#8a96a8;padding:26px!important}.page-foot{font-size:11px;color:#7d899a}.record-banner{display:flex;justify-content:space-between;align-items:center;gap:18px;margin:14px 18px;padding:12px 14px;border:1px solid #b9e2c9;border-radius:8px;background:#eefaf3}.record-banner>div{display:grid;gap:4px}.record-banner strong{color:#12663f;font-size:14px}.record-banner span:not(.status-chip){color:#3f5f50;font-size:13px;line-height:1.55}.derived-orders{margin:14px 18px}.derived-orders>h3{margin:0 0 8px;font-size:15px;color:#111827}.derived-card header>span{color:#0f5f42;font-size:12px;font-weight:700}.record-warning{padding:10px 12px;border-radius:7px;background:#fff7e8;color:#8a4600}.record-note{display:grid;gap:5px;margin:14px 18px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc;color:#374151;font-size:13px}
.modal-backdrop{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:24px;background:rgba(17,28,45,.55)}.plan-modal{width:min(900px,96vw);max-height:92vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 22px 70px rgba(15,23,42,.28);color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;font-size:13px}.modal-header{position:sticky;top:0;z-index:4;display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid #e7ebf1;background:#fff}.modal-header h2{margin:2px 0 0;font-size:20px;line-height:1.35;color:#111827;font-weight:750}.icon-button{border:0;background:transparent;color:#64748b;font-size:25px;cursor:pointer}.order-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin:14px 18px;border:1px solid #e5eaf0;border-radius:8px;overflow:hidden}.order-meta div{display:grid;gap:4px;padding:10px 12px;border-right:1px solid #e9edf2}.order-meta div:last-child{border-right:0}.order-meta small{color:#4b5563;font-size:12px;font-weight:600}.order-meta strong{font-size:13px;color:#111827;font-weight:700}.text-good{color:#0a8d55}.text-warn{color:#bd6908}.recommendation-banner,.manual-banner{margin:14px 18px;padding:12px 14px;border-radius:8px}.recommendation-banner{display:flex;justify-content:space-between;gap:20px;align-items:center;background:#eaf8ef;border:1px solid #ccebd8}.recommendation-banner div{display:grid;gap:4px}.recommendation-banner span,.manual-banner span{color:#475569;font-size:13px;line-height:1.55}.manual-banner{background:#fff7e8;border:1px solid #e9bf77;color:#8a4600}.manual-banner{display:grid;gap:5px}.manual-banner ul{margin:2px 0 0;padding-left:18px;font-size:13px;line-height:1.7;color:#7c3f00}.segment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin:14px 18px}.segment-card{border:1px solid #dfe6ee;border-radius:8px;overflow:hidden}.segment-card>header{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;background:#f8fafc}.segment-card h3{margin:2px 0 0;font-size:14px;color:#175ebf}.segment-card small,.segment-card>header>span{color:#475569;font-size:11px;font-weight:600}.segment-card table{width:100%;border-collapse:collapse;font-size:13px;color:#1f2937}.segment-card th,.segment-card td{padding:8px 10px;border-top:1px solid #e5e7eb;text-align:left}.segment-card th{color:#475569;font-weight:700}.segment-card td{color:#111827}.cost-comparison{display:grid;grid-template-columns:repeat(4,1fr);margin:14px 18px;border:1px solid #dce6e2;border-radius:8px;overflow:hidden;background:#f4fbf7}.cost-comparison div{display:grid;gap:4px;padding:10px 12px;border-right:1px solid #dce6e2}.cost-comparison div:last-child{border-right:0}.cost-comparison small{color:#475569;font-size:12px;font-weight:600}.cost-comparison strong{font-size:15px;color:#111827;font-weight:750}.reason-box{margin:14px 18px;padding:12px 14px;border-radius:8px;background:#eff6ff;border:1px solid #bfd7f4;color:#23466f}.reason-box ol{margin:6px 0 0;padding-left:18px;font-size:13px;line-height:1.75;color:#1f456f}.manual-allocation{margin:14px 18px}.manual-allocation h3{margin:0 0 4px;font-size:15px;color:#111827;font-weight:750}.manual-allocation>p{margin:0 0 10px;color:#475569;font-size:13px;line-height:1.55}.manual-table-wrap{overflow:auto;border:1px solid #e1e7ef;border-radius:8px}.manual-table-wrap table{width:100%;min-width:720px;border-collapse:collapse;font-size:13px;color:#1f2937}.manual-table-wrap th,.manual-table-wrap td{padding:8px;border-bottom:1px solid #edf1f5;text-align:left;vertical-align:top}.manual-table-wrap th{background:#f7f9fc;color:#475569;font-weight:700}.manual-table-wrap td{color:#111827}.manual-table-wrap select{min-width:150px;padding:7px 9px;border:1px solid #aebdce;border-radius:6px;background:#fff;color:#111827;font-size:13px}.stock-list{display:flex;gap:5px;flex-wrap:wrap}.stock-list span{padding:3px 7px;border-radius:4px;font-size:11px;font-weight:650}.stock-ok{background:#e6f8ed;color:#087b47}.stock-short{background:#ffe8e8;color:#b42c2c}.stock-unknown{background:#fff0d7;color:#8b4b00}.stock-neutral{background:#eaf1fb;color:#334e6f}.constraint{margin-top:8px!important;color:#8a4600!important;font-weight:650}.note-field{display:grid;gap:6px;margin:14px 18px;color:#374151;font-size:13px;font-weight:600}.note-field textarea{padding:10px 11px;border:1px solid #b8c4d1;border-radius:7px;resize:vertical;color:#111827;font-size:13px;line-height:1.5}.note-field textarea::placeholder{color:#6b7280}.submit-error{margin:0 18px;padding:8px 10px;border-radius:6px;background:#fff0f0;color:#b42318;font-size:12px}.modal-actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;align-items:center;gap:9px;padding:13px 18px;border-top:1px solid #e7ebf1;background:#fff}.submitted-note{color:#16814f;font-weight:700;font-size:12px}@media(max-width:760px){.allocation-page{padding:12px}.page-heading{display:grid}.principle{text-align:left}.order-meta{grid-template-columns:1fr 1fr}.order-meta div:nth-child(2){border-right:0}.cost-comparison{grid-template-columns:1fr 1fr}.cost-comparison div:nth-child(2){border-right:0}.recommendation-banner{align-items:flex-start;display:grid}.modal-backdrop{padding:8px}.plan-modal{width:100%;max-height:96vh}}
</style>
