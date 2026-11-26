<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const props = defineProps<{ canDownloadDatabase: boolean }>()
const busy = ref(false)
const message = ref('')
const failed = ref(false)

async function downloadBackup() {
  if (!props.canDownloadDatabase || busy.value) return
  busy.value = true
  failed.value = false
  message.value = ''
  try {
    const response = await fetch('/settings/backup/download', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json, application/gzip',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
    })
    if (!response.ok || !response.headers.get('content-type')?.includes('application/gzip')) {
      throw new Error('数据库备份未完成，请稍后重试。')
    }
    const filename = response.headers.get('content-disposition')?.match(/filename="?([a-zA-Z0-9.-]+\.sql\.gz)"?/)?.[1]
    if (!filename) throw new Error('备份下载响应异常，请重试。')
    const blob = await response.blob()
    if (blob.size === 0) throw new Error('备份文件为空，请重试。')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    message.value = '数据库备份成功，已发起浏览器下载。'
  } catch {
    failed.value = true
    message.value = '数据库备份或下载失败，请稍后重试。'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Head title="数据库备份 · BYL ERP" />
  <AuthenticatedLayout title="数据库备份" :breadcrumbs="['BYL ERP', '设置', '数据库备份']">
    <section class="mvp-page backup-page">
      <div class="mvp-page-heading"><div><h2>数据库备份</h2><p>备份 MySQL 全部表结构及业务数据，不包含网站代码。</p></div></div>
      <section class="mvp-panel backup-panel">
        <button v-if="props.canDownloadDatabase" type="button" class="erp-btn erp-btn-primary backup-button" :disabled="busy" data-testid="database-backup-download" @click="downloadBackup">
          {{ busy ? '正在备份，请勿关闭页面…' : '一键备份并下载到电脑' }}
        </button>
        <p v-else>仅管理员可备份及下载数据库。</p>
        <p class="backup-note">SQL.GZ · 服务器 /backup/ 最多保留 3 份 · 备份包含敏感业务数据，请妥善保管。</p>
        <p v-if="message" :role="failed ? 'alert' : 'status'" :class="{ 'erp-field-error': failed }">{{ message }}</p>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.backup-page{display:grid;gap:var(--byl-space-20)}
.backup-panel{display:grid;justify-items:start;gap:var(--byl-space-12);padding:var(--byl-space-20);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}
.backup-panel p{margin:0}.backup-note{color:var(--byl-text-muted)}.backup-button{min-height:42px}.backup-button:disabled{opacity:.6;cursor:wait}
</style>
