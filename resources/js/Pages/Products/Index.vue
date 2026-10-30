<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type ColumnKey =
  | 'status' | 'sku' | 'cnf' | 'material_name' | 'name' | 'image_url' | 'woo_url'
  | 'units_per_carton' | 'packaging_dimensions' | 'weight_kg' | 'regular_price'
  | 'sale_price' | 'brand' | 'available_stock' | 'gtin' | 'label_description'
  | 'dimensions_cm' | 'shipping_weight_kg' | 'standard_production_cost'
  | 'standard_packaging_cost' | 'cost_currency' | 'unit' | 'source'
  | 'parameter_status' | 'parameter_review_status' | 'parameter_assigned_user_id' | 'material' | 'net_weight_kg' | 'color_box_gross_kg'
  | 'courier_gross_kg' | 'package_dimensions' | 'package_volume_l' | 'carton_dimensions'
  | 'carton_gross_weight_kg' | 'accessories_count' | 'accessories_summary'
type Product = {
  id: number
  sku: string | null
  name: string
  is_active: boolean
  image_url: string | null
  woo_url: string | null
  [key: string]: string | number | boolean | null
}
type PaginatedProducts = {
  data: Product[]
  current_page: number
  last_page: number
  total: number
  per_page: number
  from: number | null
  to: number | null
}
type Filters = { search?: string; per_page?: string; sort?: string; direction?: string; view?: string; parameter_status?: string; attention?: string }

const props = defineProps<{
  products: PaginatedProducts
  filters: Filters
  canOperate: boolean
  parameterCounts?: Record<string,number>
  parameterFactoryUsers?: {id:number;name:string}[]
}>()
const search = ref(props.filters.search ?? '')
const perPage = ref(props.filters.per_page ?? '50')
const confirmDeleteId = ref<number | null>(null)
const failedImageIds = ref<number[]>([])
const markImageFailed = (id: number) => { if (!failedImageIds.value.includes(id)) failedImageIds.value.push(id) }
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'status', label: '状态' },
  { key: 'sku', label: 'SKU' },
  { key: 'cnf', label: 'CNF（待接入）', defaultVisible: false },
  { key: 'material_name', label: '物料名称（待接入）', defaultVisible: false },
  { key: 'name', label: '产品名称' },
  { key: 'image_url', label: '图片' },
  { key: 'woo_url', label: '官网链接' },
  { key: 'units_per_carton', label: '标准装箱数' },
  { key: 'packaging_dimensions', label: '包装尺寸（待接入）', defaultVisible: false },
  { key: 'weight_kg', label: '商品重量 kg' },
  { key: 'regular_price', label: '原价' },
  { key: 'sale_price', label: '售价' },
  { key: 'brand', label: '品牌', defaultVisible: false },
  { key: 'available_stock', label: '可用库存' },
  { key: 'gtin', label: 'GTIN' },
  { key: 'label_description', label: '蓝标描述' },
  { key: 'dimensions_cm', label: '商品尺寸 cm' },
  { key: 'shipping_weight_kg', label: '运重 (kg)' },
  { key: 'standard_production_cost', label: '标准生产成本' },
  { key: 'standard_packaging_cost', label: '标准包材成本' },
  { key: 'cost_currency', label: '成本币种' },
  { key: 'unit', label: '单位' },
  { key: 'source', label: '来源' },
  { key: 'parameter_status', label: '资料状态', defaultVisible: false },
  { key: 'parameter_review_status', label: '审核情况', defaultVisible: false },
  { key: 'parameter_assigned_user_id', label: '工厂负责人', defaultVisible: false },
  { key: 'material', label: '产品材质', defaultVisible: false },
  { key: 'net_weight_kg', label: '工厂净重 kg', defaultVisible: false },
  { key: 'color_box_gross_kg', label: '彩盒毛重 kg', defaultVisible: false },
  { key: 'courier_gross_kg', label: '快递毛重 kg', defaultVisible: false },
  { key: 'package_dimensions', label: '单件包装尺寸', defaultVisible: false },
  { key: 'package_volume_l', label: '包装体积 L', defaultVisible: false },
  { key: 'carton_dimensions', label: '外箱尺寸', defaultVisible: false },
  { key: 'carton_gross_weight_kg', label: '整箱毛重 kg', defaultVisible: false },
  { key: 'accessories_count', label: '配件种类', defaultVisible: false },
  { key: 'accessories_summary', label: '包装内配件', defaultVisible: false },
]
const { columns, visibleColumns: persistedVisibleColumns, toggle, startDrag, drop, reset, syncState } =
  useAccountGridPreferences<ColumnKey>({
    namespace: 'grid.products.index.v1',
    definitions,
    requiredKeys: ['sku', 'name'],
  })
const currentView = computed<string>(() => ['standard','measurements','accessories'].includes(props.filters.view ?? '')
  ? props.filters.view! : 'standard')
const viewDefs: Record<string, ColumnKey[]> = {
  measurements: ['sku','name','parameter_status','parameter_review_status','parameter_assigned_user_id','material','net_weight_kg','color_box_gross_kg','courier_gross_kg','package_dimensions','package_volume_l','carton_dimensions','carton_gross_weight_kg'],
  accessories: ['sku','name','parameter_status','accessories_count','accessories_summary'],
}
const visibleColumns = computed(() => currentView.value === 'standard'
  ? persistedVisibleColumns.value
  : (viewDefs[currentView.value] ?? []).map((key: ColumnKey) => definitions.find(d => d.key === key)!).filter(Boolean))
const selectedProductIds = ref<number[]>([])
const bulkAction = ref<'required'|'optional'|'disable'>('required')
const bulkAssignee = ref<number|null>(null)
const bulkRequiredFields = ref<string[]>(['material','net_weight_kg','package_length_cm','package_width_cm','package_height_cm'])
const bulkAllowReassign = ref(false)
const bulkBusy = ref(false)
const bulkError = ref('')
const bulkRequiredChoices = [
  {key:'material',label:'产品材质'}, {key:'weight_composition',label:'重量组成'},
  {key:'net_weight_kg',label:'产品净重'}, {key:'color_box_gross_kg',label:'彩盒毛重'},
  {key:'courier_gross_kg',label:'快递毛重'}, {key:'package_length_cm',label:'包装长'},
  {key:'package_width_cm',label:'包装宽'}, {key:'package_height_cm',label:'包装高'},
  {key:'units_per_carton',label:'每箱数量'}, {key:'carton_gross_weight_kg',label:'整箱毛重'}
]
const visibleBatchIds = computed(() => props.products.data.map(p=>p.id).slice(0,100))
const isAllCurrentPageSelected = computed(() =>
  visibleBatchIds.value.length > 0 && visibleBatchIds.value.every(id=>selectedProductIds.value.includes(id)))
watch(() => props.products.data.map(p=>p.id).join(',')+'|'+props.filters.search+'|'+props.filters.parameter_status, () => {
  selectedProductIds.value = []
  bulkError.value = ''
})
const togglePageSelection = (checked:boolean) => {
  selectedProductIds.value = checked ? [...visibleBatchIds.value] : []
}
const toggleSelection = (id:number, checked:boolean) => {
  selectedProductIds.value = checked
    ? [...new Set([...selectedProductIds.value, id])].slice(0,100)
    : selectedProductIds.value.filter(x=>x!==id)
}
const applyBatch = () => {
  if (!props.canOperate || currentView.value!=='measurements' || !selectedProductIds.value.length || bulkBusy.value) return
  bulkError.value = ''
  if (bulkAction.value!=='disable' && !bulkAssignee.value) {
    bulkError.value = '请先选择工厂填报员工'
    return
  }
  if (bulkAction.value==='required' && bulkRequiredFields.value.length===0) {
    bulkError.value = '必填任务至少勾选一项必填字段'
    return
  }
  const actionName = bulkAction.value==='required' ? '设为必填' : bulkAction.value==='optional' ? '设为待填' : '暂停填写'
  if (!window.confirm('确认对当前勾选的 '+selectedProductIds.value.length+' 个产品执行「'+actionName+'」？不会影响其他页、产品主数据或历史审核记录。')) return
  bulkBusy.value = true
  router.post('/products/parameter-tasks/bulk', {
    product_ids: selectedProductIds.value,
    action: bulkAction.value,
    assigned_user_id: bulkAction.value==='disable'?null:bulkAssignee.value,
    required_fields: bulkAction.value==='required'?bulkRequiredFields.value:[],
    allow_reassign: bulkAllowReassign.value,
  }, {
    preserveScroll:true,
    onError:(errors:Record<string,string>)=>{bulkError.value=Object.values(errors).join('；') || '保存失败，请检查任务设置'},
    onSuccess:()=>{selectedProductIds.value=[];bulkAllowReassign.value=false},
    onFinish:()=>{bulkBusy.value=false},
  })
}
const attentionLabels: Record<string, string> = {
  needs_data: '资料需补齐', missing_image: '未关联 Woo 主图', missing_brand: '缺少品牌',
  missing_barcode_69: '缺少 69 条码', missing_shipping_weight: '缺少运重',
  missing_dimensions: '缺少商品尺寸', pending_review: '工厂资料待审核',
}
const activeAttention = computed(() => attentionLabels[props.filters.attention ?? ''] || '')
const activeStatus = computed(() => props.filters.parameter_status || '全部')
const statuses = computed(() => currentView.value === 'measurements'
  ? ['全部','已填','待填','必填','其他']
  : currentView.value === 'accessories' ? ['全部','有配件','待补充'] : [])
const changeView=(name:string)=>go({view:name,parameter_status:'',page:1})
const changeStatus=(name:string)=>go({parameter_status:name==='全部'?'':name,page:1})
const sortable = new Set<ColumnKey>(['sku', 'name', 'regular_price', 'sale_price', 'shipping_weight_kg'])
const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '产品列表']

const query = (extra: Record<string, string | number> = {}) => ({
  search: props.filters.search ?? '',
  per_page: perPage.value,
  sort: props.filters.sort ?? 'name',
  direction: props.filters.direction ?? 'asc',
  view: currentView.value ?? 'standard',
  parameter_status: props.filters.parameter_status ?? '',
  attention: props.filters.attention ?? '',
  ...extra,
})
const go = (extra: Record<string, string | number>) => {
  router.get('/products/list', query(extra), { preserveState: true, replace: true, preserveScroll: true })
}
const applySearch = () => go({ search: search.value.trim(), page: 1 })
const changePerPage = () => go({ per_page: perPage.value, page: 1 })
const goToPage = (page: number) => go({ page })
const paginationItems = computed<(number | string)[]>(() => {
  const last = Math.max(1, props.products.last_page)
  const current = Math.min(last, Math.max(1, props.products.current_page))
  const pages = Array.from(new Set([1, last, current - 2, current - 1, current, current + 1, current + 2]))
    .filter(page => page >= 1 && page <= last).sort((a, b) => a - b)
  const items: (number | string)[] = []
  for (let i = 0; i < pages.length; i++) {
    if (i > 0 && pages[i] - pages[i - 1] > 1) items.push('…')
    items.push(pages[i])
  }
  return items
})
const sortBy = (key: ColumnKey) => {
  if (!sortable.has(key)) return
  const direction = props.filters.sort === key && props.filters.direction !== 'desc' ? 'desc' : 'asc'
  go({ sort: key, direction, page: 1 })
}
const exportUrl = computed(() => {
  const params = new URLSearchParams({
    search: props.filters.search ?? '',
    sort: props.filters.sort ?? 'name',
    direction: props.filters.direction ?? 'asc',
    columns: visibleColumns.value.map(c => c.key).join(','),
  })
  return '/products/list/export?' + params.toString()
})
const fmt = (value: unknown) => value === null || value === undefined || value === '' ? '—' : String(value)
const cellValue = (p: Product, key: ColumnKey) => key === 'source' && p.source == null ? (p.source_type === 'manual' ? '手动' : 'Woo') : fmt(p[key])
const doDelete = () => {
  if (confirmDeleteId.value === null) return
  router.delete('/products/' + confirmDeleteId.value, { onFinish: () => { confirmDeleteId.value = null } })
}
</script>

<template>
  <Head title="产品列表 · BYL ERP" />
  <AuthenticatedLayout title="产品列表" :breadcrumbs="breadcrumbs">
    <section class="mvp-page product-grid-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">产品主数据 · PRODUCT OS</p>
          <h2>产品列表</h2>
          <p>共 {{ products.total }} 件产品 · 数据来自 ERP 产品主表</p>
        </div>
        <div class="product-header-controls">
          <form class="product-search" role="search" @submit.prevent="applySearch">
            <svg class="product-search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <circle cx="10.8" cy="10.8" r="6.8" />
              <path d="m16 16 5 5" />
            </svg>
            <input v-model="search" aria-label="搜索 SKU 或产品名称" type="search" placeholder="搜索 SKU / 名称 / GTIN" class="product-search-input">
            <button class="product-search-submit" type="submit">搜索</button>
          </form>
          <div class="mvp-page-actions page-header-actions">
            <a v-if="currentView==='standard'" class="mvp-button mvp-button-secondary" :href="exportUrl" download>下载 XLSX</a>
            <template v-if="canOperate">
              <a href="/products/import" class="mvp-button mvp-button-secondary">导入 CSV</a>
              <a href="/products/create" class="mvp-button mvp-button-primary">新增产品</a>
            </template>
          </div>
        </div>
      </div>

      <div class="product-grid-view-selector">
        <nav aria-label="产品列表视图" class="product-grid-view-tabs">
          <button v-for="choice in [{key:'standard',label:'标准产品列表'},{key:'measurements',label:'重量与尺寸'},{key:'accessories',label:'包装内配件'}]"
            :key="choice.key" type="button" :class="{active:currentView===choice.key}"
            @click="changeView(choice.key)">{{choice.label}}</button>
        </nav>
        <nav v-if="statuses.length" aria-label="产品资料填报状态" class="product-grid-status-tabs">
          <button v-for="status in statuses" :key="status" type="button" :class="{active:activeStatus===status}"
            @click="changeStatus(status)">{{status}}
            <small v-if="currentView==='measurements'">{{parameterCounts?.[status]??0}}</small>
          </button>
        </nav>
      </div>

      <div v-if="activeAttention" class="product-grid-active-attention" role="status">
        <span>筛选条件：{{ activeAttention }} · 共 {{ products.total }} 件产品</span>
        <button type="button" @click="go({attention:'', page:1})">清除筛选</button>
      </div>

      <section v-if="canOperate && currentView==='measurements'" class="parameter-bulk-toolbar" aria-label="产品参数批量指定">
        <div class="parameter-bulk-title">
          <strong>批量指定工厂填写</strong>
          <span>本页选中 {{selectedProductIds.length}} 件 · 最多100件/次 · 仅当前勾选产品生效</span>
        </div>
        <div class="parameter-bulk-controls">
          <label>处理方式
            <select v-model="bulkAction" aria-label="批量任务处理方式">
              <option value="required">设为必填</option>
              <option value="optional">设为待填（非必填）</option>
              <option value="disable">暂停填写任务</option>
            </select>
          </label>
          <label v-if="bulkAction!=='disable'">指派工厂员工
            <select v-model.number="bulkAssignee" aria-label="批量工厂员工">
              <option :value="null">请选择员工</option>
              <option v-for="person in parameterFactoryUsers??[]" :key="person.id" :value="person.id">{{person.name}} · #{{person.id}}</option>
            </select>
          </label>
          <button type="button" class="parameter-bulk-submit" :disabled="!selectedProductIds.length||bulkBusy" @click="applyBatch">应用于选中的 {{selectedProductIds.length}} 件</button>
        </div>
        <details v-if="bulkAction==='required'" class="parameter-bulk-details">
          <summary>必填字段：已选择 {{bulkRequiredFields.length}} 项（点击展开修改）</summary>
          <div class="parameter-bulk-fields" aria-label="批量必填字段">
            <label v-for="item in bulkRequiredChoices" :key="item.key">
              <input v-model="bulkRequiredFields" type="checkbox" :value="item.key" /> {{item.label}}
            </label>
          </div>
        </details>
        <label v-if="bulkAction!=='disable'" class="parameter-bulk-confirm">
          <input v-model="bulkAllowReassign" type="checkbox" /> 允许重新指派已有任务（需要明确确认；待审核任务不能转交）
        </label>
        <p v-if="bulkAction==='disable'" class="parameter-bulk-note">暂停仅禁用新的工厂填报入口；不会删除既有草稿、提交、照片和已审核配件。待审核任务不得暂停。</p>
        <p v-if="bulkError" class="parameter-bulk-error" role="alert">{{bulkError}}</p>
      </section>

      <div v-if="confirmDeleteId !== null" class="mvp-confirm-overlay">
        <div class="mvp-confirm-dialog">
          <p>确认删除此产品？若该产品为 Woo 导入，下次导入相同 Woo ID 的 CSV 时将重新创建。</p>
          <div class="mvp-confirm-actions">
            <button class="mvp-button mvp-button-danger" type="button" @click="doDelete">确认删除</button>
            <button class="mvp-button mvp-button-secondary" type="button" @click="confirmDeleteId = null">取消</button>
          </div>
        </div>
      </div>

      <div class="grid-topbar">
        <span class="grid-hint">点击表头排序 · 右上角 … 调整显示列</span>
        <nav class="grid-pages" aria-label="产品列表顶部分页">
          <button type="button" class="grid-page-nav" :disabled="products.current_page <= 1" @click="goToPage(products.current_page - 1)">上一页</button>
          <template v-for="(item, i) in paginationItems" :key="'top-' + i">
            <span v-if="typeof item === 'string'" class="grid-ellipsis">…</span>
            <button v-else type="button" class="grid-page-number" :class="{ active: item === products.current_page }" :aria-current="item === products.current_page ? 'page' : undefined" @click="goToPage(item)">{{ item }}</button>
          </template>
          <button type="button" class="grid-page-nav" :disabled="products.current_page >= products.last_page" @click="goToPage(products.current_page + 1)">下一页</button>
        </nav>
      </div>

      <div class="product-grid-scroller" role="region" aria-label="真实产品数据表" tabindex="0">
        <table class="product-data-table">
          <thead>
            <tr>
              <th v-if="canOperate && currentView==='measurements'" scope="col" class="parameter-select-cell">
                <input type="checkbox" aria-label="选择本页产品（最多100件）" :checked="isAllCurrentPageSelected"
                  :disabled="!visibleBatchIds.length" @change="togglePageSelection(($event.target as HTMLInputElement).checked)" />
              </th>
              <th v-for="col in visibleColumns" :key="col.key" scope="col">
                <button v-if="sortable.has(col.key)" type="button" class="head-sort" @click="sortBy(col.key)">
                  {{ col.label }}<span class="sort-mark" aria-hidden="true">{{ filters.sort === col.key ? (filters.direction === 'desc' ? '▼' : '▲') : '↕' }}</span>
                </button>
                <span v-else>{{ col.label }}</span>
              </th>
              <th scope="col">操作</th>
              <ColumnSettingsHeader :columns="columns" :required-keys="['sku','name']" :sync-state="syncState"
                @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td v-if="canOperate && currentView==='measurements'" class="parameter-select-cell">
                <input type="checkbox" :aria-label="'选择 '+p.sku+' 工厂填报任务'" :checked="selectedProductIds.includes(p.id)"
                  @change="toggleSelection(p.id,($event.target as HTMLInputElement).checked)" />
              </td>
              <td v-for="col in visibleColumns" :key="col.key" :class="{ 'col-name': col.key === 'name', 'col-picture': col.key === 'image_url' }">
                <template v-if="col.key === 'status'"><span class="product-status" :class="{ inactive: !p.is_active }">{{ p.is_active ? '启用' : '停用' }}</span></template>
                <template v-else-if="col.key === 'sku'"><a :href="'/products/' + p.id" class="product-grid-link">{{ fmt(p.sku) }}</a></template>
                <template v-else-if="col.key === 'name'"><a :href="'/products/' + p.id" class="product-grid-link">{{ p.name }}</a></template>
                <template v-else-if="col.key === 'image_url'"><img v-if="p.image_url && !failedImageIds.includes(p.id)" :src="p.image_url" :alt="p.name" class="product-thumbnail" loading="lazy" referrerpolicy="no-referrer" @error="markImageFailed(p.id)"><span v-else class="product-image-empty">—</span></template>
                <template v-else-if="col.key === 'woo_url'"><a v-if="p.woo_url" :href="p.woo_url" target="_blank" rel="noopener noreferrer" class="product-grid-link external-url">{{ p.woo_url }}</a><span v-else>—</span></template>
                <template v-else-if="col.key === 'parameter_status'"><span class="product-status">{{fmt(p.parameter_status)}}</span></template>
                <template v-else-if="col.key === 'parameter_assigned_user_id'">
                  {{ parameterFactoryUsers?.find(u=>u.id===p.parameter_assigned_user_id)?.name ?? (p.parameter_assigned_user_id ? '#'+p.parameter_assigned_user_id : '—') }}
                </template>
                <template v-else>{{ cellValue(p, col.key) }}</template>
              </td>
              <td class="grid-actions">
                <a :href="'/products/' + p.id">查看</a>
                <template v-if="canOperate">
                  <a :href="'/products/' + p.id + '/edit'">编辑</a>
                  <button class="danger-link" type="button" @click="confirmDeleteId = p.id">删除</button>
                </template>
              </td>
              <td class="end-cap"></td>
            </tr>
            <tr v-if="products.data.length === 0"><td :colspan="visibleColumns.length + 2 + (canOperate&&currentView==='measurements'?1:0)" class="mvp-empty">暂无产品</td></tr>
          </tbody>
        </table>
      </div>
      <div class="grid-footer">
        <div class="grid-result-count">显示 {{ products.from ?? 0 }}–{{ products.to ?? 0 }} / {{ products.total }} 条</div>
        <nav class="grid-pages" aria-label="产品列表底部分页">
          <button type="button" :disabled="products.current_page <= 1" @click="goToPage(products.current_page - 1)">上一页</button>
          <template v-for="(item, i) in paginationItems" :key="'bottom-' + i">
            <span v-if="typeof item === 'string'" class="grid-ellipsis">…</span>
            <button v-else type="button" class="grid-page-number" :class="{ active: item === products.current_page }" :aria-current="item === products.current_page ? 'page' : undefined" @click="goToPage(item)">{{ item }}</button>
          </template>
          <button type="button" :disabled="products.current_page >= products.last_page" @click="goToPage(products.current_page + 1)">下一页</button>
        </nav>
        <label class="grid-page-size">每页显示
          <select v-model="perPage" aria-label="每页显示条数" @change="changePerPage">
            <option value="20">20</option><option value="50">50</option>
            <option value="100">100</option><option value="all">全部</option>
          </select> 条
        </label>
      </div>
      <p class="grid-data-note">CNF、物料名称、包装尺寸、品牌尚未建立可靠的数据映射，可在列设置中开启查看；未知库存保持空白，不按 0 计算。包装箱数仅引用已保存的包装方案。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.product-grid-active-attention{display:flex;align-items:center;gap:14px;justify-content:space-between;margin:9px 0 12px;padding:10px 14px;border-radius:8px;background:#fff7f9;border:1px solid #ead8de;color:#78364e;font-size:12px}
.product-grid-active-attention button{background:transparent;border:0;color:#81334e;font-size:12px;cursor:pointer;font-weight:650}
.parameter-bulk-toolbar{display:grid;gap:11px;padding:14px;border:1px solid #cddfd2;border-radius:9px;background:#f8fbf8;margin:12px 0}
.parameter-bulk-title{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.parameter-bulk-title strong{font-size:13px;color:#205d43}
.parameter-bulk-title span,.parameter-bulk-note{font-size:11px;color:#708678}
.parameter-bulk-controls{display:flex;gap:9px;align-items:end;flex-wrap:wrap}
.parameter-bulk-controls label{display:grid;gap:5px;min-width:145px;font-size:11px;color:#587060}
.parameter-bulk-controls select{border:1px solid #cad9cd;border-radius:6px;padding:8px;background:#fff;color:#284e37;font-size:12px}
.parameter-bulk-submit{border:0;background:#176b4e;color:#fff;padding:9px 13px;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.parameter-bulk-submit:disabled{opacity:.42;cursor:not-allowed}
.parameter-bulk-details{font-size:11px;color:#50715b}.parameter-bulk-details summary{cursor:pointer;font-weight:650}.parameter-bulk-details .parameter-bulk-fields{margin-top:9px}
.parameter-bulk-fields{display:flex;gap:6px 12px;align-items:center;flex-wrap:wrap;font-size:11px}
.parameter-bulk-fields label,.parameter-bulk-confirm{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#496d55}
.parameter-bulk-fields input,.parameter-bulk-confirm input,.parameter-select-cell input{width:16px;height:16px;min-width:16px;min-height:16px;margin:0;padding:0;accent-color:#176b4e}
.parameter-select-cell{text-align:center;min-width:38px;width:38px}
.parameter-bulk-error{font-size:11px;color:#aa3a2d;background:#fff0ec;padding:8px;border-radius:5px}
@media(max-width:620px){.parameter-bulk-controls label{flex:1;min-width:125px}.parameter-bulk-submit{width:100%}}

.product-grid-view-selector{display:grid;gap:9px;margin:11px 0 12px}
.product-grid-view-tabs,.product-grid-status-tabs{display:flex;gap:6px;flex-wrap:wrap}
.product-grid-view-tabs button,.product-grid-status-tabs button{border:1px solid #d9e6dd;background:#fff;border-radius:7px;color:#597c64;padding:8px 11px;font-size:12px;font-weight:600;cursor:pointer}
.product-grid-view-tabs button.active,.product-grid-status-tabs button.active{background:#176b4f;color:#fff;border-color:#176b4f}
.product-grid-status-tabs small{margin-left:6px;font-size:10px;opacity:.8}
@media(max-width:640px){.product-grid-view-tabs,.product-grid-status-tabs{flex-wrap:nowrap;overflow-x:auto;padding-bottom:3px}.product-grid-view-tabs button,.product-grid-status-tabs button{white-space:nowrap;flex-shrink:0}}

.product-grid-page{--grid-green:#166b57;--grid-border:#d6dedb;font-family:PingFang SC,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#172725}
.product-grid-page .mvp-page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.product-header-controls{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-left:auto}
.product-header-controls .page-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.product-search{display:flex;align-items:center;gap:6px;width:clamp(225px,22vw,330px);height:39px;background:#fff;border:1px solid #c4d9d1;border-radius:9px;padding:3px 4px 3px 12px;box-shadow:0 1px 3px rgba(21,58,45,.08);transition:border-color .15s,box-shadow .15s}
.product-search:focus-within{border-color:#167c63;box-shadow:0 0 0 3px rgba(22,124,99,.12)}
.product-search-icon{width:17px;height:17px;flex:0 0 17px;color:#6e867d}
.product-search-input{background:transparent!important;border:0!important;box-shadow:none!important;outline:0!important;padding:0 2px!important;min-width:0;flex:1;width:100%;font:inherit;font-size:13px;color:#172725}
.product-search-input:focus{outline:0!important;box-shadow:none!important}
.product-search-input::placeholder{color:#879a93}
.product-search-submit{height:31px;flex:0 0 auto;padding:0 15px;background:#166b57;color:white;border:0;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}
.product-search-submit:hover{background:#0d5947}
.grid-topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 9px;min-height:34px}
.grid-hint,.grid-data-note{font-size:12px;color:#657977}
.grid-data-note{margin-top:9px;line-height:1.65}
.product-grid-scroller{overflow:auto;max-width:100%;max-height:min(70vh,960px);border:1px solid var(--grid-border);background:#fff;overscroll-behavior:contain}
.product-data-table{border-collapse:separate;border-spacing:0;min-width:100%;width:max-content;font-size:12px;line-height:1.35;color:#172725}
.product-data-table th{position:sticky;top:0;z-index:2;background:var(--grid-green);color:#fff;font-size:12px;font-weight:600;text-align:left;border-right:1px solid #5e988a;border-bottom:1px solid #165542;padding:9px 11px;white-space:nowrap}
.product-data-table td{height:43px;max-width:260px;min-width:72px;padding:5px 11px;vertical-align:middle;border-right:1px solid var(--grid-border);border-bottom:1px solid var(--grid-border);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-data-table tbody tr:hover td{background:#f2faf6}
.product-data-table .col-name{min-width:240px;max-width:440px}
.product-data-table .col-picture{min-width:56px;text-align:center;padding:3px 8px}
.product-thumbnail{display:block;margin:auto;width:34px;height:34px;object-fit:contain;background:#f6f8f7;border-radius:3px}
.product-grid-link,.grid-actions a{color:#127362;text-decoration:none;font-weight:500}
.product-grid-link:hover,.grid-actions a:hover{text-decoration:underline}
.external-url{display:block;max-width:170px;text-overflow:ellipsis;overflow:hidden}
.product-status{display:inline-block;border-radius:3px;padding:3px 7px;background:#dbf2e5;color:#176b42;font-weight:600}
.product-status.inactive{background:#fee1db;color:#a33229}
.grid-actions{white-space:nowrap;min-width:125px!important}
.grid-actions a,.grid-actions button{margin-right:10px}
.danger-link{border:0;padding:0;background:transparent;color:#b73c38;cursor:pointer;font-size:12px}
.head-sort{display:inline-flex;align-items:center;gap:6px;background:transparent;border:0;color:inherit;font-size:inherit;font-weight:inherit;cursor:pointer}
.sort-mark{opacity:.7;font-size:10px}
.grid-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 10px;font-size:13px;position:sticky;bottom:0;background:#fff;z-index:4;border:1px solid var(--grid-border);border-top:0;box-shadow:0 -3px 12px rgba(0,0,0,.035)}
.grid-pages{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.grid-pages button,.grid-page-size select{padding:6px 10px;border:1px solid var(--grid-border);border-radius:6px;background:#fff;cursor:pointer;color:#27453b;font-size:12px;min-height:30px}
.grid-pages button:hover:not(:disabled){border-color:#166b57;background:#eaf5f0}
.grid-pages button.active{background:#166b57;color:#fff;border-color:#166b57;font-weight:700}
.grid-pages button:disabled{opacity:.45;cursor:not-allowed}
.grid-ellipsis{color:#71857c;padding:0 3px}
.product-image-empty{color:#8e9d99}
.grid-page-size{display:flex;align-items:center;gap:8px}
:deep(.product-data-table .column-settings-header){position:sticky!important;right:0;top:0;z-index:5!important;background:var(--grid-green);box-shadow:-1px 0 0 var(--grid-border)}
.end-cap{position:sticky;right:0;z-index:1;width:46px;min-width:46px!important;padding:0!important;background:#fff;box-shadow:-1px 0 0 var(--grid-border)}
@media(max-width:1100px){.product-header-controls{width:100%;justify-content:space-between;margin-left:0}.product-search{flex:1;max-width:450px}}
@media(max-width:640px){.product-grid-page{padding-inline:10px}.product-header-controls{align-items:stretch}.product-search{width:100%;max-width:none;flex:1 1 100%}.page-header-actions{width:100%}.grid-topbar{justify-content:flex-end}.grid-hint{display:none}.grid-footer{justify-content:flex-start}.grid-result-count{width:100%}.grid-page-size{margin-left:auto}.product-grid-scroller{max-height:60vh}.product-data-table{font-size:12px}.grid-pages{gap:3px}.grid-pages button{padding:5px 8px}}
</style>
