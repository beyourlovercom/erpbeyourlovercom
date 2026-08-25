<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Product = {
  id: number
  woocommerce_product_id: number | null
  source_type: string
  product_type: string
  sku: string | null
  gtin: string | null
  name: string
  unit: string | null
  is_active: boolean
  weight_kg: string | null
  sale_price: string | null
  regular_price: string | null
  shipping_weight_kg: string | null
  length_cm: string | null
  width_cm: string | null
  height_cm: string | null
  country_of_origin: string | null
  hs_code: string | null
  standard_production_cost: string | null
  standard_packaging_cost: string | null
  standard_purchase_cost: string | null
  cost_currency: string | null
  cost_note: string | null
  notes: string | null
}

const props = defineProps<{ product: Product }>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, { label: '产品列表', href: '/products/list' }, '编辑产品']

const form = ref({
  sku: props.product.sku ?? '',
  name: props.product.name,
  product_type: props.product.product_type,
  gtin: props.product.gtin ?? '',
  unit: props.product.unit ?? '',
  is_active: props.product.is_active,
  weight_kg: props.product.weight_kg ?? '',
  sale_price: props.product.sale_price ?? '',
  regular_price: props.product.regular_price ?? '',
  shipping_weight_kg: props.product.shipping_weight_kg ?? '',
  length_cm: props.product.length_cm ?? '',
  width_cm: props.product.width_cm ?? '',
  height_cm: props.product.height_cm ?? '',
  country_of_origin: props.product.country_of_origin ?? '',
  hs_code: props.product.hs_code ?? '',
  standard_production_cost: props.product.standard_production_cost ?? '',
  standard_packaging_cost: props.product.standard_packaging_cost ?? '',
  standard_purchase_cost: props.product.standard_purchase_cost ?? '',
  cost_currency: props.product.cost_currency ?? '',
  cost_note: props.product.cost_note ?? '',
  notes: props.product.notes ?? '',
})
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

const submit = () => {
  submitting.value = true
  errors.value = {}
  router.patch(`/products/${props.product.id}`, form.value, {
    onError: (e) => { errors.value = e },
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <Head title="编辑产品 · BYL ERP" />
  <AuthenticatedLayout title="编辑产品" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>编辑产品</h2></div>
      </div>

      <div class="mvp-form-meta">
        <span class="mvp-meta-item">来源：{{ product.source_type === 'manual' ? '手动' : 'WooCommerce' }}</span>
        <span v-if="product.woocommerce_product_id" class="mvp-meta-item">Woo ID：{{ product.woocommerce_product_id }}</span>
      </div>

      <form class="mvp-form" @submit.prevent="submit">
        <h3 class="mvp-form-section-title">基本信息</h3>

        <div class="mvp-form-field">
          <label class="mvp-label">SKU</label>
          <input v-model="form.sku" type="text" class="mvp-input" placeholder="SKU（可留空）">
          <p v-if="errors.sku" class="mvp-error">{{ errors.sku }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">产品名称 <span class="mvp-required">*</span></label>
          <input v-model="form.name" type="text" class="mvp-input" required>
          <p v-if="errors.name" class="mvp-error">{{ errors.name }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">产品类型</label>
          <input v-model="form.product_type" type="text" class="mvp-input">
          <p v-if="errors.product_type" class="mvp-error">{{ errors.product_type }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">GTIN</label>
          <input v-model="form.gtin" type="text" class="mvp-input">
          <p v-if="errors.gtin" class="mvp-error">{{ errors.gtin }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">单位</label>
          <input v-model="form.unit" type="text" class="mvp-input" placeholder="pcs / 件 / 套">
          <p v-if="errors.unit" class="mvp-error">{{ errors.unit }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">状态</label>
          <label class="mvp-checkbox-label">
            <input v-model="form.is_active" type="checkbox" class="mvp-checkbox"> 启用
          </label>
        </div>

        <h3 class="mvp-form-section-title">价格</h3>

        <div class="mvp-form-field">
          <label class="mvp-label">售价</label>
          <input v-model="form.sale_price" type="number" step="0.01" class="mvp-input">
          <p v-if="errors.sale_price" class="mvp-error">{{ errors.sale_price }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">原价</label>
          <input v-model="form.regular_price" type="number" step="0.01" class="mvp-input">
          <p v-if="errors.regular_price" class="mvp-error">{{ errors.regular_price }}</p>
        </div>

        <h3 class="mvp-form-section-title">物流尺寸</h3>

        <div class="mvp-form-field">
          <label class="mvp-label">重量 (kg)</label>
          <input v-model="form.weight_kg" type="number" step="0.0001" class="mvp-input">
          <p v-if="errors.weight_kg" class="mvp-error">{{ errors.weight_kg }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">运输重量 (kg)</label>
          <input v-model="form.shipping_weight_kg" type="number" step="0.0001" class="mvp-input">
          <p v-if="errors.shipping_weight_kg" class="mvp-error">{{ errors.shipping_weight_kg }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">长度 (cm)</label>
          <input v-model="form.length_cm" type="number" step="0.01" class="mvp-input">
          <p v-if="errors.length_cm" class="mvp-error">{{ errors.length_cm }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">宽度 (cm)</label>
          <input v-model="form.width_cm" type="number" step="0.01" class="mvp-input">
          <p v-if="errors.width_cm" class="mvp-error">{{ errors.width_cm }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">高度 (cm)</label>
          <input v-model="form.height_cm" type="number" step="0.01" class="mvp-input">
          <p v-if="errors.height_cm" class="mvp-error">{{ errors.height_cm }}</p>
        </div>

        <h3 class="mvp-form-section-title">海关信息</h3>

        <div class="mvp-form-field">
          <label class="mvp-label">原产地 (2位代码)</label>
          <input v-model="form.country_of_origin" type="text" maxlength="2" class="mvp-input" placeholder="CN">
          <p v-if="errors.country_of_origin" class="mvp-error">{{ errors.country_of_origin }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">HS 编码</label>
          <input v-model="form.hs_code" type="text" class="mvp-input">
          <p v-if="errors.hs_code" class="mvp-error">{{ errors.hs_code }}</p>
        </div>

        <h3 class="mvp-form-section-title">标准成本（参考值）</h3>

        <div class="mvp-form-field">
          <label class="mvp-label">成本币种 (3位代码)</label>
          <input v-model="form.cost_currency" type="text" maxlength="3" class="mvp-input" placeholder="CNY">
          <p v-if="errors.cost_currency" class="mvp-error">{{ errors.cost_currency }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">标准生产成本</label>
          <input v-model="form.standard_production_cost" type="number" step="0.0001" class="mvp-input">
          <p v-if="errors.standard_production_cost" class="mvp-error">{{ errors.standard_production_cost }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">标准包材成本</label>
          <input v-model="form.standard_packaging_cost" type="number" step="0.0001" class="mvp-input">
          <p v-if="errors.standard_packaging_cost" class="mvp-error">{{ errors.standard_packaging_cost }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">标准采购成本</label>
          <input v-model="form.standard_purchase_cost" type="number" step="0.0001" class="mvp-input">
          <p v-if="errors.standard_purchase_cost" class="mvp-error">{{ errors.standard_purchase_cost }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">成本备注</label>
          <textarea v-model="form.cost_note" class="mvp-input" rows="2" />
          <p v-if="errors.cost_note" class="mvp-error">{{ errors.cost_note }}</p>
        </div>

        <div class="mvp-form-field">
          <label class="mvp-label">产品备注</label>
          <textarea v-model="form.notes" class="mvp-input" rows="2" />
          <p v-if="errors.notes" class="mvp-error">{{ errors.notes }}</p>
        </div>

        <div class="mvp-form-actions">
          <button type="submit" class="mvp-button" :disabled="submitting">保存更改</button>
          <a href="/products/list" class="mvp-button mvp-button-secondary">返回列表</a>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>
