<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

const props = defineProps<{
  defaultOrderKind: string
  orderKindLabel: string
  generatedExternalOrderNumber: string
}>()

type OrderItem = {
  product_summary: string
  sku: string
  product_id: number | null
  quantity: number
  unit_price: string
}

const form = useForm<{
  external_order_number: string
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
  items: OrderItem[]
}>({
  external_order_number: props.generatedExternalOrderNumber,
  order_date: new Date().toISOString().slice(0, 10),
  order_note: '',
  recipient_name: '',
  phone: '',
  country: '',
  province_state: '',
  city: '',
  address_line_1: '',
  address_line_2: '',
  postal_code: '',
  items: [{ product_summary: '', sku: '', product_id: null, quantity: 1, unit_price: '0.00' }],
})

const numberEditable = ref(false)

const isKol = computed(() => props.defaultOrderKind === 'kol_fulfillment')

const pageTitle = computed(() => isKol.value ? '新建网红履约订单' : '新建客户订单')

const breadcrumbs = computed(() => isKol.value
  ? [{ label: 'BYL ERP', href: '/dashboard' }, { label: '网红', href: '/kol' }, { label: '订单', href: '/kol/orders' }, '新建']
  : [{ label: 'BYL ERP', href: '/dashboard' }, { label: '客户', href: '/customers' }, { label: '订单', href: '/customers/orders' }, '新建']
)

const listHref = computed(() => isKol.value ? '/kol/orders' : '/customers/orders')

const addItem = () =>
  form.items.push({ product_summary: '', sku: '', product_id: null, quantity: 1, unit_price: '0.00' })

const removeItem = (index: number) => {
  if (form.items.length > 1) form.items.splice(index, 1)
}

const submit = () =>
  form.post(isKol.value ? '/kol/orders' : '/customers/orders')
</script>

<template>
  <Head :title="`${pageTitle} · BYL ERP`" />
  <AuthenticatedLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
    <div class="ord-page">
      <div class="ord-page-header">
        <div>
          <p class="ord-kicker">{{ isKol ? '网红履约' : '客户订单' }}</p>
          <h2 class="ord-page-title">{{ pageTitle }}</h2>
          <p class="ord-page-hint">填写订单信息后点击「创建订单」完成录入</p>
        </div>
        <a :href="listHref" class="mvp-button mvp-button-secondary">返回列表</a>
      </div>

      <form @submit.prevent="submit">
        <!-- Card 1: 订单信息 -->
        <div class="ord-card">
          <h3 class="ord-card-title">订单信息</h3>
          <div class="ord-form-grid">
            <div class="mvp-form-field">
              <label class="mvp-label">订单类型</label>
              <input :value="props.orderKindLabel" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">来源</label>
              <input value="手动创建" class="mvp-input ord-readonly" disabled>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">订单日期 <span class="mvp-required">*</span></label>
              <input v-model="form.order_date" type="date" class="mvp-input" required>
              <p v-if="form.errors.order_date" class="mvp-error">{{ form.errors.order_date }}</p>
            </div>
            <div class="mvp-form-field ord-span-2">
              <label for="external_order_number" class="mvp-label">
                业务订单号 <span class="mvp-required">*</span>
                <span class="ord-hint-inline">（系统已预生成，保存前可修改）</span>
              </label>
              <div class="ord-inline-row">
                <input
                  id="external_order_number"
                  v-model="form.external_order_number"
                  class="mvp-input"
                  :class="{ 'ord-readonly': !numberEditable }"
                  :readonly="!numberEditable"
                  required
                >
                <button
                  v-if="!numberEditable"
                  type="button"
                  class="mvp-button mvp-button-secondary ord-icon-btn"
                  aria-label="编辑订单号"
                  @click="numberEditable = true"
                >
                  编辑
                </button>
              </div>
              <p v-if="form.errors.external_order_number" class="mvp-error">{{ form.errors.external_order_number }}</p>
            </div>
          </div>
        </div>

        <!-- Card 2: 收件与地址 -->
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

        <!-- Card 3: 订单商品 -->
        <div class="ord-card">
          <div class="ord-card-header-row">
            <h3 class="ord-card-title">订单商品</h3>
            <button type="button" class="mvp-button mvp-button-secondary" @click="addItem">添加商品</button>
          </div>
          <div v-for="(item, index) in form.items" :key="index" class="ord-item-row">
            <div class="ord-item-sku">
              <label class="mvp-label">SKU / 产品 <span class="mvp-required">*</span></label>
              <SkuCombobox
                v-model="item.sku"
                :product-id="item.product_id"
                @update:product-id="(id) => { item.product_id = id }"
              />
              <p v-if="(form.errors as Record<string, string>)[`items.${index}.product_id`]" class="mvp-error">
                {{ (form.errors as Record<string, string>)[`items.${index}.product_id`] }}
              </p>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">产品摘要</label>
              <input v-model="item.product_summary" class="mvp-input" placeholder="可选">
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">数量 <span class="mvp-required">*</span></label>
              <input v-model="item.quantity" type="number" min="1" class="mvp-input" required>
            </div>
            <div class="mvp-form-field">
              <label class="mvp-label">单价 <span class="mvp-required">*</span></label>
              <input v-model="item.unit_price" type="number" min="0" step="0.01" class="mvp-input" required>
            </div>
            <div class="ord-item-remove">
              <button
                v-if="form.items.length > 1"
                type="button"
                class="mvp-button mvp-button-secondary"
                @click="removeItem(index)"
              >
                删除
              </button>
            </div>
          </div>
        </div>

        <!-- Card 4: 内部备注 -->
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
          <a :href="listHref" class="mvp-button mvp-button-secondary">取消</a>
          <button type="submit" class="mvp-button mvp-button-primary" :disabled="form.processing">
            创建订单
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.ord-page {
  max-width: 1080px;
  margin: 0 auto;
  padding: 24px 16px;
}

.ord-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 24px;
  gap: 16px;
}

.ord-kicker {
  font-size: 13px;
  color: #7a8c94;
  margin: 0 0 4px;
}

.ord-page-title {
  font-size: 28px;
  font-weight: 600;
  color: #142126;
  margin: 0 0 4px;
}

.ord-page-hint {
  font-size: 13px;
  color: #7a8c94;
  margin: 0;
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

.ord-card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.ord-card-header-row .ord-card-title {
  margin: 0;
}

.ord-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.ord-span-2 {
  grid-column: span 2;
}

.ord-hint-inline {
  font-size: 12px;
  color: #7a8c94;
  font-weight: 400;
}

.ord-inline-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.ord-inline-row .mvp-input {
  flex: 1;
}

.ord-icon-btn {
  white-space: nowrap;
  flex-shrink: 0;
}

.ord-readonly {
  background: #f4f7f8;
  color: #7a8c94;
  cursor: default;
}

.ord-item-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr auto;
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

.ord-item-remove {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0;
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

@media (max-width: 767px) {
  .ord-form-grid {
    grid-template-columns: 1fr;
  }
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-item-row {
    grid-template-columns: 1fr;
  }
  .ord-page-header {
    flex-direction: column;
  }
}
</style>
