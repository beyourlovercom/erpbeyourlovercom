<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Order = { id: number; erp_order_id: string | null; order_kind: string | null; order_status: string | null; operational_archived_at: string | null; archived_by: string | null; owner: string | null }
const props = defineProps<{ orders: { data: Order[]; current_page: number; last_page: number; total: number }; canRestore: boolean }>()
const detailPath = (order: Order) => '/orders/' + order.id
const statusLabel = (status: string | null): string => {
  const labels: Record<string, string> = {
    pending_shipment: '待发货', shipped: '已发货',
    completed: '已完结', completed_delivered: '已送达', cancelled: '已取消',
  }
  return status ? (labels[status] ?? status) : '—'
}
const restore = (order: Order) => {
  if (window.confirm('确认恢复此订单的运营资格？')) router.post('/orders/archive/' + order.id + '/restore', {}, { preserveScroll: true })
}
</script>

<template>
  <Head title="归档清单 · BYL ERP" />
  <AuthenticatedLayout title="归档清单" :breadcrumbs="['BYL ERP', '归档清单']">
    <section class="mvp-page orders-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">订单运营</p><h2>归档清单</h2><p>运营归档订单只退出履约操作序列，财务、物流、同步和历史记录保留。</p></div>
        <div class="mvp-actions page-header-actions"><Link class="mvp-button" href="/orders">返回订单</Link></div>
      </div>
      <div class="mvp-table-wrap"><table class="mvp-table archive-table">
        <thead><tr><th>ERP订单号</th><th>类型</th><th>归档时间</th><th>归档人</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="order in props.orders.data" :key="order.id">
            <td data-label="ERP订单号"><Link :href="detailPath(order)">{{ order.erp_order_id ?? ('#' + order.id) }}</Link></td>
            <td data-label="类型">{{ order.order_kind === 'kol_fulfillment' ? '网红履约' : '客户订单' }}</td>
            <td data-label="归档时间">{{ order.operational_archived_at ? new Date(order.operational_archived_at).toLocaleString('zh-CN', { hour12: false }) : '—' }}</td>
            <td data-label="归档人">{{ order.archived_by ?? '—' }}</td><td data-label="状态">{{ statusLabel(order.order_status) }}</td>
            <td data-label="操作"><button v-if="props.canRestore" class="mvp-button" type="button" @click="restore(order)">恢复运营</button><span v-else>只读</span></td>
          </tr>
          <tr v-if="!props.orders.data.length"><td colspan="6" class="mvp-empty">暂无运营归档订单</td></tr>
        </tbody>
      </table></div>
      <nav v-if="props.orders.last_page > 1" class="mvp-actions" aria-label="归档订单分页">
        <Link v-if="props.orders.current_page > 1" class="mvp-button" :href="'/orders/archive?page=' + (props.orders.current_page - 1)">上一页</Link>
        <span>第 {{ props.orders.current_page }} / {{ props.orders.last_page }} 页 · 共 {{ props.orders.total }} 单</span>
        <Link v-if="props.orders.current_page < props.orders.last_page" class="mvp-button" :href="'/orders/archive?page=' + (props.orders.current_page + 1)">下一页</Link>
      </nav>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
@media (max-width: 640px) {
  .archive-table,
  .archive-table tbody { display: block; width: 100%; min-width: 0; }
  .archive-table thead { display: none; }
  .archive-table tbody tr {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px 16px;
    padding: 16px;
    border-bottom: 1px solid #e2e8f0;
  }
  .archive-table tbody td {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
    min-width: 0;
    padding: 0;
    border: 0;
    white-space: normal;
    overflow-wrap: anywhere;
  }
  .archive-table tbody td:first-child,
  .archive-table tbody td:last-child,
  .archive-table tbody td.mvp-empty { grid-column: 1 / -1; }
  .archive-table tbody td::before {
    content: attr(data-label);
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
  }
  .archive-table tbody td.mvp-empty::before { content: none; }
}
</style>
