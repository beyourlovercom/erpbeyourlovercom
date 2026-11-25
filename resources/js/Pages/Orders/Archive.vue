<script setup lang="ts">
import OrderContactFilter from '../../Components/Orders/OrderContactFilter.vue'
import { currentContactQuery } from '../../Components/Orders/orderContactQuery'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import OrderWorkflowStatusPill from '../../Components/Orders/OrderWorkflowStatusPill.vue'

type Workflow = { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean }
type WorkflowOption = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Order = { contact_user_name?: string | null; id: number; erp_order_id: string | null; order_kind: string | null; order_status: string | null; workflow_revision: number; workflow_status_projection: Workflow; workflow_status_options: WorkflowOption[]; can_change_status: boolean; operational_archived_at: string | null; archived_by: string | null; owner: string | null; direct_archive: boolean; fulfillment_parent_id: number | null }
const props = defineProps<{ orders: { data: Order[]; current_page: number; last_page: number; total: number }; canRestore: boolean; canChangeStatus: boolean; filters: { search: string; per_page: string } }>()
const search = ref(props.filters.search)
const submitSearch = () => router.get('/orders/archive', { ...currentContactQuery(), search: search.value.trim(), per_page: props.filters.per_page, page: 1 }, { preserveState: true, replace: true })
const pageHref = (page: number) => '/orders/archive?' + new URLSearchParams({ ...currentContactQuery(), search: props.filters.search, per_page: props.filters.per_page, page: String(page) }).toString()
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
        <div><p class="mvp-kicker">订单运营</p><h2>归档清单</h2></div>
        <div class="mvp-actions page-header-actions"><Link class="mvp-button" href="/orders">返回订单</Link></div>
      </div>
      <form class="archive-search" role="search" @submit.prevent="submitSearch">
        <input v-model="search" aria-label="搜索全部归档订单" maxlength="120" placeholder="搜索全部归档订单：ERP号 / 来源号 / 收件人 / SKU…" />
        <button class="mvp-button mvp-button-primary" type="submit">搜索</button>
        <button v-if="props.filters.search" class="mvp-button" type="button" @click="search = ''; submitSearch()">清除</button>
        <span class="archive-result-count">匹配 {{ props.orders.total }} 单 · 搜索覆盖全部分页</span>
      </form>
      <OrderContactFilter />
      <div class="mvp-table-wrap"><table class="mvp-table archive-table">
        <thead><tr><th>ERP订单号</th><th>订单联络人</th><th>类型</th><th>归档时间</th><th>归档人</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="order in props.orders.data" :key="order.id">
            <td data-label="ERP订单号"><Link :href="detailPath(order)">{{ order.erp_order_id ?? ('#' + order.id) }}</Link></td><td data-label="订单联络人">{{ order.contact_user_name ?? '未分配' }}</td>
            <td data-label="类型">{{ order.order_kind === 'fulfillment_derived' ? '履约子订单' : order.order_kind === 'kol_fulfillment' ? '网红订单' : '客户订单' }}</td>
            <td data-label="归档时间">{{ order.operational_archived_at ? new Date(order.operational_archived_at).toLocaleString('zh-CN', { hour12: false }) : '—' }}</td>
            <td data-label="归档人">{{ order.archived_by ?? '—' }}</td><td data-label="状态"><OrderWorkflowStatusPill :order-id="order.id" :projection="order.workflow_status_projection" :revision="order.workflow_revision" :options="order.workflow_status_options" :can-edit="props.canChangeStatus && order.can_change_status" /><small class="archive-canonical-status">原业务状态：{{ statusLabel(order.order_status) }}</small></td>
            <td data-label="操作"><button v-if="props.canRestore && order.direct_archive" class="mvp-button" type="button" @click="restore(order)">恢复运营</button><span v-else-if="!order.direct_archive" class="text-slate-500">随主单归档 · <Link v-if="order.fulfillment_parent_id" :href="detailPath({ ...order, id: order.fulfillment_parent_id })">查看主单</Link></span><span v-else-if="props.canChangeStatus && order.can_change_status" class="text-slate-500">可在状态列修改</span><span v-else>只读</span></td>
          </tr>
          <tr v-if="!props.orders.data.length"><td colspan="7" class="mvp-empty">暂无运营归档订单</td></tr>
        </tbody>
      </table></div>
      <nav v-if="props.orders.last_page > 1" class="mvp-actions" aria-label="归档订单分页">
        <Link v-if="props.orders.current_page > 1" class="mvp-button" :href="pageHref(props.orders.current_page - 1)">上一页</Link>
        <span>第 {{ props.orders.current_page }} / {{ props.orders.last_page }} 页 · 共 {{ props.orders.total }} 单</span>
        <Link v-if="props.orders.current_page < props.orders.last_page" class="mvp-button" :href="pageHref(props.orders.current_page + 1)">下一页</Link>
      </nav>
      <p class="archive-help-footer">主订单与履约子订单可独立归档。运营/管理员在状态列更改归档订单状态后，订单会自动恢复并进入对应业务队列；真实物流历史不受影响。已发货订单需要再发货时，请在「履约发货」创建补发/重发子单。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.archive-search{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.archive-search input{flex:1;min-width:220px;border:1px solid #cbd5e1;border-radius:8px;padding:9px 12px}
.archive-result-count{font-size:12px;color:#64748b}
.archive-canonical-status{display:block;margin-top:4px;font-size:11px;color:#64748b}
.archive-help-footer{margin-top:10px;color:#64748b;font-size:12px;line-height:1.5}
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
