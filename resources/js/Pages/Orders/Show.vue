<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

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
    carrier: string | null
    tracking_number: string | null
    shipped_at: string | null
  } | null
  manual_sync: { status: string; synced_at: string | null } | null
}

const props = defineProps<{
  order: Order
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

const form = useForm<{
  order_date: string
  order_note: string
  recipient_name: string
  phone: string
  country: string
  province_state: string
  city: string
  address_line_1: string
  address_line_2: string
  postal_code: string
  items: EditItem[]
}>({
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
  })),
})

const submit = () => form.patch(`${basePath.value}/${props.order.id}`)

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
    <div class="ord-page">
      <!-- Identity strip -->
      <div class="ord-identity-strip">
        <div class="ord-identity-main">
          <span class="ord-identity-number">{{ order.external_order_number }}</span>
          <span class="ord-identity-badge">{{ order.order_kind_label }}</span>
          <span class="ord-identity-badge ord-badge-muted">{{ order.source_label }}</span>
          <span class="ord-identity-badge ord-badge-status">{{ order.order_status_label }}</span>
          <span class="ord-identity-owner">负责人：{{ order.owner_label ?? '—' }}</span>
        </div>
        <div class="ord-identity-actions">
          <details class="ord-details-disclosure">
            <summary class="ord-details-summary">ERP技术编号</summary>
            <span class="ord-details-value">{{ order.internal_order_number }}</span>
          </details>
          <a :href="basePath" class="mvp-button mvp-button-secondary">返回列表</a>
        </div>
      </div>

      <form @submit.prevent="submit">
        <!-- Card 1: 履约状态 -->
        <div class="ord-card">
          <h3 class="ord-card-title">履约状态</h3>
          <div class="ord-fulfillment-grid">
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">发货状态</small>
              <strong class="ord-fulfillment-value">{{ shipmentStatusLabel }}</strong>
            </div>
            <div class="ord-fulfillment-item">
              <small class="ord-fulfillment-label">承运商</small>
              <strong class="ord-fulfillment-value">{{ order.shipment?.carrier ?? '—' }}</strong>
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
              class="mvp-button mvp-button-secondary"
              @click="router.post(`/logistics/orders/${order.id}/reopen`)"
            >
              重开发货
            </button>
          </div>
        </div>

        <!-- Card 2: 订单信息 -->
        <div class="ord-card">
          <h3 class="ord-card-title">订单信息</h3>
          <div class="ord-form-grid">
            <div class="mvp-form-field">
              <label class="mvp-label">业务订单号</label>
              <input :value="order.external_order_number" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">订单类型</label>
              <input :value="order.order_kind_label" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">来源</label>
              <input :value="order.source_label" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">负责人</label>
              <input :value="order.owner?.name ?? '—'" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field ord-span-2">
              <label class="mvp-label">订单日期</label>
              <input v-model="form.order_date" type="date" class="mvp-input">
              <p v-if="form.errors.order_date" class="mvp-error">{{ form.errors.order_date }}</p>
            </div>
          </div>
        </div>

        <!-- Card 3: 收件与地址 -->
        <div class="ord-card">
          <h3 class="ord-card-title">收件与地址</h3>
          <div class="ord-form-grid">
            <div class="mvp-form-field">
              <label class="mvp-label">收件人姓名</label>
              <input v-model="form.recipient_name" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.recipient_name" class="mvp-error">{{ form.errors.recipient_name }}</p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">联系电话</label>
              <input v-model="form.phone" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.phone" class="mvp-error">{{ form.errors.phone }}</p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">国家</label>
              <input v-model="form.country" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.country" class="mvp-error">{{ form.errors.country }}</p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">省/州</label>
              <input v-model="form.province_state" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.province_state" class="mvp-error">{{ form.errors.province_state }}</p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">城市</label>
              <input v-model="form.city" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.city" class="mvp-error">{{ form.errors.city }}</p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">邮政编码</label>
              <input v-model="form.postal_code" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.postal_code" class="mvp-error">{{ form.errors.postal_code }}</p>
            </div>
            <div class="mvp-form-field ord-span-2">
              <label class="mvp-label">地址行 1</label>
              <input v-model="form.address_line_1" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.address_line_1" class="mvp-error">{{ form.errors.address_line_1 }}</p>
            </div>
            <div class="mvp-form-field ord-span-2">
              <label class="mvp-label">地址行 2</label>
              <input v-model="form.address_line_2" class="mvp-input" placeholder="可选">
              <p v-if="form.errors.address_line_2" class="mvp-error">{{ form.errors.address_line_2 }}</p>
            </div>
          </div>
        </div>

        <!-- Card 4: 订单商品 -->
        <div class="ord-card">
          <h3 class="ord-card-title">订单商品</h3>
          <div v-for="(item, index) in form.items" :key="item.id" class="ord-item-row">
            <div class="ord-item-sku">
              <label class="mvp-label">SKU / 产品</label>
              <SkuCombobox
                v-model="item.sku"
                :product-id="item.product_id"
                @update:product-id="(id) => { item.product_id = id }"
              />
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">产品摘要</label>
              <input v-model="item.product_summary" class="mvp-input" placeholder="可选">
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">数量</label>
              <input v-model="item.quantity" type="number" min="1" class="mvp-input" required>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">单价</label>
              <input v-model="item.unit_price" type="number" min="0" step="0.01" class="mvp-input" required>
            </div>
          </div>
        </div>

        <!-- Card 5: 内部备注 -->
        <div class="ord-card">
          <h3 class="ord-card-title">内部备注</h3>
          <div class="mvp-form-field">
            <label class="mvp-label">备注内容</label>
            <textarea v-model="form.order_note" class="mvp-input ord-textarea" placeholder="可选" />
            <p v-if="form.errors.order_note" class="mvp-error">{{ form.errors.order_note }}</p>
          </div>
        </div>

        <!-- Action bar -->
        <div class="ord-action-bar">
          <a :href="basePath" class="mvp-button mvp-button-secondary">返回列表</a>
          <button type="submit" class="mvp-button mvp-button-primary" :disabled="form.processing">
            保存订单
          </button>
        </div>
      </form>

      <!-- Audit log -->
      <div class="ord-card ord-audit-card">
        <h3 class="ord-card-title">操作审计</h3>
        <ul class="ord-audit-list">
          <li v-for="event in audit" :key="event.created_at + event.action" class="ord-audit-item">
            <span class="ord-audit-action">{{ event.action }}</span>
            <small class="ord-audit-meta">{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small>
          </li>
        </ul>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.ord-page {
  max-width: 1080px;
  margin: 0 auto;
  padding: 24px 16px;
}

.ord-identity-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1px solid #e4eaed;
  border-radius: 16px;
  padding: 16px 24px;
  margin-bottom: 24px;
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

.ord-card {
  background: #ffffff;
  border: 1px solid #e4eaed;
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 24px;
}

.ord-card-title {
  font-size: 18px;
  font-weight: 600;
  color: #142126;
  margin: 0 0 20px;
}

.ord-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.ord-span-2 {
  grid-column: span 2;
}

.ord-readonly {
  background: #f4f7f8;
  color: #7a8c94;
  cursor: default;
}

.ord-fulfillment-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
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

.ord-item-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row:first-of-type {
  border-top: none;
}

.ord-item-sku {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ord-textarea {
  min-height: 96px;
  resize: vertical;
}

.ord-action-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 0 8px;
}

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
  .ord-form-grid {
    grid-template-columns: 1fr;
  }
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-fulfillment-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .ord-item-row {
    grid-template-columns: 1fr;
  }
  .ord-identity-strip {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
