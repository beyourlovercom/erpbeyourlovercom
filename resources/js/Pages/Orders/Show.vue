<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type Availability = { warehouse_id: number; warehouse_slug: string; warehouse_name: string; quantity_on_hand: number; quantity_reserved: number; quantity_available: number }
type Warehouse = { id: number; slug: string; name: string; code: string; country_code: string | null }

type OrderItem = {
  id: number
  product_id: number | null
  product_summary: string
  sku: string | null
  quantity: number
  unit_price: string
}

type Order = {
  id: number
  internal_order_number: string
  external_order_number: string
  source_type: string
  source_label: string
  order_kind: string | null
  order_kind_label: string | null
  order_date: string
  order_status: string
  order_status_label: string
  order_note: string | null
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
}

const props = defineProps<{
  order: Order
  inventoryAvailability?: Record<number, Availability[]>
  audit: { action: string; actor_id: number | null; created_at: string; reason?: string }[]
}>()

const isKol = computed(() => props.order.order_kind === 'kol_fulfillment')
const basePath = computed(() => isKol.value ? '/kol/orders' : '/customers/orders')

const breadcrumbs = computed(() => isKol.value
  ? [{ label: 'BYL ERP', href: '/dashboard' }, { label: '网红', href: '/kol' }, { label: '订单', href: '/kol/orders' }, props.order.external_order_number]
  : [{ label: 'BYL ERP', href: '/dashboard' }, { label: '客户', href: '/customers' }, { label: '订单', href: '/customers/orders' }, props.order.external_order_number]
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

const shipmentStatusLabel = computed(() => {
  const s = props.order.shipment?.shipment_status
  if (s === 'shipped') return '已发货'
  if (s === 'exception') return '异常'
  if (s === 'returned') return '已退回'
  return '待发货'
})
</script>

<template>
  <Head :title="`${order.external_order_number} · BYL ERP`" />
  <AuthenticatedLayout :title="`订单 ${order.external_order_number}`" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <!-- Identity strip -->
        <div class="ord-identity-strip">
          <div class="ord-identity-main">
            <span class="ord-identity-number">{{ order.external_order_number }}</span>
            <span class="ord-identity-badge">{{ order.order_kind_label }}</span>
            <span class="ord-identity-badge ord-badge-muted">{{ order.source_label }}</span>
            <span class="ord-identity-badge ord-badge-status">{{ order.order_status_label }}</span>
            <span class="ord-identity-owner">负责人：{{ order.owner?.name ?? '—' }}</span>
          </div>
          <div class="ord-identity-actions">
            <details class="ord-details-disclosure">
              <summary class="ord-details-summary">ERP技术编号</summary>
              <span class="ord-details-value">{{ order.internal_order_number }}</span>
            </details>
            <button
              v-if="!editMode"
              type="button"
              class="erp-btn erp-btn-secondary"
              @click="enterEditMode"
            >
              编辑订单
            </button>
            <a v-else :href="basePath" class="erp-btn erp-btn-secondary">返回列表</a>
            <a v-if="!editMode" :href="basePath" class="erp-btn erp-btn-secondary">返回列表</a>
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
          <div v-if="order.shipment?.tracking_number" class="ord-fulfillment-action">
            <button
              type="button"
              class="erp-btn erp-btn-secondary"
              @click="router.post(`/logistics/orders/${order.id}/reopen`)"
            >
              重开发货
            </button>
          </div>
        </div>

        <!-- Inventory visibility -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">库存可见</h2>
          <p class="ord-inventory-help">仅展示当前库存事实，由操作人员人工决定发货仓库。</p>
          <div v-for="item in order.items" :key="`inventory-${item.id}`" class="ord-inventory-row">
            <div class="ord-inventory-sku"><strong>{{ item.sku || '—' }}</strong><small>需求 {{ item.quantity }}</small></div>
            <div class="ord-inventory-warehouses">
              <span v-for="row in inventoryAvailability?.[item.product_id ?? 0] ?? []" :key="row.warehouse_id" class="ord-inventory-pill" :class="{ shortage: row.quantity_available < item.quantity }">
                {{ row.warehouse_name }} · 可用 {{ row.quantity_available }}
                <small>QOH {{ row.quantity_on_hand }} / Reserved {{ row.quantity_reserved }}</small>
              </span>
            </div>
          </div>
        </div>

        <!-- Card 2: 订单信息 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">订单信息</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field">
              <label class="erp-form-label">业务订单号</label>
              <input :value="order.external_order_number" class="erp-input" disabled>
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
            <div class="erp-form-field ord-span-2">
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
                <div class="erp-readonly-input">{{ item.unit_price }}</div>
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
                <label class="erp-form-label">单价</label>
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

        <!-- Edit Mode action bar -->
        <div v-if="editMode" class="erp-form-actions ord-action-bar">
          <button type="button" class="erp-btn erp-btn-secondary" @click="cancelEdit">取消编辑</button>
          <button type="button" class="erp-btn erp-btn-primary" :disabled="form.processing" @click="submit">
            保存订单
          </button>
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
.ord-inventory-row { display: grid; grid-template-columns: 150px 1fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid #e4eaed; }
.ord-inventory-sku { display: flex; flex-direction: column; gap: 3px; }
.ord-inventory-sku small { color: #7a8c94; }
.ord-inventory-warehouses { display: flex; flex-wrap: wrap; gap: 8px; }
.ord-inventory-pill { background: #eef7f2; border-radius: 7px; padding: 6px 9px; font-size: 12px; }
.ord-inventory-pill.shortage { background: #fff1f2; }
.ord-inventory-pill small { display: block; color: #6b7f87; }

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

/* Action bar — right-aligned for orders */
.ord-action-bar {
  justify-content: flex-end;
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
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-fulfillment-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .ord-item-row,
  .ord-item-row-view {
    grid-template-columns: 1fr;
  }
  .ord-identity-strip {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
