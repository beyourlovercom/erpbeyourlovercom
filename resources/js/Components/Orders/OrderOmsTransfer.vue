<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

type PreviewRow = { row: number; order_number: string; customer: string; country: string; sku_detail: string; total_quantity: number }
type Problem = { row: number; message: string }
type Preview = { count: number; valid_count: number; errors: Problem[]; preview: PreviewRow[]; token: string | null }
const props = defineProps<{ exportUrl: string; canImport: boolean; total: number }>()
const csvUrl = computed(() => props.exportUrl + (props.exportUrl.includes('?') ? '&' : '?') + 'format=csv')
const open = ref(false)
const busy = ref(false)
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null)
const message = ref('')
const committed = ref<{ created: number; orders: Array<{ id: number; erp_order_id: string }> } | null>(null)
const canConfirm = computed(() => file.value !== null && preview.value?.errors.length === 0 && !!preview.value?.token && !busy.value)
const close = () => { if (busy.value) return; open.value = false }
const chooseFile = (event: Event) => {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null
  committed.value = null
  message.value = ''
}
const errorMessage = (error: unknown): string => {
  if (axios.isAxiosError(error)) {
    const response = error.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined
    const first = response?.errors ? Object.values(response.errors).flat()[0] : null
    return String(first ?? response?.message ?? '请求失败，请检查网络及权限')
  }
  return '请求失败，请检查文件内容'
}
async function inspect() {
  if (!file.value || busy.value) return
  busy.value = true
  preview.value = null
  message.value = ''
  const payload = new FormData()
  payload.append('file', file.value)
  try {
    const { data } = await axios.post<Preview>('/orders/oms/import/preview', payload)
    preview.value = data
    message.value = data.errors.length
      ? '预检发现 ' + data.errors.length + ' 项问题，请修改 XLSX 后重新上传。'
      : '预检通过：' + data.count + ' 个客户订单、不会修改现有订单。'
  } catch (error) { message.value = errorMessage(error) }
  finally { busy.value = false }
}
async function confirm() {
  if (!canConfirm.value || !file.value || !preview.value?.token) return
  if (!window.confirm('确定批量创建 ' + preview.value.count + ' 个真实 ERP 手动客户订单吗？该操作会产生订单编号及审计记录，无法通过取消导入直接撤销。')) return
  busy.value = true
  message.value = ''
  const payload = new FormData()
  payload.append('file', file.value)
  payload.append('token', preview.value.token)
  payload.append('confirm', '1')
  try {
    const { data } = await axios.post<{ created: number; orders: Array<{ id: number; erp_order_id: string }> }>('/orders/oms/import/confirm', payload)
    committed.value = data
    preview.value = null
    file.value = null
    message.value = '批量创建完成：' + data.created + ' 个订单。'
    router.reload({ only: ['orders'] })
  } catch (error) {
    message.value = errorMessage(error) + '。请重新上传并预检，确认之前不会继续创建。'
    preview.value = null
  } finally { busy.value = false }
}
</script>

<template>
  <div class="oms-transfer-actions">
    <button v-if="canImport" type="button" @click="open = true">导入</button>
    <a v-if="total <= 2000" :href="exportUrl" class="oms-transfer-export">导出 XLSX</a>
    <span v-else title="XLSX 单次最多导出 2000 单，请先筛选或使用 CSV" class="oms-export-limit">XLSX ≤2000</span>
    <a :href="csvUrl" class="oms-transfer-export">导出 CSV</a>
  </div>
  <Teleport to="body">
    <div v-if="open" class="oms-import-overlay" role="presentation" @click.self="close">
      <section class="oms-import-dialog" role="dialog" aria-modal="true" aria-label="导入订单" tabindex="-1">
        <header class="oms-import-head">
          <strong>批量导入客户订单</strong>
          <button type="button" aria-label="关闭导入弹窗" :disabled="busy" @click="close">×</button>
        </header>
        <main class="oms-import-body">
          <div class="oms-template-panel">
            <strong>模板 1 · 一行一个订单</strong>
            <p>每行一个客户订单，最多横向填写 5 款 SKU。黄色表头为必填，灰色可选。使用 Excel XLSX 文件，最多 200 个订单。</p>
            <div class="oms-import-file">
              <label class="oms-file-button" for="oms-grid-import-file">选择文件</label>
              <input id="oms-grid-import-file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="busy" @change="chooseFile" />
              <a href="/orders/oms/import/template" class="oms-template-link">下载导入模板 XLSX</a>
            </div>
            <small v-if="file">{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</small>
          </div>
          <p class="oms-import-disclaimer">上传后先预检：SKU 是否存在、商品数量、价格、地址、重复来源订单号均由 ERP 校验。<strong>预检不会创建订单。</strong></p>
          <div v-if="message" role="status" class="oms-import-status">{{ message }}</div>
          <div v-if="preview" class="oms-import-results">
            <strong>预检：共 {{ preview.count }} 单 · 通过 {{ preview.valid_count }} 单 · 错误 {{ preview.errors.length }} 项</strong>
            <ul v-if="preview.errors.length" class="oms-import-errors">
              <li v-for="(issue, i) in preview.errors.slice(0, 40)" :key="i">第 {{ issue.row }} 行：{{ issue.message }}</li>
            </ul>
            <div v-else class="oms-preview-table-wrap">
              <table class="oms-preview-table">
                <thead><tr><th>Excel 行</th><th>来源订单号</th><th>收件人</th><th>国家</th><th>SKU×数量</th><th>件数</th></tr></thead>
                <tbody><tr v-for="row in preview.preview.slice(0, 100)" :key="row.row"><td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.customer }}</td><td>{{ row.country }}</td><td>{{ row.sku_detail }}</td><td>{{ row.total_quantity }}</td></tr></tbody>
              </table>
              <small v-if="preview.count > 100">页面仅预览前 100 条；服务器已校验全部 {{ preview.count }} 条。</small>
            </div>
          </div>
          <div v-if="committed" class="oms-import-finished">
            <strong>已创建 {{ committed.created }} 个订单</strong>
            <p>首个 ERP 订单号：{{ committed.orders[0]?.erp_order_id ?? '—' }}</p>
          </div>
        </main>
        <footer class="oms-import-footer">
          <button type="button" :disabled="busy" @click="close">关闭</button>
          <button type="button" :disabled="!file || busy" @click="inspect">{{ busy ? '处理中...' : '上传并预检' }}</button>
          <button type="button" class="oms-import-commit" :disabled="!canConfirm" @click="confirm">确认创建 {{ preview?.count ?? 0 }} 单</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
<style scoped>
.oms-transfer-actions{display:flex;align-items:center;gap:7px;font-size:12px}
.oms-transfer-actions button,.oms-transfer-export{display:inline-flex;align-items:center;text-decoration:none;padding:6px 12px;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:5px;font-size:12px;cursor:pointer}
.oms-transfer-actions button:hover,.oms-transfer-export:hover{background:#eff6ff;color:#1d4ed8}
.oms-export-limit{font-size:11px;color:#64748b;white-space:nowrap}
.oms-import-overlay{position:fixed;inset:0;z-index:10500;background:#0f172a99;display:flex;align-items:center;justify-content:center;padding:20px}
.oms-import-dialog{background:white;border-radius:10px;box-shadow:0 12px 45px #0004;width:min(900px,95vw);max-height:90vh;display:flex;flex-direction:column;color:#1f2937}
.oms-import-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e5e7eb;padding:16px 24px;font-size:19px}
.oms-import-head button{font-size:25px;color:#64748b;border:0;background:transparent;cursor:pointer}
.oms-import-body{padding:24px;overflow:auto;display:flex;flex-direction:column;gap:14px;font-size:13px}
.oms-template-panel{border:1px solid #dbe0e8;border-radius:6px;padding:20px;max-width:640px;display:flex;flex-direction:column;gap:10px}
.oms-template-panel strong{font-size:17px}.oms-template-panel p{color:#64748b;font-size:12px;line-height:1.6;margin:0}
.oms-import-file{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.oms-file-button{cursor:pointer;background:#2252f5;color:#fff;padding:9px 16px;border-radius:5px}
.oms-import-file input{position:absolute;width:1px;height:1px;opacity:0}
.oms-template-link{color:#155dfb;text-decoration:none;font-weight:600}
.oms-import-disclaimer{color:#64748b;margin:0}.oms-import-status{background:#f1f5f9;padding:10px;border-radius:5px}
.oms-import-errors{max-height:140px;overflow:auto;color:#b42318;margin:6px 0}
.oms-preview-table-wrap{max-height:230px;overflow:auto;border:1px solid #e2e8f0}
.oms-preview-table{border-collapse:collapse;width:100%;font-size:12px}
.oms-preview-table th,.oms-preview-table td{white-space:nowrap;text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0}
.oms-preview-table th{position:sticky;top:0;background:#f1f5f9}
.oms-import-finished{background:#ecfdf5;padding:10px;border-radius:5px}
.oms-import-footer{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e5e7eb;padding:12px 24px}
.oms-import-footer button{padding:8px 14px;background:#fff;border:1px solid #cbd5e1;border-radius:5px;cursor:pointer}
.oms-import-footer button:disabled{opacity:.5;cursor:not-allowed}
.oms-import-footer .oms-import-commit{background:#155dfb;color:#fff;border-color:#155dfb}
</style>
