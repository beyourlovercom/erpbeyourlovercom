<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Product = {
  id: number
  woocommerce_product_id: number
  product_type: string
  sku: string | null
  gtin: string | null
  name: string
  weight_kg: string | null
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

const props = defineProps<{ products: PaginatedProducts; filters: { search?: string } }>()
const search = ref(props.filters.search ?? '')

const applySearch = () => {
  router.get('/products/list', search.value ? { search: search.value } : {}, { preserveState: true, replace: true })
}

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '产品列表']

const fmt = (v: string | null | undefined) => (v == null || v === '') ? '—' : v
</script>

<template>
  <Head title="产品列表 · BYL ERP" />
  <AuthenticatedLayout title="产品列表" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>产品列表</h2><p>共 {{ products.total }} 件产品</p></div>
      </div>

      <form class="mvp-filter-bar" @submit.prevent="applySearch">
        <input v-model="search" type="search" placeholder="搜索 SKU 或产品名称…" class="mvp-search-input">
        <button class="mvp-button" type="submit">搜索</button>
      </form>

      <div class="mvp-table-wrapper">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>产品名称</th>
              <th>Woo ID</th>
              <th>类型</th>
              <th>重量 (kg)</th>
              <th>售价</th>
              <th>原价</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td>{{ fmt(p.sku) }}</td>
              <td>{{ p.name }}</td>
              <td>{{ p.woocommerce_product_id }}</td>
              <td>{{ p.product_type }}</td>
              <td>{{ fmt(p.weight_kg) }}</td>
              <td>{{ fmt(p.sale_price) }}</td>
              <td>{{ fmt(p.regular_price) }}</td>
            </tr>
            <tr v-if="products.data.length === 0">
              <td colspan="7" class="mvp-empty">暂无产品</td>
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
