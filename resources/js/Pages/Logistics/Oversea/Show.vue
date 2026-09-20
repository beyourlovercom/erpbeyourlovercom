<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type WarehouseOption = { key:string; id:number; code:string; label:string; carrier:string|null; url:string }
type Item = { sku:string|null; quantity:number }
type CarrierServiceOption = { id:number; key:string; value:string; label:string; name_en:string|null; name_zh:string|null }
type CarrierOption = { value:string; label:string; default_service_id:number|null; default_service_value:string|null; services:CarrierServiceOption[] }
type Row = {
  line:number; order_id:number; erp_order_id:string|null; order_url:string|null; recipient_name:string|null; country:string|null
  warehouse_id:number; warehouse_name:string|null; carrier:string|null; service:string|null; carrier_selection_source:string|null; tracking_number:string|null
  progress:string; note:string|null; review_status:'approved'|'pending'; shipment_status:string; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; total:number; links?:{url:string|null;label:string;active:boolean}[] }
type TrackingImportRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string|null; shipped_at:string; status:'apply'|'noop'|'blocked'; message:string }
type TrackingImportPreview = { token:string; rows:TrackingImportRow[]; apply_count:number; noop_count:number; blocked_count:number }
const props = defineProps<{
  warehouseKey:string; warehouse:WarehouseOption|null; warehouseOptions:WarehouseOption[]; view:string
  rows:PageRows; viewCounts:Record<string,number>; canOperate:boolean; carrierOptions:CarrierOption[]; trackingImportPreview:TrackingImportPreview|null
}>()

const drafts = reactive<Record<number,{ note:string; carrier:string; service:string; tracking_number:string }>>({})
const trackingFileInput = ref<HTMLInputElement|null>(null)
const trackingPreviewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 20) ?? [])
const rowErrors = reactive<Record<number,string>>({})
const carrierOption = (name:string) => props.carrierOptions.find(row => row.value === name)
const serviceOptionsFor = (name:string) => carrierOption(name)?.services ?? []
const defaultServiceFor = (name:string, country?:string|null) => {
  const carrier = carrierOption(name)
  if (!carrier) return ''
  if (name === 'DHL' && String(country ?? '').toUpperCase() === 'DE') {
    return carrier.services.find(service => service.key === 'paket_domestic')?.value ?? carrier.default_service_value ?? ''
  }
  return carrier.default_service_value ?? ''
}
const draft = (row:Row) => drafts[row.order_id] ??= { note:row.note ?? '', carrier:row.carrier ?? '', service:row.service ?? defaultServiceFor(row.carrier ?? '', row.country), tracking_number:row.tracking_number ?? '' }
const viewTabs = [
  ['review','待审核'], ['pending','待发货'], ['shipped','已发货'], ['exceptions','异常订单'], ['all','全部订单'],
] as const


const pageTitle = computed(() => props.warehouse ? `海外仓发货 · ${props.warehouse.label}` : '海外仓发货 · 全部海外仓')
const basePath = computed(() => props.warehouse ? props.warehouse.url : '/logistics/oversea')
const switchView = (view:string) => router.visit(`${basePath.value}?view=${view}`, { preserveScroll:true, preserveState:true, only:['rows','view','viewCounts','warehouse','warehouseKey','warehouseOptions','canOperate','carrierOptions'] })
const switchWarehouse = (url:string) => router.visit(`${url}?view=${props.view}`, { preserveScroll:true, preserveState:true })
const saveDraft = (row:Row, payload:Record<string,string|number|null>) => router.patch(`/logistics/oversea/orders/${row.order_id}/draft`, payload, { preserveScroll:true, preserveState:true })
const changeWarehouse = (row:Row, warehouseId:number) => saveDraft(row, { warehouse_id:Number(warehouseId) })
const changeWarehouseFromEvent = (row:Row, event:Event) => changeWarehouse(row, Number((event.target as HTMLSelectElement).value))
const saveNote = (row:Row) => saveDraft(row, { note:draft(row).note.trim() || null })
const saveCarrier = (row:Row) => { const d=draft(row); d.service=defaultServiceFor(d.carrier, row.country); saveDraft(row, { carrier:d.carrier, service:d.service || null }) }
const saveService = (row:Row) => saveDraft(row, { carrier:draft(row).carrier, service:draft(row).service || null })
const saveTracking = (row:Row) => saveDraft(row, { tracking_number:draft(row).tracking_number.trim() || null })
const chooseTrackingFile = () => trackingFileInput.value?.click()
const previewTrackingFile = (event:Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const data = new FormData()
  data.append('file', file)
  router.post(`${basePath.value}/tracking-import/preview`, data, { forceFormData:true, preserveScroll:true, onFinish:() => { input.value = '' } })
}
const applyTrackingPreview = () => {
  if (!props.trackingImportPreview) return
  router.post(`${basePath.value}/tracking-import/apply`, { token:props.trackingImportPreview.token }, { preserveScroll:true })
}
const trackingStatusText = (status:TrackingImportRow['status']) => status === 'apply' ? '可应用' : (status === 'noop' ? '重复跳过' : '阻断')
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
          <a class="file-button file-link" :href="`${basePath}/export`">⇩ 下载发货表</a>
          <input ref="trackingFileInput" class="hidden-file" type="file" accept=".csv,text/csv" @change="previewTrackingFile" />
          <button v-if="canOperate" type="button" class="file-button primary" @click="chooseTrackingFile">⇧ 上传单号表</button>
        </div>
      </header>

      <section v-if="warehouse && view === 'pending' && trackingImportPreview" class="tracking-preview">
        <div class="tracking-preview-head">
          <div>
            <strong>单号表预览</strong>
            <span>可应用 {{ trackingImportPreview.apply_count }} · 重复跳过 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span>
          </div>
          <button type="button" class="file-button primary" :disabled="trackingImportPreview.blocked_count > 0 || trackingImportPreview.apply_count === 0" @click="applyTrackingPreview">确认发货并同步 Woo</button>
        </div>
        <p class="tracking-hint">只接受真实 Tracking 和包含时区的发货时间，例如 2026-09-15T10:30:00-07:00；阻断项必须修正后重新上传。</p>
        <div class="tracking-preview-table-wrap">
          <table class="tracking-preview-table">
            <thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>发货时间</th><th>结果</th><th>说明</th></tr></thead>
            <tbody><tr v-for="row in trackingPreviewRows" :key="`${row.row}-${row.order_number}`"><td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number }}</td><td>{{ row.carrier ?? '—' }}</td><td>{{ row.shipped_at }}</td><td><span class="tracking-status" :class="row.status">{{ trackingStatusText(row.status) }}</span></td><td>{{ row.message || '—' }}</td></tr></tbody>
          </table>
        </div>
        <small v-if="trackingImportPreview.rows.length > trackingPreviewRows.length">仅展示前 {{ trackingPreviewRows.length }} 行；应用时仍会校验整份文件。</small>
      </section>

      <nav class="oversea-tabs" aria-label="海外仓状态视图">
        <button v-for="([key,label]) in viewTabs" :key="key" type="button" class="oversea-tab" :class="{ active:view === key }" @click="switchView(key)">
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <div class="oversea-table-wrap">
        <table class="oversea-table">
          <thead><tr>
            <th class="decision-col">审核</th><th class="line-col">#</th><th>订单号</th><th>Stock 仓库</th><th>Carrier 承运商</th>
            <th>运输 / 服务类型</th><th>追踪单号 Tracking No.</th><th>备注</th><th>收件人</th><th>国家</th><th>SKU / 数量</th>
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
              <td><a class="order-link" :href="row.order_url ?? `/orders/${row.order_id}`">{{ row.erp_order_id ?? '—' }}</a></td>
              <td><select :value="row.warehouse_id" :disabled="!canOperate || view !== 'review'" class="cell-select warehouse" @change="changeWarehouseFromEvent(row, $event)"><option v-for="option in warehouseOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></td>
              <td><select v-model="draft(row).carrier" :disabled="!canOperate || row.shipment_status === 'shipped'" class="cell-select carrier" @change="saveCarrier(row)"><option v-for="carrier in carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select></td><td><select v-model="draft(row).service" :disabled="!canOperate || row.shipment_status === 'shipped' || !draft(row).carrier" class="cell-select service" @change="saveService(row)"><option value="">请选择服务类型</option><option v-for="service in serviceOptionsFor(draft(row).carrier)" :key="service.id" :value="service.value">{{ service.label }}</option></select></td>
              <td class="tracking-cell"><input v-model="draft(row).tracking_number" :disabled="!canOperate || row.shipment_status === 'shipped'" class="tracking-input" placeholder="填写发货单号" @blur="saveTracking(row)" /></td>
              <td><div class="note-cell"><input v-model="draft(row).note" :disabled="!canOperate" class="note-input" :class="{ error: !!rowErrors[row.order_id] }" placeholder="等海运 / 等空运 / 缺货 / 太贵 / 送不到…" @input="rowErrors[row.order_id] = ''" @blur="view === 'review' ? undefined : saveNote(row)" /><small v-if="rowErrors[row.order_id]" class="note-error">{{ rowErrors[row.order_id] }}</small></div></td>
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
.oversea-page{padding:18px 22px 28px 8px;min-width:0}.oversea-workbench{display:grid;grid-template-columns:124px minmax(0,1fr);gap:10px;align-items:start}.oversea-main{min-width:0}.warehouse-rail{position:sticky;top:12px;border:1px solid #dfe6ea;border-radius:8px;background:#fff;padding:6px 5px}.warehouse-rail-title{margin:2px 6px 5px;color:#64748b;font-size:11px;font-weight:800;letter-spacing:.06em}.warehouse-link{width:100%;border:0;background:transparent;border-radius:6px;padding:7px 6px;text-align:left;color:#475569;font-size:12px;font-weight:700;white-space:nowrap}.warehouse-link:hover{background:#f1f5f9}.warehouse-link.active{background:#e7f6f3;color:#0f766e}.oversea-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.oversea-heading h1{margin:0;color:#132238;font-size:28px;line-height:1.2}.oversea-heading p{margin:6px 0 0;color:#64748b;font-size:14px}
.oversea-file-actions{display:flex;gap:10px}.hidden-file{display:none}.file-button{height:40px;padding:0 16px;border:1px solid #0f8f82;border-radius:8px;background:white;color:#0d796f;font-weight:700}.file-button.primary{background:#0f8f82;color:white}.file-link{display:inline-flex;align-items:center;text-decoration:none}.file-button:disabled{opacity:.45;cursor:not-allowed}
.tracking-preview{margin:0 0 14px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.tracking-preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.tracking-preview-head>div{display:flex;flex-direction:column;gap:3px}.tracking-preview-head strong{color:#0f172a}.tracking-preview-head span,.tracking-hint,.tracking-preview small{color:#64748b;font-size:12px}.tracking-hint{margin:8px 0}.tracking-preview-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.tracking-preview-table{width:100%;min-width:980px;border-collapse:collapse;font-size:12px}.tracking-preview-table th,.tracking-preview-table td{padding:7px 8px;border-bottom:1px solid #edf2f7;text-align:left;white-space:nowrap}.tracking-preview-table th{background:#f8fafc;color:#475569}.tracking-status{font-weight:800}.tracking-status.apply{color:#047857}.tracking-status.noop{color:#64748b}.tracking-status.blocked{color:#b91c1c}
.oversea-tabs{display:flex;gap:6px;align-items:center;margin:0 0 12px;border-bottom:1px solid #e2e8f0;overflow-x:auto}.oversea-tab{border:0;background:transparent;padding:9px 14px 8px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.oversea-tab span{margin-left:4px;color:#94a3b8;font-weight:600}.oversea-tab.active{color:#0f766e;border-bottom-color:#0f766e}.oversea-tab.active span{color:#0f766e}
.oversea-table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:white}.oversea-table{width:max-content;min-width:1760px;border-collapse:collapse;font-size:13px}.oversea-table th{padding:7px 8px;background:#f8fafc;color:#425466;font-weight:700;text-align:left;border-right:1px solid #e5eaee;border-bottom:1px solid #dfe6ea;white-space:nowrap}.oversea-table td{padding:3px 7px;border-right:1px solid #edf1f4;border-bottom:1px solid #e7ecef;vertical-align:middle;white-space:nowrap}.oversea-table tr:last-child td{border-bottom:0}.decision-col{width:118px}.line-col{width:44px;text-align:center!important;color:#64748b}.decision-cell{white-space:nowrap}.decision{border:1px solid transparent;border-radius:6px;padding:5px 9px;font-weight:700;margin-right:4px}.decision.approve{background:#0ca678;color:white}.decision.reject{background:white;color:#d33;border-color:#efb6b6}.decision-state{font-weight:700;color:#a16207}.decision-state.approved{color:#087f5b}.decision-approved{display:flex;flex-direction:column;align-items:flex-start;gap:2px}.reopen-review{border:1px solid #99cfc7;background:#fff;color:#0f766e;border-radius:6px;padding:2px 7px;font-size:11px;font-weight:700}
.cell-select,.note-input{width:100%;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:white;color:#334155;padding:3px 7px}.cell-select:disabled,.note-input:disabled{background:#f8fafc;color:#64748b}.cell-select.warehouse{min-width:135px}.carrier-cell{display:flex;flex-direction:column;gap:3px;min-width:150px}.carrier-cell small{color:#94a3b8;font-size:11px}.cell-select.carrier{min-width:145px}.tracking-cell{min-width:240px}.tracking-input{width:100%;min-width:220px;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:#fff;color:#334155;padding:3px 7px}.tracking-input:disabled{background:#f8fafc;color:#64748b}.note-cell{display:flex;flex-direction:column;gap:4px}.note-input{min-width:260px}.note-input.error{border-color:#dc2626}.note-error{color:#dc2626;font-size:11px}.order-link{display:inline-block;min-width:180px;color:#0f766e;font-weight:700;text-decoration:none;white-space:nowrap}.order-link:hover{text-decoration:underline}.items-cell{min-width:180px;color:#475569}.empty-cell{text-align:center!important;color:#94a3b8;padding:30px!important}
.oversea-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 2px;color:#64748b}.pagination{display:flex;gap:5px}.pagination button{border:1px solid #d7e0e5;background:white;border-radius:6px;min-width:32px;height:32px;color:#475569}.pagination button.active{background:#0f766e;border-color:#0f766e;color:white}
@media (max-width:900px){.oversea-workbench{grid-template-columns:1fr}.warehouse-rail{position:static;display:flex;gap:6px;overflow-x:auto}.warehouse-rail-title{display:none}.warehouse-link{width:auto;flex:0 0 auto}.oversea-heading{flex-direction:column}.oversea-file-actions{align-self:stretch}.file-button{flex:1}.oversea-page{padding:14px}}
</style>
