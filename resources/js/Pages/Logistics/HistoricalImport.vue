<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = { id:number; source_row_number:number; source_order_no:string|null; kol:{id:number;display_name:string;url:string}|null; carrier:string|null; tracking:string|null; tracking_raw:string|null; warehouse:string|null; classification:'ready'|'review'|'skip'; reason:string }
type Pagination = { current_page:number; last_page:number; per_page:number; total:number }
type Batch = { id:number; filename:string; checksum:string; sheet:string; reconciliation_batch_id:number|null; status:string; total_rows:number; ready_count:number; review_count:number; skip_count:number; rows:Row[]; pagination:Pagination }
const props = defineProps<{ batch: Batch|null; commitEnabled:boolean; canOperate:boolean }>()
const batch = ref<Batch|null>(props.batch)
const file = ref<File|null>(null)
const busy = ref(false)
const error = ref('')
watch(() => props.batch, (value) => { batch.value = value })
const choose = (event: Event) => { file.value = (event.target as HTMLInputElement).files?.[0] ?? null }
const preview = async () => {
  if (!file.value) return
  busy.value = true; error.value = ''
  const form = new FormData(); form.append('file', file.value)
  try { batch.value = (await axios.post('/logistics/historical-import/preview', form)).data.batch }
  catch (e:any) { error.value = e.response?.data?.message ?? '预览失败' }
  finally { busy.value = false }
}
const commitEvidence = async () => {
  if (!batch.value || !props.commitEnabled || !props.canOperate) return
  busy.value = true; error.value = ''
  try { batch.value = (await axios.post(`/logistics/historical-import/${batch.value.id}/commit`)).data.batch }
  catch (e:any) { error.value = e.response?.data?.message ?? '证据提交失败' }
  finally { busy.value = false }
}
const goToPage = (page:number) => {
  if (!batch.value || page < 1 || page > batch.value.pagination.last_page) return
  router.get('/logistics/historical-import', { page }, { preserveState: true, replace: true, preserveScroll: true })
}
const label = (value:string) => ({ ready:'Ready · 可提交', review:'Review · 需复核', skip:'Skip · 跳过' }[value] ?? value)
</script>

<template>
  <Head title="历史发货证据 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '历史发货证据']">
    <section class="mvp-page historical-import-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">Historical Shipment Evidence</p><h2>历史发货证据导入</h2><p>只保存历史 Carrier / Tracking 证据；不会创建订单，也不会修改当前 Shipment 或物流状态。</p></div>
        <div v-if="canOperate" class="mvp-actions">
          <label class="file-control"><input type="file" accept=".xlsx" :disabled="busy" @change="choose"><span>{{ file?.name ?? '选择 XLSX' }}</span></label>
          <button class="mvp-button mvp-button-primary" :disabled="!file || busy" @click="preview">{{ busy ? '处理中…' : '生成预览' }}</button>
          <button v-if="batch" class="mvp-button" :disabled="!commitEnabled || busy || batch.status === 'committed'" @click="commitEvidence">{{ batch.status === 'committed' ? '证据已提交' : '提交 Ready 证据' }}</button>
        </div>
      </div>
      <p v-if="error" class="mvp-error" role="alert">{{ error }}</p>

      <template v-if="batch">
        <div class="source-card">
          <div><small>Source</small><strong>{{ batch.filename }}</strong><span :title="batch.checksum">SHA-256 {{ batch.checksum.slice(0, 16) }}…</span></div>
          <div><small>精确 KOL 图谱</small><strong>{{ batch.reconciliation_batch_id ? `Batch #${batch.reconciliation_batch_id}` : '未解析' }}</strong><span>未解析时绝不猜测 KOL</span></div>
          <div><small>提交影响</small><strong>仅历史证据</strong><span>Active Shipment 保持不变</span></div>
        </div>
        <div class="evidence-summary">
          <div><small>Rows</small><strong>{{ batch.total_rows }}</strong></div>
          <div class="ready"><small>Ready</small><strong>{{ batch.ready_count }}</strong></div>
          <div class="review"><small>Review</small><strong>{{ batch.review_count }}</strong></div>
          <div class="skip"><small>Skip</small><strong>{{ batch.skip_count }}</strong></div>
          <div><small>Commit gate</small><strong>{{ commitEnabled ? 'ON · 测试环境' : 'OFF · 默认' }}</strong></div>
        </div>

        <div class="mvp-table-wrap"><table class="mvp-table">
          <thead><tr><th>源行</th><th>Order No.</th><th>KOL</th><th>原始 Carrier</th><th>Tracking</th><th>仓库</th><th>分类</th><th>原因</th></tr></thead>
          <tbody>
            <tr v-for="row in batch.rows" :key="row.id">
              <td>{{ row.source_row_number }}</td><td>{{ row.source_order_no ?? '—' }}</td>
              <td><Link v-if="row.kol" class="entity-link" :href="row.kol.url">#{{ row.kol.id }} {{ row.kol.display_name }}</Link><span v-else>未解析</span></td>
              <td>{{ row.carrier ?? '—' }}</td><td :title="row.tracking_raw ?? ''">{{ row.tracking ?? '—' }}</td><td>{{ row.warehouse ?? '—' }}</td>
              <td><span :class="`classification ${row.classification}`">{{ label(row.classification) }}</span></td><td>{{ row.reason }}</td>
            </tr>
            <tr v-if="!batch.rows.length"><td colspan="8" class="mvp-empty">没有可展示的来源行。</td></tr>
          </tbody>
        </table></div>
        <div v-if="batch.pagination.last_page > 1" class="pagination-bar">
          <span>每页 {{ batch.pagination.per_page }} 行 · {{ batch.pagination.total }} 条</span>
          <div><button class="mvp-button" :disabled="batch.pagination.current_page <= 1" @click="goToPage(batch.pagination.current_page - 1)">上一页</button><span>第 {{ batch.pagination.current_page }} / {{ batch.pagination.last_page }} 页</span><button class="mvp-button" :disabled="batch.pagination.current_page >= batch.pagination.last_page" @click="goToPage(batch.pagination.current_page + 1)">下一页</button></div>
        </div>
      </template>
      <div v-else class="mvp-card empty-state"><h3>等待 XLSX 预览</h3><p>读取范围固定为 Status、Warehouse、Carrier、Tracking、Input Time、Order No.、SKU、Quantity；地址、邮箱、电话和社交帐号不会进入历史物流证据。</p></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.file-control{display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid #cfdade;border-radius:7px;background:#fff;font-size:12px;cursor:pointer}.file-control input{max-width:170px;font-size:11px}.source-card{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin:18px 0 10px}.source-card>div,.evidence-summary>div{display:grid;gap:4px;padding:12px;border:1px solid #dce5e8;border-radius:8px;background:#fff}.source-card small,.evidence-summary small{color:#627780}.source-card span{font-size:11px;color:#71848b}.evidence-summary{display:grid;grid-template-columns:repeat(5,minmax(100px,1fr));gap:10px;margin-bottom:18px}.evidence-summary strong{font-size:17px}.ready strong{color:#166534}.review strong{color:#9a6700}.skip strong{color:#64748b}.classification{font-size:11px;font-weight:700}.classification.ready{color:#166534}.classification.review{color:#9a6700}.classification.skip{color:#64748b}.entity-link{font-weight:600}.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;font-size:12px;color:#627780}.pagination-bar>div{display:flex;align-items:center;gap:10px}.empty-state{margin-top:18px}@media(max-width:900px){.mvp-page-heading{display:grid;gap:14px}.mvp-actions{flex-wrap:wrap}.source-card,.evidence-summary{grid-template-columns:repeat(2,1fr)}.pagination-bar{align-items:flex-start;flex-direction:column}}
</style>
