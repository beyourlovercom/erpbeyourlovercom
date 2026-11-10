<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ProductSku = {
  id: number
  sku: string | null
  name: string
  is_active: boolean
  source_type: string
  alias_count: number
}
type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
  links: { url: string | null; label: string; active: boolean }[]
}

const props = defineProps<{
  products: Paginated<ProductSku>
  filters: { search?: string }
  globalGridPilotAvailable?: boolean
  globalGridPilotEnabled?: boolean
}>()
const search = ref(props.filters.search ?? '')
watch(() => props.filters.search, value => { search.value = value ?? '' })

// Use only the canonical /products/skus GET query. Original list remains the default.
const navigate = (extra: Record<string, string | number | undefined> = {}) => router.get('/products/skus', {
  ...(search.value ? { search: search.value } : {}),
  ...(props.globalGridPilotEnabled ? { global_grid: '1', per_page: props.products.per_page } : {}),
  ...extra,
}, { preserveState: true, replace: true })
const applySearch = () => navigate({ page: 1 })
const toggleGlobalGrid = () => navigate({ global_grid: props.globalGridPilotEnabled ? '0' : '1', page: 1 })
const gridSearch = (value: string) => { search.value = value; navigate({ search: value || undefined, page: 1 }) }
const gridPage = (page: number) => navigate({ page })
const gridPageSize = (size: number | 'all') => {
  if (size === 'all') return
  navigate({ per_page: size, page: 1 })
}

type GridRow = Record<string, unknown>
const globalRows = computed<GridRow[]>(() => props.products.data.map(product => ({
  id: product.id,
  sku: product.sku ?? '—',
  name: product.name,
  status_label: product.is_active ? '启用' : '停用',
  source_label: product.source_type === 'manual' ? '手动' : 'Woo',
  alias_count: product.alias_count,
})))
const globalColumns: ColDef<GridRow>[] = [
  { colId: 'sku', field: 'sku', headerName: '规范 SKU', width: 180, sortable: false,
    cellRenderer: GlobalGridCell, cellRendererParams: {
      mode: 'link', hrefFor: (row: GridRow) => '/products/' + row.id + '/edit',
    } },
  { colId: 'name', field: 'name', headerName: '产品名称', width: 260, sortable: false },
  { colId: 'status', field: 'status_label', headerName: '状态', width: 105, sortable: false,
    cellRenderer: GlobalGridCell, cellRendererParams: { mode: 'badge' } },
  { colId: 'source', field: 'source_label', headerName: '来源', width: 110, sortable: false },
  { colId: 'aliases', field: 'alias_count', headerName: '别名数量', width: 112, sortable: false },
  { colId: 'actions', field: 'id', headerName: '操作', width: 128, sortable: false,
    cellRenderer: GlobalGridCell, cellRendererParams: {
      mode: 'actions', actionsFor: (row: GridRow) => [
        { label: '查看产品', href: '/products/' + row.id + '/edit' },
      ],
    } },
]
const pager = computed(() => ({
  current_page: props.products.current_page, last_page: props.products.last_page,
  per_page: props.products.per_page, total: props.products.total,
}))

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  '规范 SKU 注册表',
]
</script>

<template>
  <Head title="SKU 注册表 · BYL ERP" />
  <AuthenticatedLayout title="SKU 注册表" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">产品主数据</p>
          <h2>规范 SKU 注册表</h2>
          <p>共 {{ products.total }} 件产品</p>
        </div>
        <div class="mvp-page-actions page-header-actions">
          <button v-if="globalGridPilotAvailable" type="button" class="mvp-button"
            data-testid="sku-global-grid-toggle" @click="toggleGlobalGrid">{{ globalGridPilotEnabled ? '返回原列表' : '体验 Global Grid' }}</button>
          <a href="/products/sku-aliases" class="mvp-button mvp-button-secondary">SKU 别名 &amp; 待解析</a>
        </div>
      </div>

      <form v-if="!globalGridPilotEnabled" class="mvp-filter-bar" @submit.prevent="applySearch">
        <input v-model="search" type="search" placeholder="搜索规范 SKU 或产品名称…" class="mvp-search-input" />
        <button class="mvp-button" type="submit">搜索</button>
      </form>

      <GlobalGrid v-if="globalGridPilotEnabled"
        namespace="grid.products.skus.global.v1" title="SKU 注册表 · Global Grid V1"
        :rows="globalRows" :columns="globalColumns" :required-column-ids="['sku']"
        :page="pager" :search="filters.search ?? ''"
        search-placeholder="搜索全部 SKU / 产品名称 / 别名..."
        @search-change="gridSearch" @page-change="gridPage" @page-size-change="gridPageSize" />
      <div v-else class="mvp-table-wrapper">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>规范 SKU</th>
              <th>产品名称</th>
              <th>状态</th>
              <th>来源</th>
              <th>别名数量</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td>{{ p.sku ?? '—' }}</td>
              <td>{{ p.name }}</td>
              <td>{{ p.is_active ? '启用' : '停用' }}</td>
              <td>{{ p.source_type === 'manual' ? '手动' : 'Woo' }}</td>
              <td>{{ p.alias_count }}</td>
              <td class="mvp-actions-cell">
                <a :href="`/products/${p.id}/edit`" class="mvp-link">查看产品</a>
              </td>
            </tr>
            <tr v-if="products.data.length === 0">
              <td colspan="6" class="mvp-empty">暂无产品</td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="!globalGridPilotEnabled && products.last_page > 1" class="mvp-pagination">
        <template v-for="link in products.links" :key="link.label">
          <a v-if="link.url" :href="link.url" :class="{ 'mvp-page-active': link.active }" v-html="link.label" />
          <span v-else v-html="link.label" />
        </template>
      </nav>
    </section>
  </AuthenticatedLayout>
</template>
