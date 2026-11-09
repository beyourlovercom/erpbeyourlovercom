<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import OrderWorkflowStatusPill from '../../../Components/Orders/OrderWorkflowStatusPill.vue'

type Item = { sku:string|null; quantity:number }
type Row = {
  line:number; order_id:number; erp_order_id:string|null; provider_reference:string|null; order_url:string; source_type?:string; tracking_sync_status?:string|null; tracking_sync_reason?:string|null; tracking_sync_verified_at?:string|null
  workflow_status_projection?:{ code:string; zh:string; en:string; color:string; source:string; mutable?:boolean }
  recipient_name:string|null; phone:string|null; country:string|null; state:string|null; city:string|null; postal_code:string|null
  carrier:string|null; service:string|null; tracking_number:string|null; shipment_status:string|null; shipped_at:string|null
  review_status:'approved'|'pending'|'stockout'|'conflict'; export_blockers:string[]; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; total:number }
type PreviewRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string; provider_status:string|null; status:string; message:string }
type Preview = { token:string; rows:PreviewRow[]; apply_count:number; noop_count:number; blocked_count:number }
type ProviderStatus = Record<'4px'|'weship',{export_ready:boolean;return_ready:boolean;label:string;note?:string;reason?:string}>

const props = defineProps<{
  view:string; rows:PageRows; viewCounts:Record<string,number>; canOperate:boolean; canReview:boolean; canTrackingSync?:boolean
  trackingImportProvider:'4px'|'weship'|'generic'|null; trackingImportPreview:Preview|null; providerStatus:ProviderStatus
}>()

const trackingSelection = ref<number[]>([])
const trackingBusy = ref(false)
const trackingFeedback = ref('')
const eligibleForTracking = (row:Row) => !!row.tracking_number?.trim() && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const eligibleForBatchTracking = (row:Row) => row.source_type === 'woocommerce'
  && row.shipment_status === 'shipped' && !!row.tracking_number && !!row.carrier && !!row.shipped_at
  && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const chooseTracking = (id:number, checked:boolean) => {
  trackingSelection.value = checked ? [...new Set([...trackingSelection.value, id])] : trackingSelection.value.filter(v=>v!==id)
}
const pushTracking = (ids:number[], manualOverride = false) => {
  if (!props.canTrackingSync || !ids.length || trackingBusy.value) return
  trackingBusy.value = true
  trackingFeedback.value = ''
  router.post('/logistics/tracking-outbound/selected', { order_ids:ids, manual_override:manualOverride }, {
    preserveScroll:true,
    onSuccess:(page)=>{
      trackingSelection.value=[]
      const flash = page.props.flash as {warning?:string;error?:string;success?:string}|undefined
      trackingFeedback.value = flash?.warning || flash?.error || flash?.success || '同步请求已处理，请核对官网同步状态'
    },
    onError:(errors)=>{trackingFeedback.value=Object.values(errors).join('；')||'官网同步请求验证未通过'},
    onFinish:()=>{trackingBusy.value=false},
  })
}
const trackingLabel = (status:string|null|undefined) => status==='completed'?'已同步':status==='failed'?'失败':status==='retry_wait'?'待重试':status==='processing'?'同步中':status==='partial'?'部分同步':status==='conflict'?'冲突待核对':'待同步'
// Saving a Tracking draft does not ship. Physical dispatch requires explicit confirmation.
const inlineTracking = ref<Record<number,string>>({})
const inlineBusy = ref<Record<number,boolean>>({})
const inlineErrors = ref<Record<number,string>>({})
const inlineSaved = ref<Record<number,string>>({})
const trackingDraft = (row:Row):string => inlineTracking.value[row.order_id] ??= row.tracking_number ?? ''
const canQuickShip = (row:Row):boolean => props.canOperate && row.review_status === 'approved'
  && row.shipment_status === 'pending'
const trackingTextChanged = (row:Row) => trackingDraft(row).trim() !== (row.tracking_number ?? '')
const inlineTrackingInput = (row:Row, event:Event) => {
  inlineTracking.value[row.order_id] = (event.target as HTMLInputElement).value
  inlineErrors.value[row.order_id] = ''
  inlineSaved.value[row.order_id] = ''
}
const saveInlineTracking = (row:Row) => {
  if (!canQuickShip(row) || inlineBusy.value[row.order_id] || !trackingTextChanged(row)) return
  inlineBusy.value[row.order_id] = true
  inlineErrors.value[row.order_id] = ''
  const tracking = trackingDraft(row).trim()
  router.post('/logistics/china/orders/' + row.order_id + '/tracking-draft', {tracking_number:tracking || null}, {
    preserveScroll:true, preserveState:true,
    onSuccess:()=>{ inlineSaved.value[row.order_id] = tracking ? '已暂存' : '已清空' },
    onError:(errors)=>{ inlineErrors.value[row.order_id] = errors.tracking_number ?? '保存失败，请核对单号和审核状态。' },
    onFinish:()=>{ inlineBusy.value[row.order_id] = false },
  })
}
const confirmInlineShip = (row:Row) => {
  if (!canQuickShip(row) || inlineBusy.value[row.order_id]) return
  const tracking = trackingDraft(row).trim()
  if (!tracking) { inlineErrors.value[row.order_id] = '请先填写真实发货单号。'; return }
  inlineBusy.value[row.order_id] = true
  inlineErrors.value[row.order_id] = ''
  router.post('/logistics/china/orders/' + row.order_id + '/quick-ship', {tracking_number:tracking}, {
    preserveScroll:true, preserveState:true,
    onError:(errors)=>{ inlineErrors.value[row.order_id] = errors.tracking_number ?? '确认发货失败，请核对审核状态。' },
    onFinish:()=>{ inlineBusy.value[row.order_id] = false },
  })
}
const uploadInput = ref<HTMLInputElement|null>(null)
const selectedProvider = ref<'4px'|'weship'|'generic'>('4px')
const tabs = [
  ['review','待审核'], ['stockout','缺货单'], ['pending','待发货'], ['today-4px','今日速达'], ['today-weship','今日纬狮'],
  ['exceptions','异常订单'], ['today','今日订单'], ['4px','速达订单'], ['weship','纬狮订单'],
] as const
const previewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 30) ?? [])
const itemText = (row:Row) => row.items.map(item => (item.sku ?? '无SKU') + ' × ' + item.quantity).join(' / ') || '—'
const europeanCountries = new Set(['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','GB','UK','NO','CH','IS','LI','AD','MC','SM','VA','AL','BA','ME','MK','RS','MD','UA','TR'])
const barcodePreview = ref<{orderId:number; items:{sku:string;quantity:number;barcode_69:string}[]}|null>(null)
const barcodeError = ref('')
const barcodeErrorOrderId = ref<number|null>(null)
const barcodeLoading = ref(false)
const showBarcode = async (row:Row) => {
  barcodePreview.value = null
  barcodeError.value = ''
  barcodeErrorOrderId.value = row.order_id
  barcodeLoading.value = true
  try {
    const response = await fetch('/logistics/china/orders/' + row.order_id + '/barcode-69', { headers:{Accept:'application/json'} })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || '条码不存在或订单不符合条件')
    barcodePreview.value = { orderId:row.order_id, items:data.items }
  } catch (error) {
    barcodeError.value = error instanceof Error ? error.message : '无法读取69条形码'
  } finally { barcodeLoading.value = false }
}

const switchView = (view:string) => router.visit('/logistics/china?view=' + view, { preserveScroll:true, preserveState:true })
const reviewBusy = ref(false)
const reviewError = ref('')
const rejectTarget = ref<Row|null>(null)
const rejectReason = ref('')
const decide = (row:Row, action:'approve'|'stockout') => {
  if (reviewBusy.value || !props.canReview) return
  reviewBusy.value = true
  router.post('/logistics/china/orders/' + row.order_id + '/' + action, {}, {
    preserveScroll:true, preserveState:true, onFinish:()=>{reviewBusy.value=false},
  })
}
const reopen = (row:Row) => router.post('/logistics/china/orders/' + row.order_id + '/reopen-review', {}, { preserveScroll:true, preserveState:true })
const openReject = (row:Row) => { rejectTarget.value=row; rejectReason.value=''; reviewError.value='' }
const submitReject = () => {
  if (!rejectTarget.value || reviewBusy.value || !props.canReview) return
  if (rejectReason.value.trim().length<3) { reviewError.value='请填写至少 3 个字的驳回原因。'; return }
  reviewBusy.value = true
  router.post('/logistics/china/orders/' + rejectTarget.value.order_id + '/reject',
    {reason:rejectReason.value.trim()}, {
      preserveScroll:true, preserveState:true,
      onSuccess:()=>{rejectTarget.value=null;rejectReason.value=''},
      onError:(errors)=>{reviewError.value=errors.reason??'驳回失败，请核对订单状态。'},
      onFinish:()=>{reviewBusy.value=false},
    })
}
const chooseUpload = (provider:'4px'|'weship'|'generic') => { selectedProvider.value=provider; uploadInput.value?.click() }
const previewFile = (event:Event) => {
  const input=event.target as HTMLInputElement
  const file=input.files?.[0]; if(!file) return
  const data=new FormData(); data.append('file',file)
  const url=selectedProvider.value==='generic' ? '/logistics/china/pending-batch/tracking-import/preview' : '/logistics/china/' + selectedProvider.value + '/tracking-import/preview'
  router.post(url, data, { forceFormData:true, preserveScroll:true, onFinish:()=>{ input.value='' } })
}
const applyPreview = () => {
  if(!props.trackingImportPreview || !props.trackingImportProvider) return
  const url=props.trackingImportProvider==='generic' ? '/logistics/china/pending-batch/tracking-import/apply' : '/logistics/china/' + props.trackingImportProvider + '/tracking-import/apply'
  router.post(url, { token:props.trackingImportPreview.token }, { preserveScroll:true })
}
const statusLabel = (status:string) => status==='apply' ? '可应用' : status==='noop' ? '重复跳过' : status==='applied' ? '已应用' : '阻断'
const previewLabel = computed(() => props.trackingImportProvider==='generic' ? '待发货批量登记' : props.trackingImportProvider ? props.providerStatus[props.trackingImportProvider].label : '')
</script>

<template>
  <Head title="国内发货 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','国内发货']">
    <section class="domestic-page">
      <header class="domestic-head">
        <div>
          <p class="eyebrow">FACTORY · 中国工厂</p>
          <h1>国内发货</h1>
          <p>Vita 分仓 → Kaven / 运营确认有货 → Liang 发货 → 下载承运商文件 → 回传 Tracking → Preview → Apply。单个异常订单不会阻断其他正常订单。</p>
        </div>
        <div class="domestic-head-actions">
          <a class="secondary" href="/logistics/wait-stock">待分配 →</a>
        </div>
      </header>

      <section class="continuity">
        <strong>生产连续性</strong>
        <span>正常订单继续发货；缺电话 / 单号冲突 / Tracking 冲突只阻断对应订单，不阻断整批。</span>
      </section>

      <nav class="tabs">
        <button v-for="([key,label]) in tabs" :key="key" type="button" :class="{active:view===key}" @click="switchView(key)">
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <section v-if="view==='pending'" class="pending-batch-card">
        <div>
          <strong>待发货 · 批量登记</strong>
          <small>ERP 通用 XLSX，可混合承运商。下载后填写 Tracking 再上传；仅用于 ERP 发货登记，不替代承运商自己的下单模板。</small>
        </div>
        <div class="actions">
          <a class="primary" href="/logistics/china/pending-batch/export">下载待发货批量表</a>
          <button v-if="canOperate" type="button" class="secondary" @click="chooseUpload('generic')">上传批量单号表</button>
        </div>
      </section>

      <section v-if="view==='today' || view==='today-4px' || view==='today-weship'" class="provider-grid" :class="{single:view==='today-4px' || view==='today-weship'}">
        <article v-if="view!=='today-weship'" class="provider-card">
          <div><strong>速达 / 4PX</strong><small>新版 27 列模板 · 所有订单按现有数据填写 SKU / 69条码（空缺留空） · XLSX 下载 · XLS/XLSX 回传</small></div>
          <div class="actions">
            <a v-if="view==='today'" class="secondary" href="/logistics/china/today/export" data-testid="download-today-orders" title="按今日订单筛选导出，每个 SKU 独立一行">下载今日订单</a>
            <a class="primary" href="/logistics/china/export/4px">下载速达下单表</a>
            <button v-if="canOperate" type="button" class="secondary" @click="chooseUpload('4px')">上传速达单号表</button>
          </div>
        </article>
        <article v-if="view!=='today-4px'" class="provider-card">
          <div><strong>纬狮 / Weship</strong><small>{{ providerStatus.weship.export_ready ? (providerStatus.weship.note ?? '下单导出可用') : providerStatus.weship.reason }}</small></div>
          <div class="actions">
            <a v-if="providerStatus.weship.export_ready" class="primary" href="/logistics/china/export/weship">下载纬狮下单表</a>
            <button v-if="canOperate && providerStatus.weship.return_ready" type="button" class="secondary" @click="chooseUpload('weship')">上传纬狮单号表</button>
          </div>
        </article>
        <input ref="uploadInput" class="hidden" type="file" accept=".xls,.xlsx" @change="previewFile" />
      </section>

      <section v-if="trackingImportPreview && trackingImportProvider" class="preview">
        <div class="preview-head">
          <div><strong>{{ previewLabel }} · 回传预览</strong><span>可应用 {{ trackingImportPreview.apply_count }} · 重复 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span></div>
          <button v-if="canOperate && trackingImportPreview.apply_count>0" class="primary" type="button" @click="applyPreview">应用可安全行并标记发货</button>
        </div>
        <p>即使存在阻断行，也只跳过阻断行；可安全行仍会逐单提交 Shipment，并逐单尝试 Woo Tracking 同步。</p>
        <div class="table-wrap">
        <div v-if="trackingFeedback" class="tracking-action-feedback" role="status">{{ trackingFeedback }}</div>
          <table><thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>Provider 状态</th><th>结果</th><th>说明</th></tr></thead>
          <tbody><tr v-for="row in previewRows" :key="row.row + '-' + row.order_number">
            <td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number || '—' }}</td><td>{{ row.carrier }}</td><td>{{ row.provider_status ?? '—' }}</td>
            <td><b :class="'status-' + row.status">{{ statusLabel(row.status) }}</b></td><td>{{ row.message || '—' }}</td>
          </tr></tbody></table>
        </div>
      </section>

      <div v-if="canTrackingSync && trackingSelection.length" class="tracking-selection-bar">
        已选 {{ trackingSelection.length }} 项
        <button type="button" class="primary" :disabled="trackingBusy" @click="pushTracking(trackingSelection)">批量同步官网物流</button>
        <button type="button" class="secondary" @click="trackingSelection=[]">清除选择</button>
      </div>
      <div class="table-wrap orders">
        <table>
          <thead><tr><th v-if="canTrackingSync">选择</th><th>#</th><th>审核</th><th>ERP 订单</th><th>订单状态</th><th>导出状态</th><th>承运商单号</th><th>Carrier</th><th>服务</th><th>Tracking</th><th>收件人</th><th>电话</th><th>国家/地区</th><th v-if="view==='review'">SKU</th><th v-if="view==='review'">数量</th><th v-else>SKU / 数量</th><th>官网同步</th><th v-if="canTrackingSync">操作</th></tr></thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.order_id">
              <td v-if="canTrackingSync"><input v-if="eligibleForBatchTracking(row)" type="checkbox"
                :aria-label="'选择同步 '+(row.erp_order_id??row.order_id)" :checked="trackingSelection.includes(row.order_id)"
                @change="chooseTracking(row.order_id, ($event.target as HTMLInputElement).checked)" /></td>
              <td>{{ row.line }}</td>
              <td>
                <div v-if="canReview && (view==='review' || view==='stockout') && row.shipment_status==='pending'" class="review-actions">
                  <button class="approve" type="button" :disabled="reviewBusy" @click="decide(row,'approve')">通过</button>
                  <button v-if="row.review_status!=='stockout'" class="stockout-btn" type="button" :disabled="reviewBusy" @click="decide(row,'stockout')">缺货</button>
                  <button class="reject-btn" type="button" :disabled="reviewBusy" @click="openReject(row)">驳回</button>
                </div>
                <div v-else-if="row.review_status==='approved'" class="approved">已通过 <button v-if="canReview && row.shipment_status==='pending'" type="button" @click="reopen(row)">重新审核</button></div>
                <span v-else :class="row.review_status==='conflict' ? 'danger' : ''">{{ row.review_status==='conflict' ? '状态待对账' : row.review_status==='stockout' ? '缺货' : '待审核' }}</span>
              </td>
              <td><a :href="row.order_url">{{ row.erp_order_id ?? '—' }}</a></td><td><OrderWorkflowStatusPill v-if="row.workflow_status_projection" :order-id="row.order_id" :projection="row.workflow_status_projection" :can-edit="false" /><span v-else>—</span></td>
              <td><span v-if="row.export_blockers.length" class="danger">{{ row.export_blockers.join(' / ') }}</span><span v-else class="ready">可导出</span></td>
              <td>{{ row.provider_reference ?? '—' }}</td><td>{{ row.carrier ?? '—' }}</td><td>{{ row.service ?? '—' }}</td>
              <td class="domestic-tracking-cell">
                <div v-if="canQuickShip(row)" class="domestic-tracking-entry">
                  <input :value="trackingDraft(row)" :aria-label="'填写发货单号 '+(row.erp_order_id ?? row.order_id)"
                    type="text" maxlength="128" class="domestic-tracking-input" placeholder="填写发货单号"
                    :disabled="!!inlineBusy[row.order_id]"
                    @input="inlineTrackingInput(row,$event)"
                    @blur="saveInlineTracking(row)" @keyup.enter="($event.target as HTMLInputElement).blur()" />
                  <button type="button" class="secondary" :disabled="!!inlineBusy[row.order_id] || !trackingTextChanged(row)"
                    @mousedown.prevent @click="saveInlineTracking(row)">暂存</button>
                  <button type="button" class="primary" :disabled="!!inlineBusy[row.order_id] || !trackingDraft(row).trim()"
                    @mousedown.prevent @click="confirmInlineShip(row)">确认发货</button>
                </div>
                <span v-else>{{ row.tracking_number || (row.review_status==='pending' ? '待审核' : row.review_status==='stockout' ? '缺货' : row.review_status==='conflict' ? '状态待对账' : '—') }}</span>
                <small v-if="inlineErrors[row.order_id]" class="domestic-tracking-error">{{ inlineErrors[row.order_id] }}</small>
                <small v-else-if="inlineBusy[row.order_id]" class="domestic-tracking-note">处理中…</small>
                <small v-else-if="inlineSaved[row.order_id]" class="domestic-tracking-note">{{ inlineSaved[row.order_id] }} · 尚未发货</small>
              </td>
              <td>{{ row.recipient_name ?? '—' }}</td><td :class="{danger:!row.phone}">{{ row.phone || '缺电话' }}</td>
              <td>{{ [row.country,row.state,row.city].filter(Boolean).join(' / ') || '—' }}</td><td v-if="view==='review'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.sku ?? '无SKU' }}</div><button v-if="europeanCountries.has((row.country ?? '').toUpperCase())" type="button" class="secondary" :disabled="barcodeLoading" @click="showBarcode(row)">69条码</button><div v-if="barcodePreview?.orderId===row.order_id" class="barcode-preview"><div v-for="item in barcodePreview.items" :key="item.sku">{{ item.sku }} × {{ item.quantity }}：{{ item.barcode_69 }}</div></div><small v-if="barcodeError && barcodeErrorOrderId===row.order_id && !barcodeLoading" class="danger">{{ barcodeError }}</small></td><td v-if="view==='review'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.quantity }}</div></td><td v-else>{{ itemText(row) }} <button v-if="europeanCountries.has((row.country ?? '').toUpperCase())" type="button" class="secondary" :disabled="barcodeLoading" @click="showBarcode(row)">69条码</button><div v-if="barcodePreview?.orderId===row.order_id" class="barcode-preview"><div v-for="item in barcodePreview.items" :key="item.sku">{{ item.sku }} × {{ item.quantity }}：{{ item.barcode_69 }}</div></div><small v-if="barcodeError && barcodeErrorOrderId===row.order_id && !barcodeLoading" class="danger">{{ barcodeError }}</small></td>
              <td><span v-if="row.source_type==='woocommerce'" class="sync-status" :title="[row.tracking_sync_reason, row.tracking_sync_verified_at ? '最近官网验证：' + new Date(row.tracking_sync_verified_at).toLocaleString('zh-CN', { hour12:false }) : '尚无官网验证记录'].filter(Boolean).join('；')" :class="row.tracking_sync_status??'pending'">{{ trackingLabel(row.tracking_sync_status) }}</span><span v-else>—</span><small v-if="row.tracking_sync_reason" class="sync-failure">{{ row.tracking_sync_reason }}</small></td>
              <td v-if="canTrackingSync"><button v-if="eligibleForTracking(row)" type="button" class="secondary"
                :disabled="trackingBusy" @click="pushTracking([row.order_id], true)">{{ (['failed','retry_wait'].includes(row.tracking_sync_status ?? '') || !!row.tracking_sync_reason) ? '重新同步' : '同步官网物流' }}</button><span v-else>—</span></td>
            </tr>
            <tr v-if="!rows.data.length"><td :colspan="(view==='review'?15:14)+(canTrackingSync?2:0)" class="empty">当前视图暂无订单</td></tr>
          </tbody>
        </table>
      </div>
      <footer>共 {{ rows.total }} 条</footer>
      <div v-if="rejectTarget" class="review-reject-backdrop" @click.self="rejectTarget=null">
        <section class="review-reject-dialog" role="dialog" aria-modal="true" aria-label="驳回国内订单">
          <h2>驳回到 Vita 待分仓</h2>
          <p>订单 {{ rejectTarget.erp_order_id }} 将撤销当前工厂分配。驳回原因会显示在订单详情及待分仓列表中。</p>
          <div class="reason-shortcuts">
            <button v-for="reason in ['缺少收件信息','收件人地址有误','税号不匹配','其他信息需核对']" :key="reason" type="button" class="secondary" @click="rejectReason=reason">{{ reason }}</button>
          </div>
          <label for="domestic-reject-reason">驳回原因（必填）</label>
          <textarea id="domestic-reject-reason" v-model="rejectReason" rows="3" maxlength="500" placeholder="请写清需要 Vita 修改或重新确认的内容"></textarea>
          <small v-if="reviewError" class="danger">{{ reviewError }}</small>
          <div class="actions">
            <button class="secondary" type="button" :disabled="reviewBusy" @click="rejectTarget=null">取消</button>
            <button class="reject-btn" type="button" :disabled="reviewBusy || rejectReason.trim().length<3" @click="submitReject">{{ reviewBusy ? '处理中…' : '确认驳回' }}</button>
          </div>
        </section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.domestic-page{padding:20px;min-width:0;color:#111827}.domestic-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.eyebrow{margin:0;color:#0f766e;font-size:11px;font-weight:800;letter-spacing:.08em}.domestic-head h1{margin:4px 0;font-size:30px}.domestic-head p{margin:0;color:#64748b;font-size:13px}.continuity{display:flex;gap:12px;align-items:center;margin:14px 0;padding:10px 12px;border:1px solid #b7ded8;border-radius:9px;background:#f2fbf9;color:#315f59;font-size:12px}.continuity strong{color:#0f766e}.tabs{display:flex;gap:5px;overflow-x:auto;border-bottom:1px solid #e2e8f0;margin-bottom:12px}.tabs button{border:0;background:transparent;padding:9px 12px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.tabs button.active{color:#0f766e;border-bottom-color:#0f766e}.tabs span{color:#94a3b8;margin-left:3px}.pending-batch-card{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px;padding:12px;border:1px solid #b7ded8;border-radius:10px;background:#f2fbf9}.pending-batch-card>div:first-child{display:flex;flex-direction:column;gap:4px}.pending-batch-card small{color:#64748b;max-width:760px}
.domestic-head-actions{display:flex;gap:8px;align-items:center}
.domestic-tracking-cell{min-width:355px}.domestic-tracking-entry{display:flex;gap:5px;align-items:center}.domestic-tracking-input{min-width:155px;width:175px;height:34px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;background:white;color:#1e293b}.domestic-tracking-entry button{white-space:nowrap;min-height:32px;padding:0 8px}.domestic-tracking-error,.domestic-tracking-note{display:block;font-size:11px;margin-top:4px;white-space:normal}.domestic-tracking-error{color:#b91c1c}.domestic-tracking-note{color:#0f766e}
.tracking-selection-bar{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:8px 12px;background:#eef6ff;border:1px solid #c9dffc;border-radius:8px;font-size:12px}
button:disabled{opacity:.5;cursor:not-allowed}
.sync-status{font-size:11px;font-weight:700;padding:4px 7px;border-radius:5px;background:#fff1d6;color:#92400e}
.sync-status.completed{background:#dcfce7;color:#166534}.sync-status.failed{background:#fee2e2;color:#991b1b}.sync-status.retry_wait{background:#fef3c7;color:#92400e}.sync-status.processing{background:#dbeafe;color:#1d4ed8}.sync-failure{display:block;max-width:240px;white-space:normal;color:#b91c1c;font-size:10px;margin-top:4px}
.provider-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}.provider-grid.single{grid-template-columns:1fr}.provider-card{display:flex;justify-content:space-between;gap:10px;padding:12px;border:1px solid #dbe4e8;border-radius:10px;background:#fff}.provider-card>div:first-child{display:flex;flex-direction:column;gap:4px}.provider-card small{color:#64748b}.actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.primary,.secondary,.approve,.approved button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 11px;border-radius:7px;font-size:12px;font-weight:800;text-decoration:none}.primary,.approve{border:1px solid #0f8f82;background:#0f8f82;color:#fff}.secondary,.approved button{border:1px solid #b8c6cc;background:#fff;color:#334155}.hidden{display:none}.preview{margin-bottom:12px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.preview-head{display:flex;justify-content:space-between;gap:12px}.preview-head>div{display:flex;flex-direction:column}.preview p{color:#64748b;font-size:12px}.table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:#fff}.table-wrap table{width:max-content;min-width:100%;border-collapse:collapse;font-size:12px}.table-wrap th,.table-wrap td{padding:8px;border-bottom:1px solid #e9eef1;text-align:left;white-space:nowrap}.table-wrap th{background:#f8fafc;color:#475569}.orders table{min-width:1500px}.orders a{color:#0f766e;font-weight:800;text-decoration:none}.approved{display:flex;gap:6px;align-items:center;color:#047857;font-weight:800}.approved button{min-height:26px;padding:0 7px;font-size:10px}.danger{color:#b91c1c;font-weight:800}.ready{color:#047857;font-weight:800}.empty{text-align:center!important;padding:30px!important;color:#94a3b8}.status-apply{color:#047857}.status-noop{color:#64748b}.status-blocked{color:#b91c1c}footer{padding:10px 2px;color:#64748b;font-size:12px}
@media(max-width:800px){.domestic-page{padding:14px}.domestic-head{flex-direction:column}.pending-batch-card{flex-direction:column}.provider-grid{grid-template-columns:1fr}.provider-card{flex-direction:column}.continuity{align-items:flex-start;flex-direction:column}.preview-head{flex-direction:column}}

.review-actions{display:flex;align-items:center;gap:5px}.stockout-btn,.reject-btn{border:1px solid #e3ac78;background:#fff9f1;color:#9a3412;border-radius:6px;min-height:30px;padding:0 8px;font-size:12px;font-weight:800}.reject-btn{border-color:#efb2b2;background:#fff;color:#b42318}.review-reject-backdrop{position:fixed;inset:0;z-index:1000;background:rgba(16,24,40,.5);display:flex;align-items:center;justify-content:center;padding:16px}.review-reject-dialog{background:#fff;width:min(480px,100%);border-radius:12px;padding:20px;box-shadow:0 24px 80px rgba(16,24,40,.2);display:grid;gap:12px}.review-reject-dialog h2{margin:0;font-size:19px}.review-reject-dialog p{margin:0;font-size:13px;color:#64748b}.review-reject-dialog label{font-size:12px;font-weight:700}.review-reject-dialog textarea{width:100%;padding:10px;border:1px solid #d4dae1;border-radius:7px;font-size:13px}.review-reject-dialog .actions{justify-content:flex-end}.reason-shortcuts{display:flex;flex-wrap:wrap;gap:6px}
</style>
