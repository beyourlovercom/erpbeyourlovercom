<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, nextTick, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import PackagingEditor from '../../Components/Products/PackagingEditor.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type ExternalReference = { provider: string; connection_key: string; reference: string; reference_type: string }
type Order = { id: number; erp_order_id?: string | null; external_order_number?: string | null; number?: string | null; order_date: string | null; status: string; quantity: number; revenue: number; currency: string }
type Shipment = { id: number; order_id: number; erp_order_id?: string | null; external_order_number?: string | null; order_number?: string | null; carrier: string | null; tracking_number: string | null; shipped_at: string | null; status: string | null }
type Inventory = { warehouse_id: number; warehouse_name: string; warehouse_slug: string; inventory_state: string; quantity_on_hand: number | null; quantity_reserved: number | null; quantity_available: number | null; expected_restock_qty: number | null }
type Audit = { action: string; action_label: string; actor_name?: string | null; actor_label?: string | null; source: string | null; created_at: string | null; reason: string | null }
type OrderColumn = 'order_number' | 'date' | 'quantity' | 'revenue' | 'status'
type ShipmentColumn = 'order_number' | 'carrier' | 'tracking' | 'shipped_at' | 'status'
type RelationColumn = 'type' | 'order_number' | 'details' | 'status'
const orderDefinitions: GridColumnDefinition<OrderColumn>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'date', label: '日期' }, { key: 'quantity', label: '数量' }, { key: 'revenue', label: '收入' }, { key: 'status', label: '状态' },
]
const shipmentDefinitions: GridColumnDefinition<ShipmentColumn>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'carrier', label: '承运商' }, { key: 'tracking', label: '追踪号' }, { key: 'shipped_at', label: '发货日期' }, { key: 'status', label: '状态' },
]
const relationDefinitions: GridColumnDefinition<RelationColumn>[] = [
  { key: 'type', label: '类型' }, { key: 'order_number', label: 'ERP订单号 / 关联对象' }, { key: 'details', label: '关键信息' }, { key: 'status', label: '状态' },
]
const orderGrid = reactive(useAccountGridPreferences<OrderColumn>({ namespace: 'grid.products.show.orders.v1', definitions: orderDefinitions, requiredKeys: ['order_number'] }))
const shipmentGrid = reactive(useAccountGridPreferences<ShipmentColumn>({ namespace: 'grid.products.show.shipments.v1', definitions: shipmentDefinitions, requiredKeys: ['order_number'] }))
const relationGrid = reactive(useAccountGridPreferences<RelationColumn>({ namespace: 'grid.products.show.relations.v1', definitions: relationDefinitions, requiredKeys: ['order_number'] }))
type ProductMedia = { id: number; url: string; alt_text: string | null; position: number; is_primary: boolean }
type SkuAlias = { id: number; alias_sku: string; source_type: string; source_reference: string | null; notes: string | null; is_active: boolean; oms_verified_at: string | null; oms_identity_evidence: string | null; updated_by: string | null; updated_at: string | null }

const props = defineProps<{
  product: Record<string, any>
  externalReferences: ExternalReference[]
  skuAliases: SkuAlias[]
  sales: { units_30d: number; order_count_30d: number; units_90d: number; order_count_90d: number; lifetime_units: number; lifetime_order_count: number; revenue_by_currency: { currency: string; revenue: number }[]; recent_orders: Order[] }
  kolShipping: { units_30d: number; shipment_count_30d: number; units_90d: number; shipment_count_90d: number; lifetime_units: number; lifetime_shipment_count: number }
  inventory: Inventory[]
  shipments: Shipment[]
  collaborations: { id: number; title: string | null; status: string; kol_id: number; kol_name: string | null }[]
  audit: Audit[]
  canOperate: boolean
  canSync?: boolean
  hasWooProductReference?: boolean
  media?: ProductMedia[]
  woo?: { provider_status: string | null; categories: string[]; description: string | null; short_description: string | null; variation_count: number | null; provider_created_at: string | null; provider_modified_at: string | null; last_synced_at: string | null; connection_key: string } | null
  parameterTask?: {id:number; enabled:boolean; is_required:boolean; assigned_user_id:number|null; required_fields:string[]}|null
  parameterSubmissions?: {id:number;status:string;created_by:number;review_note:string|null;submitted_at:string|null;measurements:Record<string,string>;accessories:{name:string;quantity:number;unit:string}[];notes?:string|null;media?:{id:number;name:string;url:string}[]}[]
  approvedParameterMeasurements?: Record<string,string>
  approvedAccessories?: {name:string;quantity:number|string;unit:string;notes:string|null}[]
  factoryUserOptions?: {id:number;name:string}[]
  packagingProfile?: {
    id:number; preset_key:string|null; method:string; scenario:string|null; version:string|null; units_per_carton:number|null;
    inherits_global_template:boolean;
    default_image:{id:number|null;name:string|null;url:string}|null;
    rendered_image:{id:number|null;name:string|null;url:string}|null;
    examples:{id:number|null;name:string|null;url:string}[]
  }|null
  packagingPresets?: {key:string;label:string}[]
  wooSyncHistory?: { status: string; result_code: string | null; error_message: string | null; completed_at: string | null; run_id: number }[]
}>()

// Product360 inline form: canonical product fields update immediately, while factory
// measurements enter the existing review queue instead of overriding approved truth.
const parametersEditing = ref(false)
const parametersSaved = ref(false)
const masterParameterFields = [
  { key: 'weight_kg', label: 'ERP 产品重量（现行标准）', suffix: 'kg' },
  { key: 'shipping_weight_kg', label: '运输参考重量', suffix: 'kg' },
  { key: 'length_cm', label: '产品长度', suffix: 'cm' },
  { key: 'width_cm', label: '产品宽度', suffix: 'cm' },
  { key: 'height_cm', label: '产品高度', suffix: 'cm' },
] as const
const reviewParameterFields = [
  { key: 'material', label: '产品材质', suffix: '' },
  { key: 'weight_composition', label: '重量组成说明', suffix: '' },
  { key: 'net_weight_kg', label: '已审核工厂净重', suffix: 'kg' },
  { key: 'color_box_gross_kg', label: '彩盒毛重', suffix: 'kg' },
  { key: 'courier_gross_kg', label: '快递毛重', suffix: 'kg' },
  { key: 'package_length_cm', label: '单件包装长度', suffix: 'cm' },
  { key: 'package_width_cm', label: '单件包装宽度', suffix: 'cm' },
  { key: 'package_height_cm', label: '单件包装高度', suffix: 'cm' },
  { key: 'carton_length_cm', label: '外箱长度', suffix: 'cm' },
  { key: 'carton_width_cm', label: '外箱宽度', suffix: 'cm' },
  { key: 'carton_height_cm', label: '外箱高度', suffix: 'cm' },
  { key: 'units_per_carton', label: '每箱数量', suffix: 'pcs' },
  { key: 'carton_gross_weight_kg', label: '整箱毛重', suffix: 'kg' },
] as const
const reviewInitial = () => Object.fromEntries(reviewParameterFields.map(
  field => [field.key, String(props.approvedParameterMeasurements?.[field.key] ?? '')]
)) as Record<string, string>
const parametersForm = useForm({
  barcode_69: String(props.product.barcode_69 ?? ''),
  weight_kg: String(props.product.weight_kg ?? ''),
  shipping_weight_kg: String(props.product.shipping_weight_kg ?? ''),
  length_cm: String(props.product.length_cm ?? ''),
  width_cm: String(props.product.width_cm ?? ''),
  height_cm: String(props.product.height_cm ?? ''),
  review_measurements: reviewInitial(),
})
const parameterErrors = computed(() => parametersForm.errors as Record<string, string>)
function beginParametersEdit() {
  parametersForm.barcode_69 = String(props.product.barcode_69 ?? '')
  for (const field of masterParameterFields) {
    parametersForm[field.key] = String(props.product[field.key] ?? '')
  }
  parametersForm.review_measurements = reviewInitial()
  parametersForm.clearErrors()
  parametersEditing.value = true
  parametersSaved.value = false
}
function cancelParametersEdit() {
  parametersEditing.value = false
  parametersForm.clearErrors()
}
function saveInlineParameters() {
  parametersForm.transform(data => {
    const { review_measurements, ...main } = data
    const initial = reviewInitial()
    const measurements = Object.fromEntries(Object.entries(review_measurements).filter(
      ([key, value]) => String(value).trim() !== String(initial[key] ?? '').trim()
    ))
    return { ...main, measurements }
  }).put('/products/' + props.product.id + '/inline-parameters', {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { parametersEditing.value = false; parametersSaved.value = true },
  })
}
const tabs = ['概览', '产品参数', '销售与合作', '库存与物流', '关联与记录'] as const
type Tab = typeof tabs[number]
const subTabs: Record<Tab, readonly string[]> = {
  '概览': [],
  '产品参数': ['参数总览', '参数填报与审核', '包装与配件', '渠道与 SKU', '成本与海关'],
  '销售与合作': ['销售记录', 'KOL 合作'],
  '库存与物流': ['库存明细', '物流记录'],
  '关联与记录': ['业务关联', '操作记录'],
}
const aliasLinked = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'aliases'
const activeTab = ref<Tab>(aliasLinked ? '产品参数' : '概览')
const activeSubTab = ref(aliasLinked ? '渠道与 SKU' : '')
const navigateTab = (tab: Tab) => {
  activeTab.value = tab
  activeSubTab.value = subTabs[tab][0] ?? ''
}
const selectSubRelative = async (sub: string, delta: number) => {
  const items = subTabs[activeTab.value]
  const index = items.indexOf(sub)
  if (index < 0 || items.length === 0) return
  activeSubTab.value = items[(index + delta + items.length) % items.length]
  await nextTick()
  Array.from(document.querySelectorAll<HTMLButtonElement>('[data-product360-subtab]'))
    .find(button => button.dataset.product360Subtab === activeSubTab.value)?.focus()
}
const display = (value: unknown) => value === null || value === undefined || value === '' ? '—' : String(value)
const date = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
const money = (value: number, currency: string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)
const source = (value: string | null) => ({ manual: '手动', woocommerce: 'WooCommerce' } as Record<string, string>)[value ?? ''] ?? display(value)
const aliasSource = (value: string | null) => ({ manual: '手动', woocommerce: 'WooCommerce', legacy_import: '历史导入' } as Record<string, string>)[value ?? ''] ?? display(value)
const auditSource = (value: string | null) => ({ web: '网页', system: '系统', 'sync:outbox': '同步队列', 'woocommerce:inbound': 'Woo 入站', fortify: '登录' } as Record<string, string>)[value ?? ''] ?? display(value)
const inventoryState = (value: string) => ({ confirmed: '已确认', partial_only: '部分同步', manual: '手工维护', never_imported: '未导入' } as Record<string, string>)[value] ?? value
const cost = (value: unknown) => value === null || value === undefined || value === '' ? '—' : `${value} ${display(props.product.cost_currency)}`
const weight = (value: unknown) => value === null || value === undefined || value === '' ? '—' : `${value} kg`
const evidenceSource = (value: string | null | undefined) => ({ woocommerce_reference: 'WooCommerce 参考', manual_entry: '手工录入', manual_measurement: '手工测量', supplier_document: '供应商文件', warehouse_measurement: '仓库实测', historical_logistics: '历史物流', other_documented: '其他已记录资料' } as Record<string, string>)[value ?? ''] ?? '—'
const kolShippingRatio = computed(() => props.sales.lifetime_units > 0 ? props.kolShipping.lifetime_units / props.sales.lifetime_units : null)
const wooGroups = computed(() => Object.values(props.externalReferences.reduce((groups, item) => {
  if (item.provider !== 'woocommerce') return groups
  const group = groups[item.connection_key] ?? { connectionKey: item.connection_key, products: [], variations: [] }
  ;(item.reference_type === 'variation' ? group.variations : group.products).push(item.reference)
  groups[item.connection_key] = group
  return groups
}, {} as Record<string, { connectionKey: string; products: string[]; variations: string[] }>)))
const mediaItems = computed(() => props.media ?? [])
const aliasCreate = useForm({ alias_sku: '', source_type: 'manual', source_reference: '', notes: '', same_identity_confirmed: false })
const aliasVerify = useForm({ physical_evidence: '', same_identity_confirmed: false })
const aliasError = ref('')
const verifyingAliasId = ref<number | null>(null)
const aliasesPath = (id?: number) => `/products/${props.product.id}/aliases${id ? '/'+id : ''}`
const setAliasTab = () => {
  activeTab.value = '产品参数'
  activeSubTab.value = '渠道与 SKU'
  if (typeof window !== 'undefined') {
    const url = new URL(window.location.href)
    url.searchParams.set('tab', 'aliases')
    window.history.replaceState(window.history.state, '', url)
  }
}
const submitAlias = () => {
  aliasError.value = ''
  aliasCreate.post(aliasesPath(), {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { aliasCreate.reset(); setAliasTab() },
  })
}
const toggleAlias = (alias: SkuAlias) => {
  aliasError.value = ''
  if (alias.is_active && !window.confirm(`停用别名 ${alias.alias_sku}？停用后无法用于身份识别或 OMS 发货。`)) return
  router.patch(aliasesPath(alias.id) + '/status', { is_active: !alias.is_active }, {
    preserveScroll: true, preserveState: true,
    onError: errors => { aliasError.value = Object.values(errors).join('；') },
  })
}
const deleteAlias = (alias: SkuAlias) => {
  aliasError.value = ''
  if (!window.confirm(`确定删除 SKU 别名 ${alias.alias_sku}？此操作会被审计。`)) return
  router.delete(aliasesPath(alias.id), {
    data: { confirm_delete: true }, preserveScroll: true, preserveState: true,
    onError: errors => { aliasError.value = Object.values(errors).join('；') },
  })
}
const submitVerify = (alias: SkuAlias) => {
  aliasError.value = ''
  aliasVerify.post(aliasesPath(alias.id) + '/verify', {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { verifyingAliasId.value = null; aliasVerify.reset(); setAliasTab() },
  })
}
const factoryTask = reactive({
  enabled: props.parameterTask?.enabled ?? false,
  is_required: props.parameterTask?.is_required ?? false,
  assigned_user_id: props.parameterTask?.assigned_user_id ?? null as number|null,
  required_fields: [...(props.parameterTask?.required_fields ?? [])] as string[],
})
const measured = (key: string) => props.approvedParameterMeasurements?.[key] ?? null
const approvedMeasure = (key: string, suffix = '') => measured(key) ? String(measured(key)) + suffix : '待填写'
const parameterLabels: Record<string,string> = {
  material:'产品材质',weight_composition:'重量组成说明',
  product_length_cm:'裸品长 cm',product_width_cm:'裸品宽 cm',product_height_cm:'裸品高 cm',
  net_weight_kg:'产品净重 kg',color_box_gross_kg:'彩盒毛重 kg',
  courier_gross_kg:'快递毛重 kg',shipping_weight_kg:'运输参考重量 kg',
  package_length_cm:'包装长 cm',package_width_cm:'包装宽 cm',package_height_cm:'包装高 cm',
  carton_length_cm:'外箱长 cm',carton_width_cm:'外箱宽 cm',carton_height_cm:'外箱高 cm',
  units_per_carton:'每箱数量 pcs',carton_gross_weight_kg:'整箱毛重 kg'
}
const parameterLabel=(key:string)=>parameterLabels[key] ?? key
const coreRequired = [
  {key:'material',name:'产品材质'}, {key:'weight_composition',name:'重量组成'},
  {key:'net_weight_kg',name:'净重'}, {key:'color_box_gross_kg',name:'彩盒毛重'},
  {key:'courier_gross_kg',name:'快递毛重'},
  {key:'package_length_cm',name:'包装长'}, {key:'package_width_cm',name:'包装宽'},
  {key:'package_height_cm',name:'包装高'}, {key:'units_per_carton',name:'每箱数量'},
]
function saveFactoryTask() {
  router.post('/products/parameter-tasks/'+props.product.id, {...factoryTask}, {preserveScroll:true})
}
function reviewSubmission(id:number, decision:'approved'|'rejected') {
  const target=(props.parameterSubmissions??[]).find(x=>x.id===id)
  if (!target || target.status!=='submitted') return
  let review_note=''
  if (decision==='rejected') {
    const explanation=window.prompt('请填写驳回理由（工厂员工可在记录中查看）')
    if(!explanation?.trim()) return
    review_note=explanation.trim()
  }
  const apply_accessories = decision === 'approved' && !!target.accessories?.length
    && window.confirm('是否将已审核配件清单采纳为该产品当前清单？')
  router.post('/products/parameter-submissions/'+props.product.id+'/'+id+'/review',
    {decision,apply_accessories,review_note},{preserveScroll:true})
}
const declaredCartonOverfilled=(measurements:Record<string,string>)=>{
  const m=measurements
  const pack=['package_length_cm','package_width_cm','package_height_cm'].map(k=>Number(m[k]))
  const box=['carton_length_cm','carton_width_cm','carton_height_cm'].map(k=>Number(m[k]))
  const units=Number(m.units_per_carton)
  return [...pack,...box,units].every(x=>Number.isFinite(x)&&x>0)
    && pack.reduce((a,b)=>a*b,1)*units > box.reduce((a,b)=>a*b,1)
}

const selectRelative = async (tab: Tab, delta: number) => {
  const index = tabs.indexOf(tab)
  navigateTab(tabs[(index + delta + tabs.length) % tabs.length])
  await nextTick()
  document.querySelector<HTMLButtonElement>(`[data-product360-tab="${activeTab.value}"]`)?.focus()
}
</script>

<template>
  <Head :title="`${product.name} · Product360`" />
  <AuthenticatedLayout title="产品360" :breadcrumbs="['BYL ERP', '产品', product.name]">
    <section class="mvp-page product360-page">
      <article class="erp-form-card product360-hero"><div><div class="product360-image" aria-label="产品图片占位"><img v-if="product.image_url" :src="product.image_url" :alt="product.name"><span v-else>暂无图片</span></div><div v-if="mediaItems.length > 1" class="product360-thumbnails"><img v-for="item in mediaItems" :key="item.id" :src="item.url" :alt="item.alt_text || product.name"></div></div><div class="product360-identity"><p class="mvp-kicker">{{ product.product_type }}</p><h1>{{ product.name }}</h1><p class="product360-sku">SKU {{ display(product.sku) }}</p><div class="product360-meta"><span>产品 ID：{{ product.id }}</span><span>来源：{{ source(product.source_type) }}</span><span>状态：{{ product.status === 'active' ? '启用' : '停用' }}</span><span>类型：{{ display(product.product_type) }}</span><span v-if="product.woo_sync_status">Woo 同步：{{ product.woo_sync_status === 'synced' ? '已同步' : (product.woo_sync_status === 'not_synced' ? '未同步' : '未配置') }}</span></div><div v-if="wooGroups.length" class="product360-external-summary"><span v-for="group in wooGroups" :key="group.connectionKey">{{ group.connectionKey }} · 产品 {{ group.products.join(', ') || '—' }}<template v-if="group.variations.length"> / 变体 {{ group.variations.join(', ') }}</template></span></div><p v-else class="product360-external-summary">Woo 外部引用：—</p></div><div class="product360-hero-actions"><Link href="/products/list" class="mvp-button mvp-button-secondary">返回列表</Link><a v-if="product.woo_url" :href="product.woo_url" target="_blank" rel="noreferrer" class="mvp-button mvp-button-secondary">查看 Woo</a><Link v-if="canSync && hasWooProductReference" :href="`/products/${product.id}/sync-woocommerce`" method="post" as="button" class="mvp-button mvp-button-secondary">刷新 Woo</Link><button type="button" class="mvp-button mvp-button-secondary" disabled title="当前没有可用的更多操作">更多</button><Link v-if="canOperate" :href="`/products/${product.id}/edit`" class="mvp-button mvp-button-primary">编辑产品</Link></div></article>
      <div class="product360-tabs" role="tablist" aria-label="产品360标签页"><button v-for="tab in tabs" :key="tab" type="button" role="tab" :data-product360-tab="tab" :aria-selected="activeTab === tab" aria-controls="product360-content" :class="{ active: activeTab === tab }" @click="navigateTab(tab)" @keydown.left.prevent="selectRelative(tab, -1)" @keydown.right.prevent="selectRelative(tab, 1)">{{ tab }}</button></div>
      <nav v-if="activeTab !== '概览'" class="product360-subtabs" role="tablist" :aria-label="activeTab + '二级标签'">
        <button v-for="sub in subTabs[activeTab]" :key="sub" type="button" role="tab" :aria-selected="activeSubTab === sub"
          :data-product360-subtab="sub" :class="{ active: activeSubTab === sub }" @click="activeSubTab = sub" @keydown.left.prevent="selectSubRelative(sub, -1)" @keydown.right.prevent="selectSubRelative(sub, 1)">{{ sub }}</button>
      </nav>
      <div id="product360-content">


      <div v-if="activeTab === '概览'" id="product360-0" class="product360-overview" role="tabpanel" tabindex="0">
        <div class="product360-overview-top">
          <article class="erp-form-card product360-summary-card product360-summary-sales"><div class="product360-summary-head"><span class="product360-summary-icon">售</span><h3>销售摘要</h3></div><div class="product360-kpis"><div><small>近 30 天</small><strong>{{ sales.units_30d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_30d }} 笔订单</span></div><div><small>近 90 天</small><strong>{{ sales.units_90d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_90d }} 笔订单</span></div><div><small>累计</small><strong>{{ sales.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ sales.lifetime_order_count }} 笔订单</span></div></div></article>
          <article class="erp-form-card product360-summary-card product360-summary-kol"><div class="product360-summary-head"><span class="product360-summary-icon">发</span><h3>网红发货摘要</h3><span class="product360-summary-help" title="仅统计已实际发货的 KOL 履约订单，按发货日期与当前产品数量聚合">ⓘ</span></div><div class="product360-kpis"><div><small>近 30 天</small><strong>{{ kolShipping.units_30d }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.shipment_count_30d }} 笔发货</span></div><div><small>近 90 天</small><strong>{{ kolShipping.units_90d }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.shipment_count_90d }} 笔发货</span></div><div><small>累计</small><strong>{{ kolShipping.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.lifetime_shipment_count }} 笔发货</span></div></div></article>
        </div>
        <div class="product360-comparison" aria-label="销售与网红发货对比"><strong>销售 / 网红发货 对比</strong><span>销售累计：<b>{{ sales.lifetime_units }}</b></span><span>网红发货累计：<b>{{ kolShipping.lifetime_units }}</b></span><span>发货 / 销售比例：<b>{{ kolShippingRatio === null ? '—' : `${kolShippingRatio.toFixed(2)} 倍` }}</b></span></div>
        <div class="product360-overview-bottom">
          <article class="erp-form-card product360-summary-card"><div class="product360-summary-head"><span class="product360-summary-icon product360-summary-icon-logistics">物</span><h3>物流摘要</h3></div><p>净重：{{ weight(product.weight_kg) }} · 运重：{{ weight(product.shipping_weight_kg) }}</p><p>尺寸：{{ [product.length_cm, product.width_cm, product.height_cm].every(v => v !== null && v !== undefined) ? `${product.length_cm} × ${product.width_cm} × ${product.height_cm} cm` : '—' }}</p></article>
          <article class="erp-form-card product360-summary-card"><div class="product360-summary-head"><span class="product360-summary-icon product360-summary-icon-stock">库</span><h3>库存摘要</h3></div><div v-if="inventory.length" class="product360-stock-summary"><span v-for="row in inventory" :key="row.warehouse_id">{{ row.warehouse_name }}：{{ row.quantity_available === null ? '库存未知' : `${row.quantity_available} 可用` }}</span></div><p v-else class="mvp-empty">暂无活跃仓库库存数据</p></article>
        </div>
        <article class="erp-form-card product360-woo-overview"><div class="product360-summary-head"><span class="product360-summary-icon">W</span><h3>WooCommerce 摘要</h3></div><template v-if="woo"><p>{{ display(woo.short_description || woo.description) }}</p><p>状态：{{ display(woo.provider_status) }} · 分类：{{ woo.categories.join('、') || '—' }} · 最近同步：{{ date(woo.last_synced_at) }}</p></template><p v-else class="mvp-empty">暂无 WooCommerce 产品数据</p></article>
      </div>
      <article v-else-if="activeTab === '产品参数' && activeSubTab === '参数总览'" class="erp-form-card" role="tabpanel" tabindex="0">
        <div class="product360-inline-head">
          <h3>产品参数总览</h3>
          <div v-if="canOperate" class="product360-inline-actions">
            <template v-if="parametersEditing">
              <button type="button" class="mvp-button mvp-button-secondary" :disabled="parametersForm.processing" @click="cancelParametersEdit">取消</button>
              <button type="button" class="mvp-button mvp-button-primary" :disabled="parametersForm.processing" @click="saveInlineParameters">{{ parametersForm.processing ? '保存中…' : '保存参数' }}</button>
            </template>
            <button v-else type="button" class="mvp-button mvp-button-primary" @click="beginParametersEdit">编辑参数</button>
          </div>
        </div>
        <p v-if="parametersSaved && !parametersEditing" class="product360-inline-success" role="status">已完成保存。需审核的工厂参数将在审核通过后更新显示。</p>
        <div v-if="parametersEditing" class="product360-inline-editor">
          <p class="product360-meta-note">直接编辑 ERP 主档；带“审核”的参数保存后进入待审核队列，不会覆盖现有已确认数据。</p>
          <h4>ERP 主档 · 保存后生效</h4>
          <div class="product360-inline-fields">
            <label class="product360-inline-field" v-for="field in masterParameterFields" :key="field.key">
              <span>{{ field.label }} <small>{{ field.suffix }}</small></span>
              <input v-model="parametersForm[field.key]" type="number" step="any" min="0" inputmode="decimal" placeholder="待填写" :aria-invalid="!!parameterErrors[field.key]" />
              <small v-if="parameterErrors[field.key]" class="product360-inline-error">{{ parameterErrors[field.key] }}</small>
            </label>
            <label class="product360-inline-field">
              <span>69条形码 <small>8–14 位数字，作为文本保存</small></span>
              <input v-model="parametersForm.barcode_69" type="text" inputmode="numeric" maxlength="14" placeholder="待填写" :aria-invalid="!!parameterErrors.barcode_69" />
              <small v-if="parameterErrors.barcode_69" class="product360-inline-error">{{ parameterErrors.barcode_69 }}</small>
            </label>
          </div>
          <h4>工厂测量 · 保存后待审核</h4>
          <div class="product360-inline-fields">
            <label class="product360-inline-field" v-for="field in reviewParameterFields" :key="field.key">
              <span>{{ field.label }} <small>{{ field.suffix }}</small></span>
              <input v-model="parametersForm.review_measurements[field.key]" :type="field.key === 'material' || field.key === 'weight_composition' ? 'text' : 'number'" :step="field.key === 'units_per_carton' ? '1' : 'any'" min="0" placeholder="待填写" :aria-invalid="!!parameterErrors['measurements.'+field.key]" />
              <small v-if="parameterErrors['measurements.'+field.key]" class="product360-inline-error">{{ parameterErrors['measurements.'+field.key] }}</small>
            </label>
          </div>
          <p class="product360-meta-note">SKU、名称和产品类型在这里保持只读。已审核工厂参数不能直接清空；如需更改，请填写新值并提交审核。</p>
          <p v-if="Object.keys(parameterErrors).length" role="alert" class="product360-inline-error">请按提示修正输入后重新保存。</p>
        </div>
        <div v-else class="product360-detail-grid product360-parameters-grid">
          <p>产品名称：{{ display(product.name) }}</p><p>SKU：{{ display(product.sku) }}</p>
          <p>产品类型：{{ display(product.product_type) }}</p><p>产品材质：{{ approvedMeasure('material') }}</p>
          <p>重量组成说明：{{ approvedMeasure('weight_composition') }}</p>
          <p>已审核工厂净重：{{ approvedMeasure('net_weight_kg',' kg') }}</p>
          <p>ERP 产品重量（现行标准）：{{ weight(product.weight_kg) }}</p>
          <p>彩盒毛重：{{ approvedMeasure('color_box_gross_kg',' kg') }}</p><p>快递毛重：{{ approvedMeasure('courier_gross_kg',' kg') }}</p>
          <p>运输参考重量：{{ weight(product.shipping_weight_kg) }}</p>
          <p>产品尺寸：{{ [product.length_cm, product.width_cm, product.height_cm].every(v => v !== null && v !== undefined) ? [product.length_cm, product.width_cm, product.height_cm].join(' × ') + ' cm' : '待填写' }}</p>
          <p>单件包装尺寸：{{ measured('package_length_cm') && measured('package_width_cm') && measured('package_height_cm') ? [measured('package_length_cm'),measured('package_width_cm'),measured('package_height_cm')].join(' × ') + ' cm' : '待填写' }}</p><p>外箱尺寸：{{ measured('carton_length_cm') && measured('carton_width_cm') && measured('carton_height_cm') ? [measured('carton_length_cm'),measured('carton_width_cm'),measured('carton_height_cm')].join(' × ') + ' cm' : '待填写' }} · 装箱：{{ approvedMeasure('units_per_carton',' pcs') }} · 整箱毛重：{{ approvedMeasure('carton_gross_weight_kg',' kg') }}</p>
          <p>69条形码：{{ display(product.barcode_69) }}</p>
        </div>
        <p class="product360-meta-note">产品净重、运输参考重量、彩盒毛重和快递毛重不能互相替代。待核实资料不得伪装为已确认参数。</p>
        <div class="product360-v2-actions"><button type="button" class="mvp-button mvp-button-secondary" @click="activeSubTab='参数填报与审核'">工厂填报</button><button type="button" class="mvp-button mvp-button-secondary" @click="activeSubTab='包装与配件'">包装与配件</button></div>
      </article>
      <article v-else-if="activeTab === '产品参数' && activeSubTab === '参数填报与审核'" class="erp-form-card" role="tabpanel" tabindex="0">
        <h3>参数填报与审核</h3>
        <p>有产品查看权限的账号可直接填写本产品参数；也可为工厂员工指定专属任务。工厂填写材料、重量、包装尺寸与配件，照片选填；提交后由产品管理员审核。</p>
        <p class="product360-meta-note">此阶段不向工厂开放产品主档修改权限，不自动采纳测量结果；原有重量证据审核照常运行。</p>
        <div class="product360-factory-task"><a :href="'/products/factory-parameters/'+product.id" class="mvp-button mvp-button-secondary">填写本产品参数 →</a><div v-if="canOperate">
          <h4>工厂专项任务（可选）</h4>
          <a href="/products/factory-parameters" class="product360-meta-note">工厂员工任务入口（仅显示被分配的产品） →</a>
          <label><input v-model="factoryTask.enabled" type="checkbox" /> 启用填写任务</label>
          <label><input v-model="factoryTask.is_required" type="checkbox" /> 必填任务</label>
          <label>指派员工
            <select v-model.number="factoryTask.assigned_user_id">
              <option :value="null">请选择员工</option>
              <option v-for="u in factoryUserOptions ?? []" :key="u.id" :value="u.id">{{ u.name }} · #{{ u.id }}</option>
            </select>
          </label>
          <div class="product360-required-fields"><strong>指定必填字段（可选）</strong>
            <label v-for="field in coreRequired" :key="field.key">
              <input v-model="factoryTask.required_fields" type="checkbox" :value="field.key"/> {{ field.name }}
            </label>
          </div>
          <button type="button" class="mvp-button mvp-button-primary" @click="saveFactoryTask">保存任务与权限</button>
          <p class="product360-meta-note">工厂员工仅能查看获指派产品的独立参数填报页，不能因此获得产品成本或审核权限。</p>
          </div><h4>近期测量提交</h4>
          <div v-for="record in parameterSubmissions ?? []" :key="record.id" class="product360-factory-record">
            <div class="product360-evidence-head">
              <strong>#{{record.id}} · {{record.status}} · 填报人 #{{record.created_by}}</strong>
              <small>{{record.submitted_at ? date(record.submitted_at) : '未提交'}}</small>
            </div>
            <div class="product360-evidence-fields">
              <span v-for="(val,key) in record.measurements" :key="key">{{parameterLabel(String(key))}}：{{val}}</span>
            </div>
            <p v-if="record.notes" class="product360-meta-note">说明：{{record.notes}}</p>
            <p v-if="record.accessories?.length" class="product360-meta-note">配件：{{record.accessories.map(a=>a.name+' × '+a.quantity+' '+a.unit).join('、')}}</p>
            <div v-if="record.media?.length" class="product360-v2-actions">
              <a v-for="photo in record.media" :key="photo.id" :href="photo.url" target="_blank" rel="noopener" class="mvp-button mvp-button-secondary">查看照片：{{photo.name}}</a>
            </div>
            <p v-if="declaredCartonOverfilled(record.measurements)" class="product360-anomaly">包装体积 × 装箱数量超过外箱体积，禁止直接审核采纳；请工厂核实。</p>
            <p v-if="record.review_note" class="product360-meta-note">审核备注：{{record.review_note}}</p>
            <div v-if="record.status === 'submitted'" class="product360-v2-actions">
              <button type="button" :disabled="declaredCartonOverfilled(record.measurements)" @click="reviewSubmission(record.id,'approved')">审核通过</button>
              <button type="button" @click="reviewSubmission(record.id,'rejected')">驳回</button>
            </div>
          </div>
          <p v-if="!parameterSubmissions?.length" class="product360-meta-note">暂无测量提交记录</p>
        </div>
        <div class="product360-v2-actions"><Link href="/products/weight-evidence" class="mvp-button mvp-button-secondary">现有重量证据工作区</Link><Link v-if="canOperate" :href="'/products/' + product.id + '/edit'" class="mvp-button mvp-button-secondary">现有产品编辑</Link></div>
      </article>
      <article v-else-if="activeTab === '产品参数' && activeSubTab === '包装与配件'" class="erp-form-card" role="tabpanel" tabindex="0">
        <h3>包装与配件</h3><p>包装方案、包装预设、方案版本、装箱数量、主示意图和案例图片都可以在当前 Tab 直接管理。</p>
        <p class="product360-meta-note">已审核采纳的包装内含物 / 配件清单：</p>
        <table v-if="approvedAccessories?.length" class="mvp-table"><thead><tr><th>配件名称</th><th>数量</th><th>单位</th><th>说明</th></tr></thead>
          <tbody><tr v-for="(part,i) in approvedAccessories" :key="i"><td>{{part.name}}</td><td>{{part.quantity}}</td><td>{{part.unit}}</td><td>{{part.notes||'—'}}</td></tr></tbody></table>
        <p v-else class="mvp-empty">尚未有经过审核的包装内配件清单</p>
        <PackagingEditor v-if="canOperate" embedded :product="{id:Number(product.id),sku:product.sku ?? null,name:String(product.name),barcode_69:product.barcode_69 ?? null}" :packaging-profile="packagingProfile ?? null" :presets="packagingPresets ?? []" />
        <p v-else class="product360-meta-note">包装方案维护需要产品操作权限；已审核配件仍可查阅。</p>
      </article>
      <article v-else-if="activeTab === '销售与合作' && activeSubTab === 'KOL 合作'" class="erp-form-card" role="tabpanel" tabindex="0">
        <h3>KOL 合作</h3>
        <p>近 30 天 KOL 发货：{{ kolShipping.units_30d }} 件 / {{ kolShipping.shipment_count_30d }} 笔；近 90 天：{{ kolShipping.units_90d }} 件；累计：{{ kolShipping.lifetime_units }} 件 / {{ kolShipping.lifetime_shipment_count }} 笔。</p>
        <table v-if="collaborations.length" class="mvp-table"><thead><tr><th>合作 ID</th><th>KOL</th><th>合作标题</th><th>状态</th></tr></thead><tbody><tr v-for="item in collaborations" :key="item.id"><td>{{ item.id }}</td><td>{{ display(item.kol_name) }}</td><td>{{ display(item.title) }}</td><td>{{ item.status }}</td></tr></tbody></table>
        <p v-else class="mvp-empty">暂无 KOL 合作</p>
      </article>
      <article v-else-if="activeTab === '销售与合作' && activeSubTab === '销售记录'" id="product360-1" class="erp-form-card" role="tabpanel" tabindex="0"><div class="product360-panel-head"><h3>销售</h3><div class="product360-price-line"><span>售价 {{ display(product.sale_price) }}</span><span>原价 {{ display(product.regular_price) }}</span></div></div><div class="product360-kpis product360-kpis-sales"><div><small>近 30 天</small><strong>{{ sales.units_30d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_30d }} 笔订单</span></div><div><small>近 90 天</small><strong>{{ sales.units_90d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_90d }} 笔订单</span></div><div><small>累计</small><strong>{{ sales.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ sales.lifetime_order_count }} 笔订单</span></div></div><p class="product360-section-label">收入（按原始币种）</p><div v-if="sales.revenue_by_currency.length" class="product360-inline-list"><span v-for="row in sales.revenue_by_currency" :key="row.currency">{{ money(row.revenue, row.currency) }}</span></div><p v-else class="mvp-empty">暂无客户订单收入</p><p class="product360-section-label">最近订单</p><table v-if="sales.recent_orders.length" class="mvp-table"><thead><tr><th v-for="column in orderGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="orderGrid.columns" :required-keys="['order_number']" :sync-state="orderGrid.syncState" @toggle="orderGrid.toggle" @start-drag="orderGrid.startDrag" @drop="orderGrid.drop" @reset="orderGrid.reset" /></tr></thead><tbody><tr v-for="order in sales.recent_orders" :key="order.id"><td v-for="column in orderGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link :href="`/customers/orders/${order.id}`" class="mvp-link">{{ display(order.erp_order_id) }}</Link></template><template v-else-if="column.key === 'date'">{{ display(order.order_date) }}</template><template v-else-if="column.key === 'quantity'">{{ order.quantity }}</template><template v-else-if="column.key === 'revenue'">{{ money(order.revenue, order.currency) }}</template><template v-else>{{ order.status }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无相关客户订单</p></article>
      <article v-else-if="activeTab === '库存与物流' && activeSubTab === '库存明细'" id="product360-2" class="erp-form-card" role="tabpanel" tabindex="0"><h3>库存</h3><table v-if="inventory.length" class="mvp-table"><thead><tr><th>仓库</th><th>状态</th><th>在手</th><th>预留</th><th>可用</th><th>入库</th></tr></thead><tbody><tr v-for="row in inventory" :key="row.warehouse_id"><td>{{ row.warehouse_name }}</td><td>{{ inventoryState(row.inventory_state) }}</td><td>{{ row.quantity_on_hand ?? '未知' }}</td><td>{{ row.quantity_reserved ?? '未知' }}</td><td>{{ row.quantity_available ?? '未知' }}</td><td>{{ row.expected_restock_qty ?? '未知' }}</td></tr></tbody></table><p v-else class="mvp-empty">暂无活跃仓库</p></article>
      <article v-else-if="activeTab === '库存与物流' && activeSubTab === '物流记录'" id="product360-3" class="erp-form-card" role="tabpanel" tabindex="0"><h3>物流</h3><div class="product360-detail-grid"><p>净重：{{ weight(product.weight_kg) }} <small class="product360-evidence">证据：{{ evidenceSource(product.weight_evidence_source_type) }}<template v-if="product.weight_evidence_source_reference"> · {{ product.weight_evidence_source_reference }}</template></small></p><p>运重：{{ weight(product.shipping_weight_kg) }} <small class="product360-evidence">证据：{{ evidenceSource(product.shipping_weight_evidence_source_type) }}<template v-if="product.shipping_weight_evidence_source_reference"> · {{ product.shipping_weight_evidence_source_reference }}</template></small></p><p>尺寸：{{ [product.length_cm, product.width_cm, product.height_cm].every(v => v !== null && v !== undefined) ? `${product.length_cm} × ${product.width_cm} × ${product.height_cm} cm` : '—' }}</p><p>体积重参考：—</p></div><table v-if="shipments.length" class="mvp-table"><thead><tr><th v-for="column in shipmentGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="shipmentGrid.columns" :required-keys="['order_number']" :sync-state="shipmentGrid.syncState" @toggle="shipmentGrid.toggle" @start-drag="shipmentGrid.startDrag" @drop="shipmentGrid.drop" @reset="shipmentGrid.reset" /></tr></thead><tbody><tr v-for="shipment in shipments" :key="shipment.id"><td v-for="column in shipmentGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="shipment.erp_order_id" :href="`/customers/orders/${shipment.order_id}`" class="mvp-link">{{ display(shipment.erp_order_id) }}</Link><span v-else>—</span></template><template v-else-if="column.key === 'carrier'">{{ display(shipment.carrier) }}</template><template v-else-if="column.key === 'tracking'">{{ display(shipment.tracking_number) }}</template><template v-else-if="column.key === 'shipped_at'">{{ display(shipment.shipped_at) }}</template><template v-else>{{ display(shipment.status) }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无相关发货记录</p></article>
      <article v-else-if="activeTab === '产品参数' && activeSubTab === '成本与海关'" id="product360-4" class="erp-form-card" role="tabpanel" tabindex="0"><h3>成本与海关</h3><div class="product360-detail-grid"><p>生产成本：{{ cost(product.standard_production_cost) }}</p><p>包材成本：{{ cost(product.standard_packaging_cost) }}</p><p>采购参考成本：{{ cost(product.standard_purchase_cost) }}</p><p>原产国：{{ display(product.country_of_origin) }}</p><p>HS Code：{{ display(product.hs_code) }}</p><p>成本备注：{{ display(product.cost_note) }}</p></div></article>
      <div v-else-if="activeTab === '产品参数' && activeSubTab === '渠道与 SKU'" class="product360-channel-panels">
      <article id="product360-5" class="erp-form-card" role="tabpanel" tabindex="0"><h3>Woo 信息</h3><div v-if="wooGroups.length" class="product360-woo-groups"><section v-for="group in wooGroups" :key="group.connectionKey" class="product360-woo-group"><div class="product360-woo-group-head"><strong>{{ group.connectionKey }}</strong><span>WooCommerce</span></div><div class="product360-woo-group-refs"><span v-for="reference in group.products" :key="group.connectionKey + '-product-' + reference">Product {{ reference }}</span><span v-for="reference in group.variations" :key="group.connectionKey + '-variation-' + reference">Variation {{ reference }}</span></div></section></div><div v-if="woo" class="product360-detail-grid"><p>状态：{{ display(woo.provider_status) }}</p><p>分类：{{ woo.categories.join('、') || '—' }}</p><p>变体数：{{ woo.variation_count ?? '—' }}</p><p>创建：{{ date(woo.provider_created_at) }}</p><p>修改：{{ date(woo.provider_modified_at) }}</p><p>最近同步：{{ date(woo.last_synced_at) }}</p></div><p v-if="woo?.description" class="product360-woo-description">{{ woo.description }}</p><p v-else-if="!woo" class="mvp-empty">暂无 Woo 外部引用</p><div v-if="wooSyncHistory?.length" class="product360-sync-history"><h4>最近同步历史</h4><p v-for="entry in wooSyncHistory" :key="entry.run_id">{{ entry.result_code || entry.status }} · {{ date(entry.completed_at) }}<span v-if="entry.error_message"> · {{ entry.error_message }}</span></p></div><p class="product360-compat-note">Legacy Woo ID（兼容证据）：{{ product.woocommerce_product_id ?? '—' }}</p></article>
      <article id="product360-6" class="erp-form-card product360-alias-panel" role="tabpanel" tabindex="0">
        <h3>产品 SKU 身份</h3>
        <div class="product360-alias-hero"><span>ERP 主 SKU · 只读</span><strong>{{ display(product.sku) }}</strong><small>一个型号 / 颜色 / 规格对应一个产品，别名不会拆分库存。</small></div>
        <div class="product360-alias-bar"><h3>已登记 SKU 别名（{{ skuAliases.length }}）</h3><span>OMS 发货仍需逐仓核对商品目录、实物和库存</span></div>
        <p v-if="aliasError" class="product360-alias-error" role="alert">{{ aliasError }}</p>
        <div class="product360-alias-overflow">
          <table v-if="skuAliases.length" class="mvp-table product360-alias-table">
            <thead><tr><th>SKU 别名</th><th>来源 / 备注</th><th>身份状态</th><th>OMS 核实</th><th>最后修改</th><th v-if="canOperate">操作</th></tr></thead>
            <tbody><template v-for="alias in skuAliases" :key="alias.id">
              <tr>
                <td><strong>{{ alias.alias_sku }}</strong></td>
                <td><span>{{ aliasSource(alias.source_type) }}</span><small v-if="alias.source_reference">{{ alias.source_reference }}</small><small v-if="alias.notes">{{ alias.notes }}</small></td>
                <td><span :class="alias.is_active ? 'product360-alias-good' : 'product360-alias-muted'">{{ alias.is_active ? '启用' : '已停用' }}</span></td>
                <td><span :class="alias.is_active && alias.oms_verified_at ? 'product360-alias-good' : 'product360-alias-muted'">{{ alias.is_active && alias.oms_verified_at ? '已核实' : '待核实' }}</span><small v-if="alias.oms_verified_at">{{ date(alias.oms_verified_at) }}</small></td>
                <td>{{ alias.updated_by || '—' }}<small>{{ date(alias.updated_at) }}</small></td>
                <td v-if="canOperate" class="product360-alias-actions">
                  <button type="button" class="mvp-button mvp-button-secondary" @click="toggleAlias(alias)">{{ alias.is_active ? '停用' : '启用' }}</button>
                  <button v-if="alias.is_active && !alias.oms_verified_at" type="button" class="mvp-button mvp-button-secondary" @click="verifyingAliasId = verifyingAliasId === alias.id ? null : alias.id; aliasVerify.reset()">核实</button>
                  <button type="button" class="mvp-button mvp-button-secondary" @click="deleteAlias(alias)">删除</button>
                </td>
              </tr>
              <tr v-if="verifyingAliasId === alias.id && canOperate"><td colspan="6"><form class="product360-alias-verify" @submit.prevent="submitVerify(alias)">
                <strong>确认该别名与主 SKU 完全属于同款、同颜色、同规格、同包装版本</strong>
                <textarea v-model="aliasVerify.physical_evidence" rows="2" maxlength="1000" placeholder="填写现场实物、产品标签、供应商文件等核实证据（至少20字）" />
                <p v-if="aliasVerify.errors.physical_evidence" class="product360-alias-error" role="alert">{{ aliasVerify.errors.physical_evidence }}</p>
                <label><input v-model="aliasVerify.same_identity_confirmed" type="checkbox" /> 已核实实物一致；我理解仍需 OMS 目标仓库库存校验</label>
                <p v-if="aliasVerify.errors.same_identity_confirmed" class="product360-alias-error" role="alert">{{ aliasVerify.errors.same_identity_confirmed }}</p>
                <div class="product360-alias-form-actions"><button type="submit" class="mvp-button mvp-button-primary" :disabled="aliasVerify.processing">保存核实证据</button><button type="button" class="mvp-button mvp-button-secondary" @click="verifyingAliasId = null">取消</button></div>
              </form></td></tr>
            </template></tbody>
          </table>
          <p v-else class="mvp-empty">暂无 SKU 别名。此产品目前只有 ERP 主 SKU。</p>
        </div>
        <form v-if="canOperate" class="product360-alias-create" @submit.prevent="submitAlias">
          <h3>添加 SKU 别名</h3>
          <div class="product360-alias-fields">
            <label>别名 SKU <input v-model.trim="aliasCreate.alias_sku" required maxlength="128" placeholder="输入新别名" /><small v-if="aliasCreate.errors.alias_sku" class="product360-alias-error" role="alert">{{ aliasCreate.errors.alias_sku }}</small></label>
            <label>来源 <select v-model="aliasCreate.source_type"><option value="manual">手动</option><option value="domestic_warehouse">国内仓</option><option value="xlwms">世迈仓</option><option value="overseas_warehouse">海外仓</option><option value="operations">运营</option><option value="supplier">供应商</option></select></label>
            <label>来源引用 <input v-model.trim="aliasCreate.source_reference" maxlength="255" placeholder="如仓库单据编号" /></label>
          </div>
          <label>备注 / 核实依据 <textarea v-model.trim="aliasCreate.notes" rows="2" maxlength="1000" placeholder="说明来源或产品一致性依据" /></label>
          <label class="product360-alias-check"><input v-model="aliasCreate.same_identity_confirmed" type="checkbox" /> 确认是相同型号、颜色、规格与包装的同一个实物产品</label>
          <p v-if="aliasCreate.errors.same_identity_confirmed" class="product360-alias-error" role="alert">{{ aliasCreate.errors.same_identity_confirmed }}</p>
          <div class="product360-alias-form-actions"><span>不同型号、颜色、赠品须使用订单商品替换流程，不得用别名合并。</span><button type="submit" class="mvp-button mvp-button-primary" :disabled="aliasCreate.processing">＋ 添加别名</button></div>
        </form>
        <p v-else class="product360-compat-note">当前账户仅可查看别名；维护需产品操作权限。</p>
      </article>
      </div>
      <article v-else-if="activeTab === '关联与记录' && activeSubTab === '业务关联'" id="product360-7" class="erp-form-card product360-relations" role="tabpanel" tabindex="0"><h3>业务关联</h3><table v-if="sales.recent_orders.length || shipments.length || collaborations.length" class="mvp-table product360-compact-table product360-relations-table"><thead><tr><th v-for="column in relationGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="relationGrid.columns" :required-keys="['order_number']" :sync-state="relationGrid.syncState" @toggle="relationGrid.toggle" @start-drag="relationGrid.startDrag" @drop="relationGrid.drop" @reset="relationGrid.reset" /></tr></thead><tbody><tr v-for="order in sales.recent_orders" :key="'rel-order-' + order.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">订单</template><template v-else-if="column.key === 'order_number'"><Link :href="'/customers/orders/' + order.id" class="mvp-link">{{ display(order.erp_order_id) }}</Link></template><template v-else-if="column.key === 'details'">{{ display(order.order_date) }} · 数量 {{ order.quantity }}</template><template v-else>{{ order.status }}</template></td></tr><tr v-for="shipment in shipments" :key="'rel-shipment-' + shipment.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">发货</template><template v-else-if="column.key === 'order_number'"><Link v-if="shipment.erp_order_id" :href="'/customers/orders/' + shipment.order_id" class="mvp-link">{{ display(shipment.erp_order_id) }}</Link><span v-else>—</span></template><template v-else-if="column.key === 'details'">{{ display(shipment.tracking_number) }} · {{ display(shipment.carrier) }}</template><template v-else>{{ display(shipment.status) }}</template></td></tr><tr v-for="collaboration in collaborations" :key="'rel-kol-' + collaboration.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">KOL</template><template v-else-if="column.key === 'order_number'"><Link :href="'/kol/library/' + collaboration.kol_id" class="mvp-link">{{ display(collaboration.kol_name) }}</Link></template><template v-else-if="column.key === 'details'">{{ display(collaboration.title) }}</template><template v-else>{{ collaboration.status }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无业务关联</p></article>
      <article v-else-if="activeTab === '关联与记录' && activeSubTab === '操作记录'" id="product360-8" class="erp-form-card" role="tabpanel" tabindex="0"><h3>审计</h3><table v-if="audit.length" class="mvp-table"><thead><tr><th>动作</th><th>操作者</th><th>来源</th><th>时间</th></tr></thead><tbody><tr v-for="record in audit" :key="`${record.created_at}-${record.action}`"><td>{{ record.action_label }}</td><td>{{ record.actor_label ?? '—' }}</td><td>{{ auditSource(record.source) }}</td><td>{{ date(record.created_at) }}</td></tr></tbody></table><p v-else class="mvp-empty">暂无产品审计记录</p></article>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.product360-factory-task { margin:18px 0; padding:14px; border:1px solid #dce8dd; border-radius:8px; background:#f9fcf9; display:grid; gap:12px; }
.product360-factory-task h4 { margin:0; color:#20583c; }
.product360-factory-task label { display:flex; align-items:center; gap:8px; font-size:12px; }
.product360-factory-task select { padding:8px; border:1px solid #d1e1d4; border-radius:7px; }
.product360-factory-task input[type=checkbox] { accent-color:#176e50; }
.product360-required-fields { display:flex; gap:9px; flex-wrap:wrap; align-items:center; }
.product360-factory-record { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid #e4ebe5; flex-wrap:wrap; }
.product360-evidence-head{display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap}
.product360-evidence-fields{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.product360-evidence-fields span{padding:4px 7px;background:#eff5f0;border-radius:5px;font-size:11px}
.product360-anomaly{padding:8px;background:#fff0e5;color:#a75a2a;border-radius:6px;font-size:12px}
.product360-factory-record button:disabled{opacity:.4;cursor:not-allowed}
.product360-factory-record button { border:1px solid #aacbb8; border-radius:6px; padding:5px 10px; background:#fff; color:#20694a; }

.product360-subtabs{display:flex;gap:6px;flex-wrap:wrap;margin:-4px 0 14px;}
.product360-subtabs button{border:1px solid #d8e6dc;border-radius:7px;background:#fff;padding:8px 11px;color:#527263;font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.product360-subtabs button.active{border-color:#96c8ac;background:#eaf6ee;color:#156d4e}
.product360-subtabs button:focus-visible{outline:2px solid #176e50;outline-offset:2px}
.product360-channel-panels{display:grid;gap:14px}
.product360-v2-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.product360-meta-note{font-size:12px;color:#6b8073;line-height:1.65}
.product360-parameters-grid{margin:14px 0}
.product360-inline-head{display:flex;align-items:center;justify-content:space-between;gap:16px}
.product360-inline-head h3{margin:0}
.product360-inline-actions{display:flex;gap:8px;justify-content:flex-end}
.product360-inline-editor{margin-top:14px}
.product360-inline-editor h4{font-size:13px;font-weight:700;margin:18px 0 10px;color:#30473c}
.product360-inline-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.product360-inline-field{display:flex;flex-direction:column;gap:5px;font-size:13px;color:#334155}
.product360-inline-field>span{font-weight:600}
.product360-inline-field small{color:#718096;font-weight:400}
.product360-inline-field input{width:100%;min-width:0;height:39px;background:#fff;border:1px solid #cad8d0;border-radius:7px;padding:7px 11px;font-size:13px}
.product360-inline-field input:focus{outline:2px solid #cce9da;outline-offset:1px;border-color:#408c68}
.product360-inline-field input[aria-invalid=true]{border-color:#b42318}
.product360-inline-error{color:#b42318!important;font-size:12px}
.product360-inline-success{background:#eef9f0;border-radius:6px;padding:9px 12px;color:#235b40;font-size:12px;margin-top:12px}
@media(max-width:1000px){.product360-inline-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.product360-inline-fields{grid-template-columns:1fr}}
@media(max-width:640px){.product360-tabs{overflow-x:auto;flex-wrap:nowrap}.product360-tabs button{white-space:nowrap;flex:0 0 auto}.product360-subtabs{flex-wrap:nowrap;overflow-x:auto}.product360-subtabs button{white-space:nowrap;flex:0 0 auto}}
.product360-page { max-width: 1440px; }
.product360-hero { display: flex; gap: 20px; align-items: center; margin-bottom: 12px; padding: 18px 20px; }
.product360-image { width: 104px; height: 104px; flex: 0 0 104px; border-radius: 10px; background: #eef1f4; color: #78838e; display: grid; place-items: center; font-size: 13px; }
.product360-image img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; }
.product360-thumbnails { display: flex; gap: 4px; margin-top: 6px; max-width: 104px; overflow: auto; }
.product360-thumbnails img { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 4px; object-fit: cover; }
.product360-woo-overview { margin-bottom: 0 !important; }
.product360-woo-description { white-space: pre-wrap; line-height: 1.6; }
.product360-sync-history { margin-top: 18px; border-top: 1px solid #e3e7ea; padding-top: 12px; }
.product360-sync-history h4 { margin: 0 0 8px; }
.product360-sync-history p { margin: 4px 0; color: #59636e; font-size: 13px; }
.product360-identity { min-width: 0; flex: 1 1 auto; }
.product360-hero-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex: 0 0 auto; }
.product360-identity h1 { margin: 2px 0 4px; font-size: 22px; }
.product360-sku { color: #59636e; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.product360-meta, .product360-external-summary { display: flex; flex-wrap: wrap; gap: 8px 18px; color: #59636e; font-size: 13px; }
.product360-external-summary { margin-top: 6px; color: #52646c; font-size: 12px; }
.product360-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #d9dee3; margin-bottom: 14px; }
.product360-tabs button { border: 0; border-bottom: 2px solid transparent; background: transparent; padding: 9px 12px; color: #59636e; font: inherit; font-size: 13px; cursor: pointer; }
.product360-tabs button:hover, .product360-tabs button:focus-visible { color: #1f5f8b; background: #f4f7f9; outline: none; }
.product360-tabs button.active { color: #1f5f8b; border-bottom-color: currentColor; font-weight: 600; }
.product360-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.product360-overview { display: grid; gap: 14px; }
.product360-overview-top, .product360-overview-bottom { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.product360-summary-card { margin-bottom: 0 !important; }
.product360-summary-head { display: flex; align-items: center; gap: 9px; margin-bottom: 14px; }
.product360-summary-head h3 { margin: 0; }
.product360-summary-icon { width: 30px; height: 30px; border-radius: 999px; display: grid; place-items: center; background: #e5f6ef; color: #187b62; font-size: 12px; font-weight: 700; }
.product360-summary-kol .product360-summary-icon { background: #f1eafa; color: #7548a8; }
.product360-summary-icon-logistics { background: #eaf3fc; color: #2b69a1; }
.product360-summary-icon-stock { background: #fff2e6; color: #a85d20; }
.product360-summary-help { color: #8a96a1; font-size: 12px; cursor: help; }
.product360-summary-sales .product360-kpis div { background: #f1faf7; }
.product360-summary-kol .product360-kpis div { background: #f8f5fc; }
.product360-comparison { display: grid; grid-template-columns: 1.25fr repeat(3, 1fr); align-items: center; gap: 12px; padding: 12px 18px; border: 1px solid #d5e6f6; border-radius: 10px; background: #f2f8fe; font-size: 13px; }
.product360-comparison span { padding-left: 16px; border-left: 1px solid #d5e2ee; text-align: center; }
.product360-comparison b { margin-left: 5px; font-size: 16px; color: #205f91; }
.product360-summary-kol + * { min-width: 0; }
.product360-stock-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 18px; }
.product360-stock-summary span { min-width: 0; }
.product360-page article { margin-bottom: 16px; }
.product360-page h3 { margin: 0 0 14px; font-size: 17px; }
.product360-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.product360-kpis div { padding: 12px; background: #f7f8f9; border-radius: 8px; }
.product360-kpis small, .product360-kpis span { display: block; color: #68737e; font-size: 12px; }
.product360-kpis strong { display: block; margin: 4px 0; font-size: 24px; }
.product360-kpis .product360-unit { display: inline; margin-left: 4px; color: #68737e; font-size: 12px; font-weight: 600; }
.product360-panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.product360-price-line { display: flex; gap: 16px; color: #68737e; font-size: 12px; }
.product360-kpis-sales { margin-bottom: 12px; }
.product360-panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.product360-price-line { display: flex; gap: 16px; color: #68737e; font-size: 12px; }
.product360-kpis-sales { margin-bottom: 12px; }
.product360-woo-groups { display: grid; gap: 10px; }
.product360-woo-group { border-top: 1px solid #e3e7ea; padding-top: 10px; }
.product360-woo-group:first-child { border-top: 0; padding-top: 0; }
.product360-woo-group-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 6px; }
.product360-woo-group-head span { color: #68737e; font-size: 12px; }
.product360-woo-group-refs { display: flex; flex-wrap: wrap; gap: 6px; }
.product360-woo-group-refs span { padding: 5px 8px; background: #f1f5f7; border-radius: 6px; font-size: 12px; }
.product360-inline-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.product360-inline-list span { padding: 7px 10px; background: #f1f5f7; border-radius: 6px; }
.product360-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 20px; }
.product360-detail-grid p { margin: 0 0 10px; }
.product360-evidence { margin-left: 6px; color: #78838e; font-weight: 400; }
.product360-section-label { font-weight: 600; margin: 14px 0 7px; }
.product360-relations { padding-top: 18px; padding-bottom: 18px; }
.product360-compact-table { margin-bottom: 4px; }
.product360-alias-panel { padding: 16px 20px; }
.product360-alias-canonical { display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px; }
.product360-alias-canonical span { color: #68737e; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.product360-alias-canonical strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; }
.product360-alias-table th, .product360-alias-table td { padding-top: 8px; padding-bottom: 8px; }
.product360-alias-table td:first-child { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.product360-compat-note { margin-top: 12px; color: #68737e; font-size: 12px; }
@media (max-width: 900px) { .product360-hero { align-items: flex-start; flex-wrap: wrap; } .product360-hero-actions { width: 100%; justify-content: flex-start; margin-left: 124px; } }
@media (max-width: 760px) { .product360-grid, .product360-detail-grid, .product360-overview-top, .product360-overview-bottom { grid-template-columns: 1fr; } .product360-kpis, .product360-stock-summary { grid-template-columns: 1fr; } .product360-comparison { grid-template-columns: 1fr; } .product360-comparison span { border-left: 0; padding-left: 0; text-align: left; } .product360-hero-actions { margin-left: 0; } }
.product360-alias-panel { padding: 16px 20px; }
.product360-alias-canonical { display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px; }
.product360-alias-canonical span { color: #68737e; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.product360-alias-canonical strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; }
.product360-alias-table th, .product360-alias-table td { padding-top: 8px; padding-bottom: 8px; }
.product360-alias-table td:first-child { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.product360-alias-hero { display: flex; flex-direction: column; gap: 5px; background: #f4f7f5; border: 1px solid #dde7df; padding: 14px 18px; border-radius: 8px; margin: 10px 0 22px; }
.product360-alias-hero span, .product360-alias-hero small { color: #66736a; font-size: 12px; }
.product360-alias-hero strong { font-family: ui-monospace, Menlo, monospace; font-size: 19px; }
.product360-alias-bar { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.product360-alias-bar span { font-size: 12px; color: #69766e; }
.product360-alias-overflow { overflow-x: auto; }
.product360-alias-table { width: 100%; min-width: 820px; }
.product360-alias-table small { display: block; font-size: 11px; margin-top: 4px; color: #66736a; overflow-wrap: anywhere; }
.product360-alias-table td { vertical-align: middle; }
.product360-alias-actions { white-space: nowrap; }
.product360-alias-actions button { margin: 2px 3px 2px 0; font-size: 12px; }
.product360-alias-good { color: #166534; font-weight: 600; }
.product360-alias-muted { color: #9a6700; font-weight: 600; }
.product360-alias-error { color: #b42318 !important; font-size: 12px; margin: 5px 0; }
.product360-alias-create, .product360-alias-verify { border-top: 1px solid #e2e8e2; margin-top: 18px; padding-top: 17px; display: flex; flex-direction: column; gap: 10px; }
.product360-alias-fields { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.product360-alias-create label, .product360-alias-verify label { font-size: 13px; display: flex; flex-direction: column; gap: 6px; }
.product360-alias-create input:not([type=checkbox]), .product360-alias-create select, .product360-alias-create textarea, .product360-alias-verify textarea { width: 100%; border: 1px solid #cbd5ce; border-radius: 7px; padding: 8px 10px; font-size: 13px; color: #141b16; background: #fff; }
.product360-alias-create label:has(input[type=checkbox]), .product360-alias-verify label:has(input[type=checkbox]) { flex-direction: row; align-items: center; }
.product360-alias-form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; }
.product360-alias-form-actions span { font-size: 12px; color: #7b6140; margin-right: auto; }
</style>
