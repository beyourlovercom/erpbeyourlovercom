<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const commonCurrencyOptions = [
  { value: 'USD', label: 'USD · 美元' },
  { value: 'CAD', label: 'CAD · 加拿大元' },
  { value: 'HKD', label: 'HKD · 港币' },
  { value: 'EUR', label: 'EUR · 欧元' },
  { value: 'CNY', label: 'CNY · 人民币' },
  { value: 'GBP', label: 'GBP · 英镑' },
  { value: 'AUD', label: 'AUD · 澳元' },
  { value: 'JPY', label: 'JPY · 日元' },
  { value: 'SGD', label: 'SGD · 新加坡元' },
]
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type Availability = { warehouse_id: number; warehouse_slug: string; warehouse_name: string; inventory_state: string; can_auto_allocate: boolean; quantity_on_hand: number | null; quantity_reserved: number | null; quantity_available: number | null }
type Warehouse = { id: number; slug: string; name: string; code: string; country_code: string | null; can_auto_allocate?: boolean }
type AllocationUi = { status: 'pending' | 'submitted' | 'locked'; can_operate: boolean; requires_full_flow: boolean; selectable_warehouse_ids: number[]; recommended_warehouse_ids: Record<number, number | null>; selected_warehouse_ids: Record<number, number | null>; recommended_plan_key: string | null; can_use_recommendation: boolean; full_allocation_url: string }

type OrderItem = {
  id: number
  product_id: number | null
  product_summary: string
  sku: string | null
  quantity: number
  unit_price: string
}

type PackagingReference = {
  source: 'current_product_standard' | 'shipment_snapshot'
  source_label: string
  measurement_record_id: number | null
  packaging_method_key: string | null
  packaging_method_label: string | null
  packaging_length_cm: string
  packaging_width_cm: string
  packaging_height_cm: string
  packaged_weight_kg: string
  measured_at?: string | null
  captured_at?: string | null
}

type Order = {
  id: number
  erp_order_id?: string | null
  source_type: string
  source_label: string
  order_kind: string | null
  order_kind_label: string | null
  order_date: string
  order_status: string
  order_status_label: string
  order_note: string | null
  currency: string
  owner: { id: number; name: string } | null
  owner_label: string | null
  address: Record<string, string> | null
  items: OrderItem[]
  shipment: {
    shipment_status: string
    warehouse_id?: number | null
    warehouse_name?: string | null
    warehouse_selection_source?: string | null
    carrier: string | null
    service?: string | null
    carrier_selection_source?: string | null
    tracking_number: string | null
    shipped_at: string | null
  } | null
  manual_sync: { status: string; synced_at: string | null } | null
  operational_markers: string[]
  procurement_order: { id: number; procurement_number: string } | null
}

const props = withDefaults(defineProps<{
  order: Order
  inventoryAvailability?: Record<number, Availability[]>
  packagingReferences?: Record<number, PackagingReference>
  warehouses?: Warehouse[]
  allocationUi?: AllocationUi
  audit: { action: string; actor_id: number | null; created_at: string; reason?: string }[]
  canMarkOperational?: boolean
  canManageProductPackaging?: boolean
  canViewPackagingEvidence?: boolean
  canCreateProcurement?: boolean
  canViewProfit?: boolean
  canOperateLogistics?: boolean
}>(), { canMarkOperational: false, canManageProductPackaging: false, canViewPackagingEvidence: false, canCreateProcurement: false, canViewProfit: false, canOperateLogistics: false })

const isKol = computed(() => props.order.order_kind === 'kol_fulfillment')
const basePath = computed(() => isKol.value ? '/kol/orders' : '/customers/orders')

const breadcrumbs = computed(() => isKol.value
  ? [{ label: 'BYL ERP', href: '/dashboard' }, { label: '网红', href: '/kol' }, { label: '订单', href: '/kol/orders' }, props.order.erp_order_id ?? '—']
  : [{ label: 'BYL ERP', href: '/dashboard' }, { label: '客户', href: '/customers' }, { label: '订单', href: '/customers/orders' }, props.order.erp_order_id ?? '—']
)

type EditItem = {
  id: number
  product_id: number | null
  sku: string
  product_summary: string
  quantity: number
  unit_price: string
}

const makeFormData = () => ({
  order_date: props.order.order_date ?? '',
  order_note: props.order.order_note ?? '',
  currency: props.order.currency ?? 'USD',
  recipient_name: props.order.address?.recipient_name ?? '',
  phone: props.order.address?.phone ?? '',
  country: props.order.address?.country ?? '',
  province_state: props.order.address?.province_state ?? '',
  city: props.order.address?.city ?? '',
  address_line_1: props.order.address?.address_line_1 ?? '',
  address_line_2: props.order.address?.address_line_2 ?? '',
  postal_code: props.order.address?.postal_code ?? '',
  items: props.order.items.map(item => ({
    id: item.id,
    product_id: item.product_id,
    sku: item.sku ?? '',
    product_summary: item.product_summary ?? '',
    quantity: item.quantity,
    unit_price: item.unit_price,
  })) as EditItem[],
})

const form = useForm(makeFormData())

const currencyOptions = computed(() => {
  const current = form.currency || props.order.currency || 'USD'
  return commonCurrencyOptions.some(option => option.value === current)
    ? commonCurrencyOptions
    : [{ value: current, label: current }, ...commonCurrencyOptions]
})

const editMode = ref(false)

const enterEditMode = () => { editMode.value = true }

const cancelEdit = () => {
  form.reset()
  Object.assign(form, makeFormData())
  form.clearErrors()
  editMode.value = false
}

const submit = () => form.patch(`${basePath.value}/${props.order.id}`, {
  onSuccess: () => { editMode.value = false },
})

const operationalMarkerOptions = [
  { key: 'exception', label: '异常' },
  { key: 'in_transit', label: '运输中' },
  { key: 'return', label: '退货' },
  { key: 'stockout', label: '缺货' },
]
const markerActive = (key: string) => props.order.operational_markers?.includes(key) ?? false
const toggleMarker = (key: string) => router.post(`/orders/${props.order.id}/operational-markers/${key}`, { active: !markerActive(key) }, { preserveScroll: true })

const shipmentStatusLabel = computed(() => {
  const s = props.order.shipment?.shipment_status
  if (s === 'shipped') return '已发货'
  if (s === 'completed') return '已完结'
  if (s === 'exception') return '异常'
  if (s === 'returned') return '已退回'
  return '待发货'
})

const allocationSelections = ref<Record<number, number | null>>(Object.fromEntries(props.order.items.map(item => [item.id, props.allocationUi?.selected_warehouse_ids?.[item.id] ?? props.allocationUi?.recommended_warehouse_ids?.[item.id] ?? null])))
const allocationSubmitting = ref(false)
const allocationSelectable = (warehouseId: number) => props.allocationUi?.selectable_warehouse_ids?.includes(warehouseId) ?? false
const allocationSelected = (itemId: number) => allocationSelections.value[itemId] ?? null
const allocationRecommended = (itemId: number, warehouseId: number) => props.allocationUi?.recommended_warehouse_ids?.[itemId] === warehouseId
const selectAllocationWarehouse = (itemId: number, warehouseId: number) => { allocationSelections.value = { ...allocationSelections.value, [itemId]: warehouseId } }
const allocationSelectedCount = computed(() => props.order.items.filter(item => allocationSelected(item.id) !== null).length)
const allocationReady = computed(() => props.order.items.length > 0 && allocationSelectedCount.value === props.order.items.length)
const allocationMatchesRecommendation = computed(() => Boolean(props.allocationUi?.can_use_recommendation && props.allocationUi?.recommended_plan_key) && props.order.items.every(item => {
  const recommended = props.allocationUi?.recommended_warehouse_ids?.[item.id] ?? null
  return recommended !== null && allocationSelected(item.id) === recommended
}))
const submitAllocation = () => {
  if (!props.allocationUi?.can_operate || props.allocationUi.requires_full_flow || !allocationReady.value || allocationSubmitting.value) return
  allocationSubmitting.value = true
  const options = { preserveScroll: true, onFinish: () => { allocationSubmitting.value = false } }
  if (allocationMatchesRecommendation.value && props.allocationUi.recommended_plan_key) {
    router.post(`/logistics/orders/${props.order.id}/fulfillment-allocation/recommended`, { plan_key: props.allocationUi.recommended_plan_key, decision_note: null }, options)
    return
  }
  router.post(`/logistics/orders/${props.order.id}/fulfillment-allocation/quick`, { assignments: props.order.items.map(item => ({ order_item_id: item.id, warehouse_id: allocationSelected(item.id) })) }, options)
}

</script>

<template>
  <Head :title="`${order.erp_order_id ?? '—'} · BYL ERP`" />
  <AuthenticatedLayout :title="`订单 ${order.erp_order_id ?? '—'}`" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <div class="erp-form-header-row">
          <div>
            <p class="ord-kicker">{{ order.order_kind_label }}</p>
            <h1 class="erp-form-title">{{ order.erp_order_id ?? '—' }}</h1>
            <p class="erp-form-subtitle">查看订单事实；进入编辑模式后可维护允许修改的订单字段。</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <button v-if="editMode" type="button" class="erp-btn erp-btn-secondary" @click="cancelEdit">取消编辑</button>
            <a :href="basePath" class="erp-btn erp-btn-secondary">返回列表</a>
            <a v-if="canOperateLogistics && order.order_status === 'pending_shipment'" :href="`/logistics/pending?order_id=${order.id}`" class="erp-btn erp-btn-primary">履约发货</a>
            <a v-if="canViewProfit && !isKol" :href="`/accounting/order-costs?search=${encodeURIComponent(order.erp_order_id ?? '')}`" class="erp-btn erp-btn-secondary">查看订单利润</a>
            <a v-if="canCreateProcurement && !order.procurement_order" :href="`/purchase/orders/from-order/${order.id}/create`" class="erp-btn erp-btn-primary">创建采购订单</a>
            <a v-if="order.procurement_order" :href="`/purchase/orders/${order.procurement_order.id}`" class="erp-btn erp-btn-secondary">查看采购订单 · {{ order.procurement_order.procurement_number }}</a>
            <button v-if="!editMode" type="button" class="erp-btn erp-btn-primary" @click="enterEditMode">编辑订单</button>
            <button v-else type="button" class="erp-btn erp-btn-primary" :disabled="form.processing" @click="submit">保存订单</button>
          </div>
        </div>

        <!-- Identity strip -->
        <div class="ord-identity-strip">
          <div class="ord-identity-main">
            <span class="ord-identity-number">{{ order.erp_order_id ?? '—' }}</span>
            <span class="ord-identity-badge">{{ order.order_kind_label }}</span>
            <span class="ord-identity-badge ord-badge-muted">{{ order.source_label }}</span>
            <span class="ord-identity-badge ord-badge-status">{{ order.order_status_label }}</span>
            <span class="ord-identity-owner">负责人：{{ order.owner?.name ?? '—' }}</span>
          </div>

        </div>

        <!-- Card 1: 履约状态 (always read-only) -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">履约状态</h2>
          <div class="ord-fulfillment-grid">
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">发货状态</small>
              <strong class="ord-fulfillment-value">{{ shipmentStatusLabel }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">发货仓库</small>
              <strong class="ord-fulfillment-value">{{ order.shipment?.warehouse_name ?? '未选择' }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">承运商</small>
              <strong class="ord-fulfillment-value">{{ order.shipment?.carrier ?? '—' }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">物流渠道</small>
              <strong class="ord-fulfillment-value">{{ order.shipment?.service ?? '—' }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">追踪号</small>
              <strong class="ord-fulfillment-value">{{ order.shipment?.tracking_number ?? '—' }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">网站同步</small>
              <strong class="ord-fulfillment-value">{{ order.manual_sync?.status ?? '未进入队列' }}</strong>
            </div>
          </div>
          <div v-if="canOperateLogistics && order.shipment?.tracking_number" class="ord-fulfillment-action">
            <button
              type="button"
              class="erp-btn erp-btn-secondary"
              @click="router.post(`/logistics/orders/${order.id}/reopen`)"
            >
              重开发货
            </button>
          </div>
        </div>

        <!-- Operational markers: explicit human marks, not a second Order status -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">业务标记</h2>
          <p class="ord-inventory-help">用于异常、运输中、退货、缺货等业务视图。标记不会改写订单主状态；缺货视图还会再次核对所选仓库真实可用库存。</p>
          <div class="ord-marker-row">
            <button v-for="marker in operationalMarkerOptions" :key="marker.key" type="button" class="erp-btn erp-btn-secondary ord-marker" :class="{ active: markerActive(marker.key) }" :disabled="!canMarkOperational" @click="toggleMarker(marker.key)">{{ markerActive(marker.key) ? '✓ ' : '' }}{{ marker.label }}</button>
          </div>
        </div>

        <!-- Inventory distribution -->
        <div v-for="item in order.items" :key="`inventory-${item.id}`" class="erp-form-card ord-inventory-card">
          <h2 class="erp-form-card-title">{{ item.sku || '未绑定 SKU' }} 库存分布</h2>
          <div class="ord-inventory-table-wrap">
            <table class="ord-inventory-table">
              <thead>
                <tr>
                  <th>仓库</th>
                  <th>SKU</th>
                  <th>是否有货</th>
                  <th>可用库存</th>
                  <th>QOH</th>
                  <th>Reserved</th>
                  <th>备注</th>
                  <th v-if="allocationUi">分仓</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in inventoryAvailability?.[item.product_id ?? 0] ?? []" :key="row.warehouse_id">
                  <td class="ord-inventory-warehouse-name">{{ row.warehouse_name }}</td>
                  <td class="ord-inventory-sku-value">{{ item.sku || '—' }}</td>
                  <td>
                    <span class="ord-stock-status" :class="row.quantity_available === null ? 'unknown' : row.quantity_available > 0 ? 'in-stock' : 'out-of-stock'">
                      <span class="ord-stock-status-icon">{{ row.quantity_available === null ? '?' : row.quantity_available > 0 ? '✓' : '×' }}</span>
                      {{ row.quantity_available === null ? '未知' : row.quantity_available > 0 ? '有货' : '无货' }}
                    </span>
                  </td>
                  <td>{{ row.quantity_available ?? '—' }}</td>
                  <td>{{ row.quantity_on_hand ?? '—' }}</td>
                  <td>{{ row.quantity_reserved ?? '—' }}</td>
                  <td class="ord-inventory-note">{{ row.quantity_available === null ? '未建立 SKU 权威库存' : '—' }}</td>
                  <td v-if="allocationUi" class="ord-allocation-cell">
                    <label v-if="allocationUi.status === 'pending' && allocationUi.can_operate && !allocationUi.requires_full_flow && allocationSelectable(row.warehouse_id)" class="ord-allocation-choice" :class="{ selected: allocationSelected(item.id) === row.warehouse_id }">
                      <input type="radio" :name="`allocation-${item.id}`" :checked="allocationSelected(item.id) === row.warehouse_id" @change="selectAllocationWarehouse(item.id, row.warehouse_id)">
                      <span>{{ allocationSelected(item.id) === row.warehouse_id ? '已选' : '选择' }}</span>
                      <small v-if="allocationRecommended(item.id, row.warehouse_id)">推荐</small>
                    </label>
                    <span v-else-if="allocationUi.status === 'submitted' && allocationUi.selected_warehouse_ids?.[item.id] === row.warehouse_id" class="ord-allocation-fixed">✓ 已选仓库</span>
                    <span v-else-if="allocationRecommended(item.id, row.warehouse_id)" class="ord-allocation-recommended">推荐</span>
                    <span v-else>—</span>
                  </td>
                </tr>
                <tr v-if="(inventoryAvailability?.[item.product_id ?? 0] ?? []).length === 0">
                  <td :colspan="allocationUi ? 8 : 7" class="ord-inventory-empty">暂无仓库库存数据</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="allocationUi?.status === 'pending'" class="ord-allocation-action-bar">
          <div>
            <strong>待分仓 · {{ allocationSelectedCount }}/{{ order.items.length }} SKU 已选择</strong>
            <span v-if="allocationUi.requires_full_flow">该订单包含 gift，需要选择实际履约 SKU。</span>
            <span v-else>库存表即仓库选择器；提交后这里变为只读“已选仓库”。</span>
          </div>
          <div class="ord-allocation-actions">
            <a :href="allocationUi.full_allocation_url" class="erp-btn erp-btn-secondary">完整分仓</a>
            <button v-if="allocationUi.can_operate && !allocationUi.requires_full_flow" type="button" class="erp-btn erp-btn-primary" :disabled="!allocationReady || allocationSubmitting" @click="submitAllocation">{{ allocationSubmitting ? '提交中…' : allocationMatchesRecommendation ? '采用推荐并提交分仓' : '确认分仓' }}</button>
          </div>
        </div>

        <!-- Product packaging standard / fulfillment snapshot -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">包装与重量参考</h2>
          <p class="ord-inventory-help">待发货订单读取产品当前包装标准；进入履约后保存为订单商品履约快照，后续产品标准变化不会改写已冻结的发货参考。</p>
          <div v-for="item in order.items" :key="`packaging-${item.id}`" class="ord-packaging-row">
            <div class="ord-packaging-identity">
              <strong>{{ item.sku || '—' }}</strong>
              <small>{{ item.product_summary }} · 数量 {{ item.quantity }}</small>
              <a v-if="canManageProductPackaging && item.product_id" class="ord-packaging-link" :href="`/products/${item.product_id}/packaging`">包装方案 →</a>
            </div>
            <template v-if="packagingReferences?.[item.id]">
              <div class="ord-packaging-metric"><small>包装方式</small><strong>{{ packagingReferences[item.id].packaging_method_label || '未指定' }}</strong></div>
              <div class="ord-packaging-metric"><small>包装尺寸</small><strong>{{ packagingReferences[item.id].packaging_length_cm }} × {{ packagingReferences[item.id].packaging_width_cm }} × {{ packagingReferences[item.id].packaging_height_cm }} cm</strong></div>
              <div class="ord-packaging-metric"><small>打包后重量</small><strong>{{ packagingReferences[item.id].packaged_weight_kg }} kg</strong></div>
              <div class="ord-packaging-source">
                <span>{{ packagingReferences[item.id].source_label }}</span>
                <a v-if="canViewPackagingEvidence && packagingReferences[item.id].measurement_record_id" class="ord-packaging-link" :href="`/inventory/packaging/${packagingReferences[item.id].measurement_record_id}`">查看包装记录 #{{ packagingReferences[item.id].measurement_record_id }} →</a>
                <small v-else>测量记录 #{{ packagingReferences[item.id].measurement_record_id }}</small>
              </div>
            </template>
            <div v-else class="ord-packaging-empty">该产品尚未设定“当前包装标准”</div>
          </div>
        </div>

        <!-- Card 2: 订单信息 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">订单信息</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field">
              <label class="erp-form-label">订单号</label>
              <input :value="order.erp_order_id ?? '—'" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单类型</label>
              <input :value="order.order_kind_label" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">来源</label>
              <input :value="order.source_label" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">负责人</label>
              <input :value="order.owner?.name ?? '—'" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单币种</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.currency }}</div>
              <template v-else>
                <select v-model="form.currency" class="erp-input">
                  <option v-for="option in currencyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="form.errors.currency" class="erp-field-error">{{ form.errors.currency }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单日期</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.order_date }}</div>
              <input v-else v-model="form.order_date" type="date" class="erp-input" :class="{ 'has-error': form.errors.order_date }">
              <p v-if="form.errors.order_date" class="erp-field-error">{{ form.errors.order_date }}</p>
            </div>
          </div>
        </div>

        <!-- Card 3: 收件与地址 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">收件与地址</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field">
              <label class="erp-form-label">收件人姓名</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.recipient_name || '—' }}</div>
              <template v-else>
                <input v-model="form.recipient_name" class="erp-input" placeholder="可选">
                <p v-if="form.errors.recipient_name" class="erp-field-error">{{ form.errors.recipient_name }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">联系电话</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.phone || '—' }}</div>
              <template v-else>
                <input v-model="form.phone" class="erp-input" placeholder="可选">
                <p v-if="form.errors.phone" class="erp-field-error">{{ form.errors.phone }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">国家</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.country || '—' }}</div>
              <template v-else>
                <input v-model="form.country" class="erp-input" placeholder="可选">
                <p v-if="form.errors.country" class="erp-field-error">{{ form.errors.country }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">省/州</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.province_state || '—' }}</div>
              <template v-else>
                <input v-model="form.province_state" class="erp-input" placeholder="可选">
                <p v-if="form.errors.province_state" class="erp-field-error">{{ form.errors.province_state }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">城市</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.city || '—' }}</div>
              <template v-else>
                <input v-model="form.city" class="erp-input" placeholder="可选">
                <p v-if="form.errors.city" class="erp-field-error">{{ form.errors.city }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">邮政编码</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.postal_code || '—' }}</div>
              <template v-else>
                <input v-model="form.postal_code" class="erp-input" placeholder="可选">
                <p v-if="form.errors.postal_code" class="erp-field-error">{{ form.errors.postal_code }}</p>
              </template>
            </div>
            <div class="erp-form-field ord-span-2">
              <label class="erp-form-label">地址行 1</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.address_line_1 || '—' }}</div>
              <template v-else>
                <input v-model="form.address_line_1" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_1" class="erp-field-error">{{ form.errors.address_line_1 }}</p>
              </template>
            </div>
            <div class="erp-form-field ord-span-2">
              <label class="erp-form-label">地址行 2</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.address_line_2 || '—' }}</div>
              <template v-else>
                <input v-model="form.address_line_2" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_2" class="erp-field-error">{{ form.errors.address_line_2 }}</p>
              </template>
            </div>
          </div>
        </div>

        <!-- Card 4: 订单商品 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">订单商品</h2>
          <!-- View mode: read-only item list -->
          <template v-if="!editMode">
            <div v-for="item in order.items" :key="item.id" class="ord-item-row-view">
              <div class="erp-form-field">
                <label class="erp-form-label">SKU / 产品</label>
                <div class="erp-readonly-input">{{ item.sku || '—' }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品摘要</label>
                <div class="erp-readonly-input">{{ item.product_summary || '—' }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <div class="erp-readonly-input">{{ item.quantity }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">单价</label>
                <div class="erp-readonly-input">{{ order.currency }} {{ item.unit_price }}</div>
              </div>
            </div>
          </template>
          <!-- Edit mode: editable item rows with SkuCombobox -->
          <template v-else>
            <div v-for="(item, index) in form.items" :key="item.id" class="ord-item-row">
              <div class="ord-item-sku">
                <label class="erp-form-label">SKU / 产品</label>
                <SkuCombobox
                  v-model="item.sku"
                  :product-id="item.product_id"
                  @update:product-id="(id) => { item.product_id = id }"
                />
                <p v-if="(form.errors as Record<string, string>)[`items.${index}.product_id`]" class="erp-field-error">
                  {{ (form.errors as Record<string, string>)[`items.${index}.product_id`] }}
                </p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品摘要</label>
                <input v-model="item.product_summary" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <input v-model="item.quantity" type="number" min="1" class="erp-input" required>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">单价（{{ form.currency }}）</label>
                <input v-model="item.unit_price" type="number" min="0" step="0.01" class="erp-input" required>
              </div>
            </div>
          </template>
        </div>

        <!-- Card 5: 内部备注 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">内部备注</h2>
          <div class="erp-form-field">
            <label class="erp-form-label">备注内容</label>
            <div v-if="!editMode" class="erp-readonly-input ord-readonly-textarea">{{ order.order_note || '—' }}</div>
            <template v-else>
              <textarea v-model="form.order_note" class="erp-textarea" placeholder="可选" />
              <p v-if="form.errors.order_note" class="erp-field-error">{{ form.errors.order_note }}</p>
            </template>
          </div>
        </div>

        <!-- Audit log -->
        <div class="erp-form-card ord-audit-card">
          <h2 class="erp-form-card-title">操作审计</h2>
          <ul class="ord-audit-list">
            <li v-for="event in audit" :key="event.created_at + event.action" class="ord-audit-item">
              <span class="ord-audit-action">{{ event.action }}</span>
              <small class="ord-audit-meta">{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.erp-form-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
}

/* Identity strip — order-specific, no erp-* equivalent */
.ord-identity-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1px solid #e4eaed;
  border-radius: 16px;
  padding: 16px 24px;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}

.ord-identity-main {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.ord-identity-number {
  font-size: 18px;
  font-weight: 600;
  color: #142126;
}

.ord-identity-badge {
  font-size: 12px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 6px;
  background: #e4eaed;
  color: #344b53;
}

.ord-badge-muted {
  background: #f4f7f8;
  color: #7a8c94;
}

.ord-badge-status {
  background: #d1fae5;
  color: #065f46;
}

.ord-identity-owner {
  font-size: 13px;
  color: #7a8c94;
}

.ord-identity-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ord-details-disclosure {
  font-size: 12px;
  color: #7a8c94;
}

.ord-details-summary {
  cursor: pointer;
  font-size: 12px;
  color: #7a8c94;
}

.ord-details-value {
  display: block;
  font-size: 12px;
  color: #344b53;
  margin-top: 4px;
  font-family: monospace;
}

/* Fulfillment card layout — no erp-* equivalent */
.ord-fulfillment-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}

.ord-fulfillment-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ord-fulfillment-label {
  font-size: 12px;
  color: #7a8c94;
}

.ord-fulfillment-value {
  font-size: 14px;
  color: #142126;
}

.ord-fulfillment-action {
  padding-top: 8px;
  border-top: 1px solid #e4eaed;
}

.ord-inventory-help { margin: -4px 0 12px; font-size: 12px; color: #7a8c94; }
.ord-inventory-card { overflow: hidden; }
.ord-inventory-table-wrap { width: 100%; margin-top: 10px; overflow-x: auto; }
.ord-inventory-table { width: 100%; min-width: 820px; border: 1px solid #dde5e8; border-radius: 10px; border-spacing: 0; border-collapse: separate; overflow: hidden; font-size: 13px; }
.ord-inventory-table th,
.ord-inventory-table td { padding: 9px 12px; border-right: 1px solid #e4eaed; border-bottom: 1px solid #e4eaed; text-align: left; vertical-align: middle; white-space: nowrap; }
.ord-inventory-table th:last-child,
.ord-inventory-table td:last-child { border-right: 0; }
.ord-inventory-table tbody tr:last-child td { border-bottom: 0; }
.ord-inventory-table th { background: #f5f7f8; color: #263b43; font-weight: 700; }
.ord-inventory-table td { color: #31464e; }
.ord-inventory-warehouse-name { font-weight: 600; color: #1f333a; }
.ord-inventory-sku-value { font-weight: 600; color: #263b43; }
.ord-stock-status { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; }
.ord-stock-status-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 999px; color: #fff; font-size: 12px; line-height: 1; }
.ord-stock-status.in-stock { color: #16705f; }
.ord-stock-status.in-stock .ord-stock-status-icon { background: #1f9d7a; }
.ord-stock-status.out-of-stock { color: #df4453; }
.ord-stock-status.out-of-stock .ord-stock-status-icon { background: #ef4454; }
.ord-stock-status.unknown { color: #7b8b93; }
.ord-stock-status.unknown .ord-stock-status-icon { background: #8c9aa1; }
.ord-inventory-note { color: #5f727a; }
.ord-inventory-empty { padding: 18px 12px !important; text-align: center !important; color: #87969c !important; }
.ord-allocation-cell { white-space: nowrap; }
.ord-allocation-choice { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 700; color: #475569; }
.ord-allocation-choice input { margin: 0; }
.ord-allocation-choice.selected { color: #087f5b; }
.ord-allocation-choice small, .ord-allocation-recommended { display: inline-flex; padding: 2px 6px; border-radius: 999px; background: #e8f8ef; color: #087f5b; font-size: 10px; font-weight: 800; }
.ord-allocation-fixed { color: #087f5b; font-weight: 800; }
.ord-allocation-action-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: -2px; padding: 10px 14px; border: 1px solid #dce5df; border-radius: 10px; background: #f8fbf9; }
.ord-allocation-action-bar > div:first-child { display: grid; gap: 3px; }
.ord-allocation-action-bar span { color: #64748b; font-size: 12px; }
.ord-allocation-actions { display: flex; align-items: center; gap: 8px; }

.ord-packaging-row { display: grid; grid-template-columns: 1.2fr 1fr 1.35fr .8fr .9fr; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid #e4eaed; }
.ord-packaging-row:last-child { border-bottom: 0; }
.ord-packaging-identity,.ord-packaging-metric,.ord-packaging-source { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.ord-packaging-identity small,.ord-packaging-metric small,.ord-packaging-source small { color: #7a8c94; font-size: 11px; }
.ord-packaging-metric strong { color: #263b43; font-size: 13px; }
.ord-packaging-source span { width: fit-content; padding: 4px 8px; border-radius: 999px; background: #eef7f2; color: #16705f; font-size: 11px; font-weight: 700; }
.ord-packaging-empty { grid-column: 2 / -1; padding: 10px 12px; border-radius: 8px; background: #f7f9fa; color: #87969c; font-size: 12px; }

/* span-2 within erp-form-grid */
.ord-span-2 {
  grid-column: span 2;
}

/* Item rows — order-specific layout */
.ord-item-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row-view {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: start;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row:first-of-type,
.ord-item-row-view:first-of-type {
  border-top: none;
}

.ord-item-sku {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Readonly textarea-height variant */
.ord-readonly-textarea {
  min-height: 80px;
  height: auto;
  align-items: flex-start;
  padding: 10px 12px;
}

/* Audit log */
.ord-audit-card {
  margin-top: 0;
}

.ord-audit-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.ord-audit-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #e4eaed;
  font-size: 14px;
}

.ord-audit-item:last-child {
  border-bottom: none;
}

.ord-audit-action {
  color: #344b53;
}

.ord-audit-meta {
  font-size: 12px;
  color: #7a8c94;
}


@media (max-width: 767px) {
  .erp-form-header-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .erp-form-header-actions .erp-btn {
    flex: 1 1 auto;
  }
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-fulfillment-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .ord-item-row,
  .ord-item-row-view,
  .ord-packaging-row {
    grid-template-columns: 1fr;
  }
  .ord-packaging-empty { grid-column: 1; }
  .ord-identity-strip {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>

<style scoped>
.ord-marker-row{display:flex;gap:8px;flex-wrap:wrap}.ord-marker.active{background:var(--byl-status-bg);color:var(--byl-status-ink);font-weight:700}.ord-marker:disabled{opacity:.55;cursor:not-allowed}
.ord-packaging-link{display:inline-flex;width:max-content;color:#0f766e;text-decoration:none;font-size:12px;font-weight:700}.ord-packaging-link:hover{text-decoration:underline}.ord-packaging-link:focus-visible{outline:2px solid #0f766e;outline-offset:2px;border-radius:4px}
</style>
