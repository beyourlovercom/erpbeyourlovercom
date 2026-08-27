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
  name: string
  unit: string | null
  is_active: boolean
  shipping_weight_kg: string | null
  standard_production_cost: string | null
  standard_packaging_cost: string | null
  cost_currency: string | null
  sale_price: string | null
  regular_price: string | null
}
type PaginatedProducts = {
  data: Product[]
  current_page: number
  last_page: number
  total: number
  per_page: number
  from: number | null
  to: number | null
  links: { url: string | null; label: string; active: boolean }[]
}

const props = defineProps<{ products: PaginatedProducts; filters: { search?: string }; canOperate: boolean }>()
const search = ref(props.filters.search ?? '')
const confirmDeleteId = ref<number | null>(null)

const applySearch = () => {
  router.get('/products/list', search.value ? { search: search.value } : {}, { preserveState: true, replace: true })
}

const confirmDelete = (id: number) => {
  confirmDeleteId.value = id
}

const doDelete = () => {
  if (confirmDeleteId.value === null) return
  router.delete(`/products/${confirmDeleteId.value}`, { onFinish: () => { confirmDeleteId.value = null } })
}

const cancelDelete = () => {
  confirmDeleteId.value = null
}

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '产品列表']

const fmt = (v: string | number | null | undefined) => (v == null || v === '') ? '—' : v
const sourceLabel = (s: string) => s === 'manual' ? '手动' : 'Woo'
</script>

<template>
  <Head title="产品列表 · BYL ERP" />
  <AuthenticatedLayout title="产品列表" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>产品列表</h2><p>共 {{ products.total }} 件产品</p></div>
        <div v-if="canOperate" class="mvp-page-actions">
          <a href="/products/create" class="mvp-button">新增产品</a>
          <a href="/products/import" class="mvp-button mvp-button-secondary">导入 CSV</a>
        </div>
      </div>

      <form class="mvp-filter-bar" @submit.prevent="applySearch">
        <input v-model="search" type="search" placeholder="搜索 SKU 或产品名称…" class="mvp-search-input">
        <button class="mvp-button" type="submit">搜索</button>
      </form>

      <div v-if="confirmDeleteId !== null" class="mvp-confirm-overlay">
        <div class="mvp-confirm-dialog">
          <p>确认删除此产品？若该产品为 Woo 导入，下次导入相同 Woo ID 的 CSV 时将重新创建。</p>
          <div class="mvp-confirm-actions">
            <button class="mvp-button mvp-button-danger" @click="doDelete">确认删除</button>
            <button class="mvp-button mvp-button-secondary" @click="cancelDelete">取消</button>
          </div>
        </div>
      </div>

      <div class="mvp-table-wrapper">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>产品名称</th>
              <th>来源</th>
              <th>单位</th>
              <th>运重 (kg)</th>
              <th>标准生产成本</th>
              <th>标准包材成本</th>
              <th>成本币种</th>
              <th>售价</th>
              <th>原价</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td>
                <a v-if="p.sku && canOperate" :href="`/products/${p.id}/edit`" class="mvp-link">{{ p.sku }}</a>
                <span v-else>{{ p.sku ?? '—' }}</span>
              </td>
              <td>{{ p.name }}</td>
              <td>{{ sourceLabel(p.source_type) }}</td>
              <td>{{ fmt(p.unit) }}</td>
              <td>{{ fmt(p.shipping_weight_kg) }}</td>
              <td>{{ fmt(p.standard_production_cost) }}</td>
              <td>{{ fmt(p.standard_packaging_cost) }}</td>
              <td>{{ fmt(p.cost_currency) }}</td>
              <td>{{ fmt(p.sale_price) }}</td>
              <td>{{ fmt(p.regular_price) }}</td>
              <td>{{ p.is_active ? '启用' : '停用' }}</td>
              <td class="mvp-actions-cell">
                <template v-if="canOperate">
                  <a :href="`/products/${p.id}/edit`" class="mvp-link">编辑</a>
                  <button class="mvp-link mvp-link-danger" @click="confirmDelete(p.id)">删除</button>
                </template>
                <span v-else>—</span>
              </td>
            </tr>
            <tr v-if="products.data.length === 0">
              <td colspan="12" class="mvp-empty">暂无产品</td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="products.last_page > 1" class="mvp-pagination">
        <template v-for="link in products.links" :key="link.label">
          <a v-if="link.url" :href="link.url" :class="{ 'mvp-page-active': link.active }" v-html="link.label" />
          <span v-else v-html="link.label" />
        </template>
      </nav>
    </section>
  </AuthenticatedLayout>
</template>
