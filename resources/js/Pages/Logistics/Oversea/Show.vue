<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type WarehouseOption = { key:string; id:number; code:string; label:string; carrier:string|null; url:string }
type Item = { sku:string|null; quantity:number }
type Row = {
  line:number; order_id:number; erp_order_id:string|null; order_url:string|null; recipient_name:string|null; country:string|null
  warehouse_id:number; warehouse_name:string|null; carrier:string|null; tracking_number:string|null
  progress:string; note:string|null; review_status:'approved'|'pending'; shipment_status:string; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; total:number; links?:{url:string|null;label:string;active:boolean}[] }
const props = defineProps<{
  warehouseKey:string; warehouse:WarehouseOption|null; warehouseOptions:WarehouseOption[]; view:string
  rows:PageRows; viewCounts:Record<string,number>; canOperate:boolean
}>()

const drafts = reactive<Record<number,{ progress:string; note:string }>>({})
const rowErrors = reactive<Record<number,string>>({})
const draft = (row:Row) => drafts[row.order_id] ??= { progress:row.progress, note:row.note ?? '' }
const viewTabs = [
  ['review','待审核'], ['pending','待发货'], ['shipped','已发货'], ['exceptions','异常订单'], ['all','全部订单'],
] as const
const progressOptions = [
  ['pending_review','待审核'], ['sending_pending','1.1 Sending 待发'], ['shipping','1.2 Shipping 发货中'], ['shipped','1.3 Shipped 已发'],
] as const

const pageTitle = computed(() => props.warehouse ? `海外仓发货 · ${props.warehouse.label}` : '海外仓发货 · 全部海外仓')
const basePath = computed(() => props.warehouse ? props.warehouse.url : '/logistics/oversea')
const switchView = (view:string) => router.visit(`${basePath.value}?view=${view}`, { preserveScroll:true, preserveState:true, only:['rows','view','viewCounts','warehouse','warehouseKey','warehouseOptions','canOperate'] })
const switchWarehouse = (url:string) => router.visit(`${url}?view=${props.view}`, { preserveScroll:true, preserveState:true })
const saveDraft = (row:Row, payload:Record<string,string|number|null>) => router.patch(`/logistics/oversea/orders/${row.order_id}/draft`, payload, { preserveScroll:true, preserveState:true })
const changeWarehouse = (row:Row, warehouseId:number) => saveDraft(row, { warehouse_id:Number(warehouseId) })
const changeWarehouseFromEvent = (row:Row, event:Event) => changeWarehouse(row, Number((event.target as HTMLSelectElement).value))
const changeProgress = (row:Row) => saveDraft(row, { progress:draft(row).progress })
const saveNote = (row:Row) => saveDraft(row, { note:draft(row).note.trim() || null })
const approve = (row:Row) => {
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/approve`, { note:draft(row).note.trim() || null }, { preserveScroll:true, preserveState:true })
}
const reopenReview = (row:Row) => router.post(`/logistics/oversea/orders/${row.order_id}/reopen-review`, {}, { preserveScroll:true })
const reject = (row:Row) => {
  const note = draft(row).note.trim()
  if (!note) { rowErrors[row.order_id] = '请先填写驳回原因'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/reject`, { note }, { preserveScroll:true, preserveState:true })
}
const itemText = (row:Row) => row.items.map(item => `${item.sku ?? '无SKU'} × ${item.quantity}`).join(' / ') || '—'
const goPage = (page:number) => router.visit(`${basePath.value}?view=${props.view}&page=${page}`, { preserveScroll:true, preserveState:true })
</script>

<template>
  <Head :title="`${pageTitle} · BYL ERP`" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','海外仓发货', warehouse?.label ?? '全部海外仓']">
    <section class="oversea-page">
      <div class="oversea-workbench">
        <aside class="warehouse-rail" aria-label="海外仓切换">
          <p class="warehouse-rail-title">海外仓</p>
          <button type="button" class="warehouse-link" :class="{ active: warehouseKey === 'all' }" @click="switchWarehouse('/logistics/oversea')">全部海外仓</button>
          <button v-for="option in warehouseOptions" :key="option.key" type="button" class="warehouse-link" :class="{ active: warehouseKey === option.key }" @click="switchWarehouse(option.url)">{{ option.label }}</button>
        </aside>
        <main class="oversea-main">
      <header class="oversea-heading">
        <div><h1>{{ pageTitle }}</h1><p>审核 Vita 分仓结果；通过后进入真实下单，驳回则退回运营重新分配。</p></div>
        <div class="oversea-file-actions" v-if="warehouse && view === 'pending'">
          <button type="button" class="file-button" disabled title="待发货视图开放仓库文件模板后启用">⇩ 下载发货表</button>
          <button type="button" class="file-button primary" disabled title="待发货视图开放仓库单号模板后启用">⇧ 上传单号表</button>
        </div>
      </header>

      <nav class="oversea-tabs" aria-label="海外仓状态视图">
        <button v-for="([key,label]) in viewTabs" :key="key" type="button" class="oversea-tab" :class="{ active:view === key }" @click="switchView(key)">
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <div class="oversea-table-wrap">
        <table class="oversea-table">
          <thead><tr>
            <th class="decision-col">审核</th><th class="line-col">#</th><th>Status 进度</th><th>Stock 仓库</th><th>Carrier 承运商</th>
            <th>追踪单号 Tracking No.</th><th>备注</th><th>ERP订单号</th><th>收件人</th><th>国家</th><th>SKU / 数量</th>
          </tr></thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.order_id">
              <td class="decision-cell">
                <template v-if="canOperate && view === 'review'">
                  <button type="button" class="decision approve" @mousedown.prevent @click="approve(row)">通过</button>
                  <button type="button" class="decision reject" title="如需驳回，请先填写备注原因" @mousedown.prevent @click="reject(row)">驳回</button>
                </template>
                <div v-else-if="row.review_status === 'approved'" class="decision-approved"><span class="decision-state approved">已通过</span><button v-if="canOperate" type="button" class="reopen-review" @click="reopenReview(row)">重新审核</button></div>
                <span v-else class="decision-state">待审核</span>
              </td>
              <td class="line-col">{{ row.line }}</td>
              <td><select v-model="draft(row).progress" :disabled="!canOperate" class="cell-select progress" @change="changeProgress(row)"><option v-for="([value,label]) in progressOptions" :key="value" :value="value">{{ label }}</option></select></td>
              <td><select :value="row.warehouse_id" :disabled="!canOperate || view !== 'review'" class="cell-select warehouse" @change="changeWarehouseFromEvent(row, $event)"><option v-for="option in warehouseOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></td>
              <td><span class="carrier-pill">{{ row.carrier ?? '—' }}</span></td>
              <td class="tracking-cell">{{ row.tracking_number ?? '—' }}</td>
              <td><div class="note-cell"><input v-model="draft(row).note" :disabled="!canOperate" class="note-input" :class="{ error: !!rowErrors[row.order_id] }" placeholder="等海运 / 等空运 / 缺货 / 太贵 / 送不到…" @input="rowErrors[row.order_id] = ''" @blur="view === 'review' ? undefined : saveNote(row)" /><small v-if="rowErrors[row.order_id]" class="note-error">{{ rowErrors[row.order_id] }}</small></div></td>
              <td><a class="order-link" :href="row.order_url ?? `/customers/orders/${row.order_id}`">{{ row.erp_order_id ?? '—' }}</a></td>
              <td>{{ row.recipient_name ?? '—' }}</td>
              <td>{{ row.country ?? '—' }}</td>
              <td class="items-cell">{{ itemText(row) }}</td>
            </tr>
            <tr v-if="!rows.data.length"><td colspan="11" class="empty-cell">当前视图暂无订单</td></tr>
          </tbody>
        </table>
      </div>

      <footer class="oversea-footer">
        <span>共 {{ rows.total }} 条记录</span>
        <div class="pagination" v-if="rows.last_page > 1">
          <button v-for="page in rows.last_page" :key="page" type="button" :class="{ active: page === rows.current_page }" @click="goPage(page)">{{ page }}</button>
        </div>
      </footer>
        </main>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.oversea-page{padding:18px 22px 28px;min-width:0}.oversea-workbench{display:grid;grid-template-columns:166px minmax(0,1fr);gap:14px;align-items:start}.oversea-main{min-width:0}.warehouse-rail{position:sticky;top:12px;border:1px solid #dfe6ea;border-radius:10px;background:#fff;padding:8px}.warehouse-rail-title{margin:4px 8px 7px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}.warehouse-link{width:100%;border:0;background:transparent;border-radius:7px;padding:8px 10px;text-align:left;color:#475569;font-size:13px;font-weight:700;white-space:nowrap}.warehouse-link:hover{background:#f1f5f9}.warehouse-link.active{background:#e7f6f3;color:#0f766e}.oversea-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.oversea-heading h1{margin:0;color:#132238;font-size:28px;line-height:1.2}.oversea-heading p{margin:6px 0 0;color:#64748b;font-size:14px}
.oversea-file-actions{display:flex;gap:10px}.file-button{height:40px;padding:0 16px;border:1px solid #0f8f82;border-radius:8px;background:white;color:#0d796f;font-weight:700}.file-button.primary{background:#0f8f82;color:white}.file-button:disabled{opacity:.45;cursor:not-allowed}
.oversea-tabs{display:flex;gap:6px;align-items:center;margin:0 0 12px;border-bottom:1px solid #e2e8f0;overflow-x:auto}.oversea-tab{border:0;background:transparent;padding:9px 14px 8px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.oversea-tab span{margin-left:4px;color:#94a3b8;font-weight:600}.oversea-tab.active{color:#0f766e;border-bottom-color:#0f766e}.oversea-tab.active span{color:#0f766e}
.oversea-table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:white}.oversea-table{width:max-content;min-width:1760px;border-collapse:collapse;font-size:13px}.oversea-table th{padding:9px 10px;background:#f8fafc;color:#425466;font-weight:700;text-align:left;border-right:1px solid #e5eaee;border-bottom:1px solid #dfe6ea;white-space:nowrap}.oversea-table td{padding:8px 10px;border-right:1px solid #edf1f4;border-bottom:1px solid #e7ecef;vertical-align:middle;white-space:nowrap}.oversea-table tr:last-child td{border-bottom:0}.decision-col{width:118px}.line-col{width:44px;text-align:center!important;color:#64748b}.decision-cell{white-space:nowrap}.decision{border:1px solid transparent;border-radius:6px;padding:5px 9px;font-weight:700;margin-right:4px}.decision.approve{background:#0ca678;color:white}.decision.reject{background:white;color:#d33;border-color:#efb6b6}.decision-state{font-weight:700;color:#a16207}.decision-state.approved{color:#087f5b}.decision-approved{display:flex;flex-direction:column;align-items:flex-start;gap:5px}.reopen-review{border:1px solid #99cfc7;background:#fff;color:#0f766e;border-radius:6px;padding:4px 8px;font-size:12px;font-weight:700}
.cell-select,.note-input{width:100%;min-height:34px;border:1px solid #d7e0e5;border-radius:6px;background:white;color:#334155;padding:5px 8px}.cell-select:disabled,.note-input:disabled{background:#f8fafc;color:#64748b}.cell-select.progress{min-width:145px}.cell-select.warehouse{min-width:135px}.carrier-pill{display:inline-block;padding:5px 10px;border-radius:6px;background:#eef2f7;color:#334155;font-weight:700}.tracking-cell{min-width:220px}.note-cell{display:flex;flex-direction:column;gap:4px}.note-input{min-width:260px}.note-input.error{border-color:#dc2626}.note-error{color:#dc2626;font-size:11px}.order-link{display:inline-block;min-width:180px;color:#0f766e;font-weight:700;text-decoration:none;white-space:nowrap}.order-link:hover{text-decoration:underline}.items-cell{min-width:180px;color:#475569}.empty-cell{text-align:center!important;color:#94a3b8;padding:30px!important}
.oversea-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 2px;color:#64748b}.pagination{display:flex;gap:5px}.pagination button{border:1px solid #d7e0e5;background:white;border-radius:6px;min-width:32px;height:32px;color:#475569}.pagination button.active{background:#0f766e;border-color:#0f766e;color:white}
@media (max-width:900px){.oversea-workbench{grid-template-columns:1fr}.warehouse-rail{position:static;display:flex;gap:6px;overflow-x:auto}.warehouse-rail-title{display:none}.warehouse-link{width:auto;flex:0 0 auto}.oversea-heading{flex-direction:column}.oversea-file-actions{align-self:stretch}.file-button{flex:1}.oversea-page{padding:14px}}
</style>
