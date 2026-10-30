<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ProductSectionRail from '../../Components/Products/ProductSectionRail.vue'
import ProductParametersEditor from '../../Components/Products/ProductParametersEditor.vue'
import PackagingEditor from '../../Components/Products/PackagingEditor.vue'

type ExternalReference = {
  provider: string
  connection_key: string
  reference: string
  reference_type: string
}

type Product = {
  id: number
  woocommerce_product_id: number | null
  source_type: string
  product_type: string
  sku: string | null
  label_sku?: string | null
  label_description?: string | null
  gtin: string | null
  barcode_69?: string | null
  brand?: string | null
  brand_local_override?: boolean
  woocommerce_brand?: string | null
  has_battery?: boolean
  name: string
  unit: string | null
  is_active: boolean
  weight_kg: string | null
  weight_evidence_id: number | null
  weight_evidence_source_type: string | null
  sale_price: string | null
  regular_price: string | null
  shipping_weight_kg: string | null
  shipping_weight_evidence_id: number | null
  shipping_weight_evidence_source_type: string | null
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
  external_references: ExternalReference[]
  media?: Array<{ id: number; url: string; position: number; is_primary: boolean }>
  woo_media?: Array<{ id: number; url: string; is_primary: boolean }>
}

type ImageRef = { id: number | null; name: string | null; url: string }
type PackagingProfile = {
  id: number; preset_key: string | null; method: string; scenario: string | null; version: string | null;
  units_per_carton: number | null; inherits_global_template: boolean;
  default_image: ImageRef | null; rendered_image: ImageRef | null; examples: ImageRef[]
}
const props = withDefaults(defineProps<{
  product: Product
  approvedMeasurements?: Record<string, string | number | null>
  pendingParameterReviewCount?: number
  packagingProfile?: PackagingProfile | null
  packagingPresets?: { key: string; label: string }[]
}>(), { approvedMeasurements: () => ({}), pendingParameterReviewCount: 0, packagingProfile: null, packagingPresets: () => [] })

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  { label: '产品列表', href: '/products/list' },
  '编辑产品',
]

const form = ref({
  sku: props.product.sku ?? '',
  label_sku: props.product.label_sku ?? '',
  label_description: props.product.label_description ?? '',
  name: props.product.name,
  product_type: props.product.product_type,
  gtin: props.product.gtin ?? '',
  barcode_69: props.product.barcode_69 ?? '',
  brand: props.product.brand ?? '',
  brand_local_override: props.product.brand_local_override ?? false,
  has_battery: props.product.has_battery ?? true,
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
const confirmingDelete = ref(false)
const images = ref<File[]>([])
const savedMedia = ref([...(props.product.media ?? [])])
const pendingPreviewUrls = ref<string[]>([])
const imagePreviews = computed(() => [...savedMedia.value.map(item => item.url), ...pendingPreviewUrls.value])
const uploadingImages = ref(false)
const clearPendingPreviews = () => {
  pendingPreviewUrls.value.forEach(url => URL.revokeObjectURL(url))
  pendingPreviewUrls.value = []
}
onBeforeUnmount(clearPendingPreviews)
watch(() => props.product.media, next => { savedMedia.value = [...(next ?? [])] }, { deep: true })
const selectImages = (event: Event) => {
  clearPendingPreviews()
  images.value = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, 12)
  pendingPreviewUrls.value = images.value.map(file => URL.createObjectURL(file))
}
const saveMediaOrder = (primaryId: number) => router.patch('/products/' + props.product.id + '/media', {
  media: savedMedia.value.map((item, position) => ({ id: item.id, position })),
  primary_id: primaryId,
}, { preserveScroll: true })
const setPrimary = (id: number) => saveMediaOrder(id)
const moveMedia = (index: number, delta: number) => {
  const other = index + delta
  if (other < 0 || other >= savedMedia.value.length) return
  const reordered = [...savedMedia.value]
  const value = reordered[index]
  reordered[index] = reordered[other]
  reordered[other] = value
  savedMedia.value = reordered
  saveMediaOrder(reordered.find(item => item.is_primary)?.id ?? reordered[0]?.id)
}
const deleteMedia = (id: number) => {
  if (!confirm('删除这张 ERP 本地图片？官网图片不会受影响。')) return
  router.delete('/products/' + props.product.id + '/media/' + id, { preserveScroll: true })
}
const uploadImages = () => {
  if (!images.value.length || uploadingImages.value) return
  uploadingImages.value = true
  const payload = new FormData()
  images.value.forEach(file => payload.append('images[]', file))
  router.post('/products/' + props.product.id + '/media', payload, {
    preserveScroll: true,
    onSuccess: () => { images.value = []; clearPendingPreviews() },
    onFinish: () => { uploadingImages.value = false },
  })
}

const externalReferenceSummary = computed(() => {
  if (props.product.external_references.length === 0) return '—'

  const grouped = new Map<string, string[]>()
  for (const reference of props.product.external_references) {
    const type = reference.reference_type === 'product' ? '产品' : '变体'
    const refs = grouped.get(reference.connection_key) ?? []
    refs.push(`${type} ${reference.reference}`)
    grouped.set(reference.connection_key, refs)
  }

  return Array.from(grouped.entries())
    .map(([connection, refs]) => `${connection} · ${refs.join(' / ')}`)
    .join(' ｜ ')
})

const externalReferenceDetail = computed(() => props.product.external_references
  .map((reference) => `${reference.provider === 'woocommerce' ? 'WooCommerce' : reference.provider} · ${reference.connection_key} · ${reference.reference_type === 'product' ? '产品' : '变体'} ${reference.reference}`)
  .join(' ｜ '))

const submit = () => {
  submitting.value = true
  errors.value = {}
  router.patch(`/products/${props.product.id}`, form.value, {
    onError: (e) => { errors.value = e },
    onFinish: () => { submitting.value = false },
  })
}

const doDelete = () => {
  router.delete(`/products/${props.product.id}`, {
    onFinish: () => { confirmingDelete.value = false },
  })
}
</script>

<template>
  <Head title="编辑产品 · BYL ERP" />
  <AuthenticatedLayout title="编辑产品" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page product-editor-page">
      <div class="product-editor-layout">
        <ProductSectionRail :sections="[{ id: 'section-basic', label: '基本信息' }, { id: 'section-images', label: '图片' }, { id: 'section-parameters', label: '产品参数' }, { id: 'section-packaging', label: '包装与配件' }, { id: 'section-sales', label: '销售' }, { id: 'section-logistics', label: '物流' }, { id: 'section-customs', label: '海关' }, { id: 'section-cost', label: '成本' }, { id: 'section-notes', label: '备注' }] " />
        <div class="erp-form-content">

        <!-- Page header -->
        <div class="erp-form-header-row">
          <div>
            <h1 class="erp-form-title">编辑产品</h1>
            <p class="erp-form-subtitle">管理产品资料、物流属性与会计参考成本</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <a href="/products/list" class="erp-btn erp-btn-secondary">取消</a>
            <a href="/products/list" class="erp-btn erp-btn-secondary">返回列表</a>
            <a :href="`/products/${product.id}/packaging`" class="erp-btn erp-btn-secondary">包装方案</a>
            <button type="button" class="erp-btn-danger" @click="confirmingDelete = true">删除产品</button>
            <button type="submit" form="product-edit-form" class="erp-btn erp-btn-primary" :disabled="submitting">{{ submitting ? '保存中…' : '保存产品' }}</button>
          </div>
        </div>

        <!-- Identity strip (edit-only) -->
        <div class="erp-identity-strip" aria-label="产品来源信息">
          <div class="erp-identity-item">
            <span class="erp-identity-label">SKU</span>
            <span class="erp-identity-value">{{ product.sku ?? '—' }}</span>
          </div>
          <div class="erp-identity-item">
            <span class="erp-identity-label">来源</span>
            <span class="erp-identity-value">{{ product.source_type === 'manual' ? '手动' : 'WooCommerce' }}</span>
          </div>
          <div class="erp-identity-item erp-identity-item-external" data-testid="external-references-compact">
            <span class="erp-identity-label">外部引用</span>
            <span
              class="erp-identity-value erp-identity-value-secondary"
              data-testid="external-reference-summary"
              :title="externalReferenceDetail"
            >{{ externalReferenceSummary }}</span>
          </div>
          <div class="erp-identity-item">
            <span class="erp-identity-label">状态</span>
            <span
              class="erp-status-chip"
              :class="product.is_active ? 'active' : 'inactive'"
            >{{ product.is_active ? '● 启用' : '○ 停用' }}</span>
          </div>
        </div>

        <form id="product-edit-form" @submit.prevent="submit">

          <!-- Card 1: 基本信息 -->
          <div class="erp-form-card" id="section-basic">
            <h2 class="erp-form-card-title">基本信息</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-sku">SKU</label>
                <input
                  id="edit-sku"
                  v-model="form.sku"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.sku }"
                  placeholder="SKU（可留空）"
                >
                <p v-if="errors.sku" class="erp-field-error">{{ errors.sku }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-label-sku">蓝标 SKU</label>
                <input
                  id="edit-label-sku"
                  v-model="form.label_sku"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.label_sku }"
                  placeholder="留空则使用 SKU"
                >
                <p class="erp-field-help">蓝标/条码使用的编号。与产品 SKU 相同时留空即可。</p>
                <p v-if="errors.label_sku" class="erp-field-error">{{ errors.label_sku }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-label-description">蓝标描述</label>
                <input
                  id="edit-label-description"
                  v-model="form.label_description"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.label_description }"
                  placeholder="留空则使用产品名称"
                >
                <p v-if="errors.label_description" class="erp-field-error">{{ errors.label_description }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-name">
                  产品名称 <span class="erp-form-required">*</span>
                </label>
                <input
                  id="edit-name"
                  v-model="form.name"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.name }"
                  required
                >
                <p v-if="errors.name" class="erp-field-error">{{ errors.name }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-product-type">产品类型</label>
                <select
                  id="edit-product-type"
                  v-model="form.product_type"
                  class="erp-select"
                  :class="{ 'has-error': errors.product_type }"
                >
                  <option value="simple">simple</option>
                  <option value="variable">variable</option>
                  <option value="bundle">bundle</option>
                </select>
                <p v-if="errors.product_type" class="erp-field-error">{{ errors.product_type }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-gtin">GTIN</label>
                <input
                  id="edit-gtin"
                  v-model="form.gtin"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.gtin }"
                >
                <p v-if="errors.gtin" class="erp-field-error">{{ errors.gtin }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-barcode-69">69条码/制造商产品识别码</label>
                <input id="edit-barcode-69" v-model="form.barcode_69" class="erp-input" inputmode="numeric" maxlength="14">
                <p v-if="errors.barcode_69" class="erp-field-error">{{ errors.barcode_69 }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-brand">品牌</label>
                <input id="edit-brand" v-model="form.brand" class="erp-input">
                <button type="button" class="erp-form-check" :class="{ selected: form.brand_local_override }" @click="form.brand_local_override = !form.brand_local_override">{{ form.brand_local_override ? '✓ 使用本地品牌覆盖 WooCommerce' : '启用本地品牌覆盖 WooCommerce' }}；关闭后下次同步可更新</button>
                <p v-if="product.woocommerce_brand" class="erp-field-help">WordPress 原始品牌：{{ product.woocommerce_brand }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label">电池属性</label>
                <div class="erp-segmented" role="group" aria-label="电池属性">
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.has_battery }" @click="form.has_battery = true">含电池</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: !form.has_battery }" @click="form.has_battery = false">不含电池</button>
                </div>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-unit">单位</label>
                <input
                  id="edit-unit"
                  v-model="form.unit"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.unit }"
                  placeholder="pcs / 件 / 套"
                >
                <p v-if="errors.unit" class="erp-field-error">{{ errors.unit }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label">状态</label>
                <div class="erp-segmented" role="group" aria-label="产品状态">
                  <button
                    type="button"
                    class="erp-segmented-option"
                    :class="{ selected: form.is_active }"
                    @click="form.is_active = true"
                  >● 启用</button>
                  <button
                    type="button"
                    class="erp-segmented-option"
                    :class="{ selected: !form.is_active }"
                    @click="form.is_active = false"
                  >○ 停用</button>
                </div>
              </div>

            </div>
          </div>

          <div class="erp-form-card" id="section-images">
            <h2 class="erp-form-card-title">产品图片</h2>
            <p class="erp-form-card-helper">ERP 本地上传可编辑；WooCommerce 原图仅作只读来源。支持 JPG、PNG、WebP，每张不超过 10MB。</p>
            <input id="edit-images" type="file" accept="image/jpeg,image/png,image/webp" multiple @change="selectImages">
            <button type="button" class="erp-btn erp-btn-secondary" :disabled="!images.length || uploadingImages" @click="uploadImages">上传选中图片</button>
            <div v-if="imagePreviews.length" class="product-image-preview-grid">
              <div v-for="(item, index) in savedMedia" :key="item.id" class="product-image-preview-item">
                <img :src="item.url" :alt="`产品图片 ${index + 1}`">
                <button type="button" @click="setPrimary(item.id)">{{ item.is_primary ? '主图' : '设为主图' }}</button>
                <div class="product-image-controls">
                  <button type="button" :disabled="index === 0" @click="moveMedia(index,-1)">← 左移</button>
                  <button type="button" :disabled="index === savedMedia.length - 1" @click="moveMedia(index,1)">右移 →</button>
                  <button type="button" @click="deleteMedia(item.id)">删除</button>
                </div>
              </div>
              <img v-for="(url, index) in imagePreviews.slice(savedMedia.length)" :key="`${url}-${index}`" :src="url" :alt="`待上传 ${index + 1}`">
            </div>
            <div v-if="product.woo_media?.length" class="product-woo-media">
              <h3>WordPress 同步图片（只读）</h3>
              <p>ERP 自行上传的主图优先显示；官网原图由 WooCommerce 同步维护，不在此处删除。</p>
              <div class="product-image-preview-grid">
                <div v-for="item in product.woo_media" :key="item.id" class="product-image-preview-item">
                  <img :src="item.url" alt="官网产品图片">
                  <small>{{ item.is_primary ? '官网主图' : '官网图片' }}</small>
                </div>
              </div>
            </div>
          </div>

          <section class="erp-form-card" id="section-parameters">
            <h2 class="erp-form-card-title">工厂产品参数</h2>
            <p class="erp-form-card-helper">已确认值只读展示；修改进入审核队列，不会直接覆盖旧值。</p>
            <ProductParametersEditor :product-id="product.id" :approved="approvedMeasurements" :pending-count="pendingParameterReviewCount" />
          </section>

          <section class="erp-form-card" id="section-packaging">
            <h2 class="erp-form-card-title">包装与配件</h2>
            <p class="erp-form-card-helper">在本页面直接编辑原有包装方案及示范图。包装方案与产品销售图片是不同的资料。</p>
            <PackagingEditor :product="{ id: product.id, sku: product.sku, name: product.name, barcode_69: product.barcode_69 }" :packaging-profile="packagingProfile" :presets="packagingPresets" embedded />
          </section>

          <!-- Card 2: 销售信息 -->
          <div class="erp-form-card" id="section-sales">
            <h2 class="erp-form-card-title">销售信息</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-sale-price">售价</label>
                <input
                  id="edit-sale-price"
                  v-model="form.sale_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.sale_price }"
                >
                <p v-if="errors.sale_price" class="erp-field-error">{{ errors.sale_price }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-regular-price">原价</label>
                <input
                  id="edit-regular-price"
                  v-model="form.regular_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.regular_price }"
                >
                <p v-if="errors.regular_price" class="erp-field-error">{{ errors.regular_price }}</p>
              </div>

            </div>
          </div>

          <!-- Card 3: 物流属性 -->
          <div class="erp-form-card" id="section-logistics">
            <h2 class="erp-form-card-title">物流属性</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-weight">净重 (kg)</label>
                <input
                  id="edit-weight"
                  v-model="form.weight_kg"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.weight_kg }"
                >
                <p v-if="errors.weight_kg" class="erp-field-error">{{ errors.weight_kg }}</p>
                <p class="text-xs text-gray-400 mt-0.5">
                  溯源：
                  <span v-if="product.weight_evidence_id" class="text-indigo-600">{{ product.weight_evidence_source_type ?? '已关联' }} #{{ product.weight_evidence_id }}</span>
                  <span v-else-if="product.weight_kg" class="text-gray-500">历史遗留 / 未关联证据</span>
                  <span v-else>—</span>
                </p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-shipping-weight">发货重量 (kg)</label>
                <input
                  id="edit-shipping-weight"
                  v-model="form.shipping_weight_kg"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.shipping_weight_kg }"
                >
                <p v-if="errors.shipping_weight_kg" class="erp-field-error">{{ errors.shipping_weight_kg }}</p>
                <p class="text-xs text-gray-400 mt-0.5">
                  溯源：
                  <span v-if="product.shipping_weight_evidence_id" class="text-indigo-600">{{ product.shipping_weight_evidence_source_type ?? '已关联' }} #{{ product.shipping_weight_evidence_id }}</span>
                  <span v-else-if="product.shipping_weight_kg" class="text-gray-500">历史遗留 / 未关联证据</span>
                  <span v-else>—</span>
                </p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-length">长 (cm)</label>
                <input
                  id="edit-length"
                  v-model="form.length_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.length_cm }"
                >
                <p v-if="errors.length_cm" class="erp-field-error">{{ errors.length_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-width">宽 (cm)</label>
                <input
                  id="edit-width"
                  v-model="form.width_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.width_cm }"
                >
                <p v-if="errors.width_cm" class="erp-field-error">{{ errors.width_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-height">高 (cm)</label>
                <input
                  id="edit-height"
                  v-model="form.height_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.height_cm }"
                >
                <p v-if="errors.height_cm" class="erp-field-error">{{ errors.height_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label">体积重量参考</label>
                <div class="erp-readonly-input" aria-label="体积重量参考（计算值）">—</div>
                <p style="font-size:12px;color:#7A8C94;margin:0;">参考/计算值，不作为承运计费重量依据</p>
              </div>

            </div>
          </div>

          <!-- Card 4: 海关与产地 -->
          <div class="erp-form-card" id="section-customs">
            <h2 class="erp-form-card-title">海关与产地</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-country">原产国</label>
                <input
                  id="edit-country"
                  v-model="form.country_of_origin"
                  type="text"
                  maxlength="2"
                  class="erp-input"
                  :class="{ 'has-error': errors.country_of_origin }"
                  placeholder="CN"
                >
                <p v-if="errors.country_of_origin" class="erp-field-error">{{ errors.country_of_origin }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-hs-code">HS Code</label>
                <input
                  id="edit-hs-code"
                  v-model="form.hs_code"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.hs_code }"
                >
                <p v-if="errors.hs_code" class="erp-field-error">{{ errors.hs_code }}</p>
              </div>

            </div>
          </div>

          <!-- Card 5: 成本参考 -->
          <div class="erp-form-card" id="section-cost">
            <h2 class="erp-form-card-title">成本参考</h2>
            <p class="erp-form-card-helper">当前参考成本；历史会计成本由 Accounting 成本版本管理。</p>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-prod-cost">标准生产成本</label>
                <input
                  id="edit-prod-cost"
                  v-model="form.standard_production_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_production_cost }"
                >
                <p v-if="errors.standard_production_cost" class="erp-field-error">{{ errors.standard_production_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-pack-cost">标准包装成本</label>
                <input
                  id="edit-pack-cost"
                  v-model="form.standard_packaging_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_packaging_cost }"
                >
                <p v-if="errors.standard_packaging_cost" class="erp-field-error">{{ errors.standard_packaging_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-purch-cost">标准采购成本</label>
                <input
                  id="edit-purch-cost"
                  v-model="form.standard_purchase_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_purchase_cost }"
                >
                <p v-if="errors.standard_purchase_cost" class="erp-field-error">{{ errors.standard_purchase_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="edit-cost-currency">成本币种</label>
                <input
                  id="edit-cost-currency"
                  v-model="form.cost_currency"
                  type="text"
                  maxlength="3"
                  class="erp-input"
                  :class="{ 'has-error': errors.cost_currency }"
                  placeholder="CNY"
                >
                <p v-if="errors.cost_currency" class="erp-field-error">{{ errors.cost_currency }}</p>
              </div>

              <div class="erp-form-field" style="grid-column: 1 / -1;">
                <label class="erp-form-label" for="edit-cost-note">成本备注</label>
                <textarea
                  id="edit-cost-note"
                  v-model="form.cost_note"
                  class="erp-textarea"
                  :class="{ 'has-error': errors.cost_note }"
                />
                <p v-if="errors.cost_note" class="erp-field-error">{{ errors.cost_note }}</p>
              </div>

            </div>
          </div>

          <!-- Card 6: 内部备注 -->
          <div class="erp-form-card" id="section-notes">
            <h2 class="erp-form-card-title">内部备注</h2>
            <div style="margin-top:16px;">
              <label class="erp-form-label" for="edit-notes" style="margin-bottom:5px;display:block;">备注</label>
              <textarea
                id="edit-notes"
                v-model="form.notes"
                class="erp-textarea"
                :class="{ 'has-error': errors.notes }"
              />
              <p v-if="errors.notes" class="erp-field-error">{{ errors.notes }}</p>
            </div>
          </div>

        </form>

        <!-- Danger confirmation: local confirmation actions remain in context -->
        <div v-if="confirmingDelete" class="erp-danger-zone">
          <div class="erp-danger-zone-text">
            <p>确认删除「{{ product.name }}」？</p>
            <small>此操作不可撤销，历史订单快照继续保留。</small>
          </div>
          <div style="display:flex;gap:10px;flex-shrink:0;">
            <button type="button" class="erp-btn erp-btn-secondary" style="height:38px;font-size:13px;" @click="confirmingDelete = false">取消</button>
            <button type="button" class="erp-btn-danger" @click="doDelete">确认删除</button>
          </div>
        </div>

        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.product-editor-layout {
  width:100%;
  margin:0 auto;
  display:grid;
  grid-template-columns:minmax(0,1fr);
  align-items:start;
}
.product-editor-layout > .erp-form-content {
  grid-area:1 / 1;
  min-width:0;
  width:100%;
  max-width:1080px;
  margin:0 auto;
}
.product-editor-page [id^="section-"] { scroll-margin-top:92px; }
/* Reserve the 126px left navigation gutter when the ERP sidebar reduces available width. */
@media (max-width:1680px) {
  .product-editor-page { padding-left:clamp(24px,calc(864px - 50vw),156px); }
}

.product-image-preview-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px; margin-top:16px; }
.product-image-preview-grid img { width:100%; aspect-ratio:1; object-fit:cover; border:1px solid #E4EAED; border-radius:10px; }
.product-image-preview-item { display:flex; flex-direction:column; gap:5px; }
.product-image-preview-item button { border:0; background:transparent; color:#2D8C84; cursor:pointer; font-size:12px; }
.erp-form-check { display:block; margin-top:8px; color:#53666d; font-size:12px; }
.product-image-controls { display:flex; gap:6px; flex-wrap:wrap; }
.product-image-controls button:disabled { opacity:.45; cursor:default; }
.product-woo-media { margin-top:20px; padding-top:14px; border-top:1px solid #edf1f2; }
.product-woo-media h3 { font-size:14px; font-weight:600; }
.product-woo-media p,.product-woo-media small { font-size:12px; color:#71838b; }
</style>

<style scoped>
.erp-external-ref-table-wrap {
  margin-top: 16px;
  width: 100%;
  overflow-x: auto;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}

.erp-external-ref-table {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
  table-layout: fixed;
}

.erp-external-ref-table th,
.erp-external-ref-table td {
  padding: 12px 16px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid #e5e7eb;
}

.erp-external-ref-table th {
  font-size: 12px;
  font-weight: 600;
  color: #667085;
  background: #f8fafc;
  white-space: nowrap;
}

.erp-external-ref-table td {
  font-size: 14px;
  color: #1f2937;
  overflow-wrap: anywhere;
}

.erp-external-ref-table th:nth-child(1),
.erp-external-ref-table td:nth-child(1) { width: 22%; }
.erp-external-ref-table th:nth-child(2),
.erp-external-ref-table td:nth-child(2) { width: 40%; }
.erp-external-ref-table th:nth-child(3),
.erp-external-ref-table td:nth-child(3) { width: 16%; }
.erp-external-ref-table th:nth-child(4),
.erp-external-ref-table td:nth-child(4) { width: 22%; }

.erp-external-ref-table tbody tr:last-child td { border-bottom: 0; }
</style>
