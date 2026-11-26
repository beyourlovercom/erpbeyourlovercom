<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const props = defineProps<{ canDownloadDatabase: boolean }>()
type Backup = { filename: string; size: number }
const files = ref<Backup[]>([])
const busy = ref(false)
const message = ref('')
const failed = ref(false)

function acceptFiles(value: unknown): Backup[] {
  if (!Array.isArray(value)) throw Error('BACKUP_LIST_INVALID')
  return value.filter((f): f is Backup => typeof f?.filename === 'string' && /^erp-db-\d{8}-\d{6}-\d{6}-[a-f0-9]{8}\.sql\.gz$/.test(f.filename) && Number.isFinite(f.size) && f.size > 0).slice(0, 3)
}
function dateLabel(filename: string) {
  const m = filename.match(/^erp-db-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-/)
  return m ? m[1]+'-'+m[2]+'-'+m[3]+' '+m[4]+':'+m[5]+':'+m[6] : filename
}
async function refresh() {
  const r = await fetch('/settings/backup/files', {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}})
  if (!r.ok) throw Error('BACKUP_LIST_FAILED')
  files.value = acceptFiles((await r.json()).files)
}
onMounted(async () => {
  if (!props.canDownloadDatabase) return
  try { await refresh() } catch { failed.value = true; message.value = '无法读取备份列表，请刷新页面。' }
})
async function createBackup() {
  if (!props.canDownloadDatabase || busy.value) return
  busy.value = true; failed.value = false; message.value = ''
  try {
    const r = await fetch('/settings/backup/create', {
      method:'POST', credentials:'same-origin', cache:'no-store',
      headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''},
    })
    if (!r.ok) throw Error('BACKUP_FAILED')
    const result = await r.json()
    if (result.success !== true) throw Error('BACKUP_UNCONFIRMED')
    const saved = acceptFiles(result.files)
    if (!saved.some(f => f.filename === result.backup?.filename)) throw Error('BACKUP_UNCONFIRMED')
    files.value = saved
    message.value = '数据库备份成功。已保存到服务器，可按需下载。'
  } catch {
    failed.value = true; message.value = '备份结果未确认，请检查下方列表。'
    try { await refresh() } catch { /* retain last known list */ }
  } finally { busy.value = false }
}
</script>

<template>
  <Head title="数据库备份 · BYL ERP" />
  <AuthenticatedLayout title="数据库备份" :breadcrumbs="['BYL ERP', '设置', '数据库备份']">
    <section class="mvp-page backup-page">
      <div class="mvp-page-heading"><div><h2>数据库备份</h2><p>备份 MySQL 全部表结构及业务数据，不包含网站代码。</p></div></div>
      <section class="mvp-panel backup-panel">
        <template v-if="props.canDownloadDatabase">
          <button type="button" class="erp-btn erp-btn-primary backup-button" :disabled="busy" data-testid="database-backup-create" @click="createBackup">
            {{ busy ? '正在备份，请勿关闭页面…' : '立即备份数据库' }}
          </button>
          <p v-if="message" :role="failed ? 'alert' : 'status'" :class="{ 'erp-field-error': failed, 'backup-success': !failed }">{{ message }}</p>
          <div v-if="files.length" class="backup-files">
            <h3>已保存的备份（北京时间）</h3>
            <div v-for="file in files" :key="file.filename" class="backup-file">
              <span>{{ dateLabel(file.filename) }} · {{ (file.size / 1000000).toFixed(2) }} MB</span>
              <a class="erp-btn" :href="'/settings/backup/file?filename='+encodeURIComponent(file.filename)" :download="file.filename">下载</a>
            </div>
          </div>
          <p v-else>暂无备份。</p>
        </template>
        <p v-else>仅管理员可备份及下载数据库。</p>
        <p class="backup-note">SQL.GZ · 服务器 /backup/ 最多保留 3 份 · 备份成功后无需下载即可继续发布。</p>
      </section>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.backup-page{display:grid;gap:var(--byl-space-20)}
.backup-panel{display:grid;justify-items:start;gap:var(--byl-space-12);padding:var(--byl-space-20);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}
.backup-panel p{margin:0}.backup-note{color:var(--byl-text-muted)}.backup-button{min-height:42px}.backup-button:disabled{opacity:.6;cursor:wait}
.backup-files{width:100%;max-width:650px}.backup-file{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--byl-border-subtle)}.backup-success{color:#16834b}
</style>
