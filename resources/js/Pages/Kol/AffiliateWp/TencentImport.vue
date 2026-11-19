<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Row = {
  source_row_number: number
  identity: Record<string, unknown>
  kol: { id: number; display_name: string } | null
  affiliate_account_id: number | null
  status: string
  message: string
}
type Batch = {
  id: number; original_filename: string; checksum: string
  total_rows: number; distinct_source_identities: number
  summary: Record<string, number>; rows: Row[]
}
const props = defineProps<{ batches: Array<{ id: number; original_filename: string; total_rows: number; created_at: string }> }>()
const file = ref<File | null>(null)
const sheetName = ref('网红-KOL')
const emailColumn = ref('Contact Email')
const profileColumn = ref('Influencer`s Link/URL')
const platformColumn = ref('')
const handleColumn = ref('')
const ownerColumn = ref('')
const ownerConfirmed = ref(false)
const preview = ref<Batch | null>(null)
const busy = ref(false)
const error = ref('')
const selectFile = (event: Event) => {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  error.value = ''
}
const upload = async () => {
  if (!file.value) { error.value = '请先选择 XLSX 文件'; return }
  if (!emailColumn.value.trim() && !profileColumn.value.trim()) { error.value = '必须选择邮箱或社交链接列'; return }
  if (ownerColumn.value.trim() && !ownerConfirmed.value) { error.value = '请先确认所选列是网红业务负责人，不是物流负责人'; return }
  busy.value = true; error.value = ''
  const data = new FormData()
  data.append('file', file.value)
  data.append('sheet_name', sheetName.value.trim())
  data.append('email_column', emailColumn.value.trim())
  data.append('profile_url_column', profileColumn.value.trim())
  data.append('platform_column', platformColumn.value.trim())
  data.append('handle_column', handleColumn.value.trim())
  data.append('owner_column', ownerColumn.value.trim())
  data.append('owner_confirmed', ownerConfirmed.value ? '1' : '0')
  try { preview.value = (await axios.post('/kol/affiliatewp/tencent-import/preview', data)).data.batch }
  catch (exception: any) {
    error.value = exception.response?.data?.message ?? '上传预览失败，请核对工作表及列名'
  } finally { busy.value = false }
}
const statusLabel = (value: string) => ({
  matched_kol_only: '已匹配 KOL', ready_for_binding: '待审核绑定',
  already_bound: '已绑定', no_affiliate: '无 Affiliate',
  unresolved: '待识别', conflict: '冲突，禁止自动执行',
}[value] ?? value)
</script>
<template>
  <Head title="腾讯网红表导入 · BYL ERP" />
  <AuthenticatedLayout title="腾讯网红表导入" :breadcrumbs="['BYL ERP', '网红', 'AffiliateWP', '腾讯网红表导入']">
    <section class="erp-form-page"><div class="erp-form-content">
      <div class="erp-form-header-row">
        <div><h1 class="erp-form-title">腾讯网红表导入</h1><p class="erp-form-subtitle">仅管理员 · XLSX 本地解析 · 精确匹配与人工审核</p></div>
        <div class="erp-form-header-actions page-header-actions">
          <a class="erp-btn erp-btn-secondary" href="/kol/affiliatewp">返回 AffiliateWP</a>
          <a class="erp-btn erp-btn-secondary" href="/kol/import-preview">KOL 匹配审核</a>
          <button class="erp-btn erp-btn-primary" type="button" :disabled="busy || !file" @click="upload">{{ busy ? '检查中…' : '生成安全预览' }}</button>
        </div>
      </div>
      <div class="erp-form-card">
        <h2>文件与工作表</h2>
        <label class="import-drop-zone"><input class="sr-only" type="file" accept=".xlsx" @change="selectFile" /><strong>选择腾讯文档导出的 XLSX</strong><small>{{ file?.name ?? '未选择文件' }} · 64 MiB / 5,000 行上限</small></label>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <label>工作表名称 <input v-model="sheetName" class="erp-input" required maxlength="120" /></label>
          <label>邮箱列名 <input v-model="emailColumn" class="erp-input" maxlength="191" /></label>
          <label>社交主页链接列名 <input v-model="profileColumn" class="erp-input" maxlength="191" /></label>
          <label>社交平台列名（可选） <input v-model="platformColumn" class="erp-input" maxlength="191" /></label>
          <label>账号 Handle 列名（可选） <input v-model="handleColumn" class="erp-input" maxlength="191" /></label>
          <label>网红业务负责人列名（可选） <input v-model="ownerColumn" class="erp-input" maxlength="191" /></label>
        </div>
        <label class="mt-3 flex items-start gap-2"><input v-model="ownerConfirmed" type="checkbox" /><span>我已核实所选负责人是网红业务负责人，不是物流跟单/发货负责人。未确认时不得导入此字段。</span></label>
        <p v-if="error" class="erp-error" role="alert">{{ error }}</p>
      </div>
      <div v-if="preview" class="erp-form-card">
        <h2>安全预览 #{{ preview.id }} · {{ preview.total_rows }} 行 · {{ preview.distinct_source_identities }} 个来源身份</h2>
        <p>只保存映射后的网红身份和经确认的负责人字段；原始物流文件不保留，订单/Referral/WWW 不写入。</p>
        <div class="mvp-table-wrap"><table class="mvp-table">
          <thead><tr><th>来源行</th><th>身份</th><th>ERP KOL</th><th>Affiliate 快照</th><th>状态</th></tr></thead>
          <tbody><tr v-for="row in preview.rows" :key="row.source_row_number">
            <td>{{ row.source_row_number }}</td><td><code>{{ JSON.stringify(row.identity) }}</code></td>
            <td>{{ row.kol?.display_name ?? '待创建/复核' }}</td><td>{{ row.affiliate_account_id ?? '—' }}</td><td>{{ statusLabel(row.status) }}</td>
          </tr></tbody>
        </table></div>
        <div class="erp-form-header-actions page-header-actions">
          <a class="erp-btn erp-btn-secondary" href="/kol/import-preview">下一步：聚合 KOL 候选</a>
          <a class="erp-btn erp-btn-secondary" href="/kol/owner-mapping">负责人审核</a>
          <a class="erp-btn erp-btn-secondary" href="/kol/import-activation">创建计划</a>
          <a class="erp-btn erp-btn-secondary" href="/kol/affiliatewp/binding-activation">Affiliate 绑定计划</a>
        </div>
      </div>
      <div v-if="props.batches.length" class="erp-form-card"><h2>最近的管理员导入批次</h2>
        <p v-for="batch in props.batches" :key="batch.id">#{{ batch.id }} · {{ batch.original_filename }} · {{ batch.total_rows }} 行</p>
      </div>
      <p class="erp-form-subtitle">审核说明：每个新 KOL 必须先确认唯一身份及真实业务负责人；冲突、无负责人或无法核实的记录保持待审核，不执行创建和绑定。</p>
    </div></section>
  </AuthenticatedLayout>
</template>
