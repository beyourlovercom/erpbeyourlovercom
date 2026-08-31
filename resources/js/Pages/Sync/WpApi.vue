<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Run = {
  id:number; status:string; started_at:string|null; completed_at:string|null;
  succeeded_items:number; failed_items:number; conflict_items:number
}
type Connection = {
  configured:boolean; url:string; connection_key:string; key_id:string;
  verify_tls:boolean; secret_set:boolean
}
type Health = {
  status:string|null; checked_at:string|null; data:Record<string, unknown>|null
}

const props = defineProps<{ connection:Connection; health:Health; lastRun:Run|null }>()
const connectionForm = useForm({
  site_url: props.connection.url,
  connection_key: props.connection.connection_key,
  key_id: props.connection.key_id,
  shared_secret: '',
  verify_tls: props.connection.verify_tls,
})
const healthForm = useForm({})
const syncForm = useForm({})
const saveConnection = () => connectionForm.put('/sync/wpapi/connection', { preserveScroll: true })
const testConnection = () => healthForm.post('/sync/wpapi/health', { preserveScroll: true })
const syncSubscribers = () => syncForm.post('/sync/wpapi/subscribers', { preserveScroll: true })
</script>
<template>
  <Head title="WordPress Connector · BYL ERP" />
  <AuthenticatedLayout title="WordPress Connector" :breadcrumbs="['BYL ERP','同步','WordPress Connector']">
    <section class="mvp-page connector-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">SYNC / WORDPRESS CONNECTOR</p>
          <h2>WordPress Connector</h2>
          <p>集中管理 Andy Core 连接、健康状态、WordPress 导入与后续写回。</p>
        </div>
        <div class="mvp-form-actions page-header-actions">
          <Link class="mvp-button" href="/sync">返回同步</Link>
          <button class="mvp-button" :disabled="healthForm.processing || !props.connection.configured" @click="testConnection">
            {{ healthForm.processing ? '测试中…' : '测试连接' }}
          </button>
        </div>
      </div>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">CONNECTION</p><h3>连接设置</h3></div>
          <span class="status-pill" :class="props.connection.configured ? 'is-ok' : 'is-muted'">
            {{ props.connection.configured ? '已配置' : '未配置' }}
          </span>
        </div>

        <div class="form-grid">
          <label><span>Site URL</span><input v-model="connectionForm.site_url" type="url" placeholder="https://localdev.beyourlover.com" /></label>
          <label><span>Connection Key</span><input v-model="connectionForm.connection_key" type="text" /></label>
          <label><span>Key ID</span><input v-model="connectionForm.key_id" type="text" /></label>
          <label>
            <span>Shared Secret</span>
            <input v-model="connectionForm.shared_secret" type="password" autocomplete="new-password" :placeholder="props.connection.secret_set ? '已保存，留空保持不变' : '输入 Shared Secret'" />
          </label>
        </div>
        <label class="check-row"><input v-model="connectionForm.verify_tls" type="checkbox" /><span>验证 TLS 证书</span></label>
        <div class="form-footer">
          <p>Secret 保存后不会再次显示，也不会返回到前端。</p>
          <button class="mvp-button mvp-button-primary" :disabled="connectionForm.processing" @click="saveConnection">
            {{ connectionForm.processing ? '保存中…' : '保存连接' }}
          </button>
        </div>
        <div v-if="Object.keys(connectionForm.errors).length" class="error-box">
          <p v-for="(message, key) in connectionForm.errors" :key="key">{{ message }}</p>
        </div>
      </section>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">HEALTH</p><h3>连接状态</h3></div>
          <span class="status-pill" :class="props.health.status === 'connected' ? 'is-ok' : 'is-muted'">
            {{ props.health.status === 'connected' ? 'Connected' : (props.health.status === 'error' ? 'Error' : 'Not tested') }}
          </span>
        </div>
        <dl class="health-grid">
          <div><dt>Site</dt><dd>{{ props.connection.url || '—' }}</dd></div>
          <div><dt>Contract</dt><dd>{{ props.health.data?.contract_version ?? '—' }}</dd></div>
          <div><dt>Andy Core</dt><dd>{{ props.health.data?.andy_core_version ?? '—' }}</dd></div>
          <div><dt>WordPress</dt><dd>{{ props.health.data?.wp_version ?? '—' }}</dd></div>
          <div><dt>WooCommerce</dt><dd>{{ props.health.data?.woocommerce_active === true ? 'Ready' : (props.health.data?.woocommerce_active === false ? 'Inactive' : '—') }}</dd></div>
          <div><dt>AffiliateWP</dt><dd>{{ props.health.data?.affiliatewp_active === true ? 'Ready' : (props.health.data?.affiliatewp_active === false ? 'Inactive' : '—') }}</dd></div>
          <div><dt>Writable</dt><dd>{{ props.health.data?.writable === true ? 'Yes' : (props.health.data?.writable === false ? 'No' : '—') }}</dd></div>
          <div><dt>Last check</dt><dd>{{ props.health.checked_at || '—' }}</dd></div>
        </dl>
        <p v-if="props.health.data?.message" class="error-box">{{ props.health.data.message }}</p>
      </section>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">READ IMPORTS</p><h3>数据同步</h3></div>
        </div>
        <div class="sync-list">
          <div class="sync-row">
            <div><strong>Subscribers</strong><span>{{ props.lastRun ? `最近：${props.lastRun.status}` : '尚未同步' }}</span></div>
            <button class="mvp-button mvp-button-primary" :disabled="syncForm.processing || !props.connection.configured" @click="syncSubscribers">立即同步</button>
          </div>
          <div v-for="resource in ['Affiliates','Coupons','Referrals','Payouts']" :key="resource" class="sync-row">
            <div><strong>{{ resource }}</strong><span>M1.2 接入</span></div>
            <button class="mvp-button" disabled>待接入</button>
          </div>
        </div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.connector-page{display:grid;gap:18px}.connector-panel{display:grid;gap:18px;padding:20px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}
.section-heading,.form-footer,.sync-row{display:flex;align-items:center;justify-content:space-between;gap:16px}.section-heading h3{margin:2px 0 0}.status-pill{padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.is-ok{background:rgba(34,197,94,.12)}.is-muted{background:var(--byl-surface-muted);color:var(--byl-text-muted)}
.form-grid,.health-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.form-grid label{display:grid;gap:6px}.form-grid span,.form-footer p,.sync-row span{font-size:13px;color:var(--byl-text-muted)}.form-grid input{width:100%;padding:10px 12px;border:1px solid var(--byl-border-subtle);border-radius:8px;background:var(--byl-surface)}
.check-row{display:flex;align-items:center;gap:8px}.health-grid{margin:0}.health-grid div{display:grid;gap:4px}.health-grid dt{font-size:12px;color:var(--byl-text-muted)}.health-grid dd{margin:0;font-weight:600}.error-box{margin:0;padding:10px 12px;border-radius:8px;background:rgba(239,68,68,.08);font-size:13px}.error-box p{margin:0}.sync-list{display:grid}.sync-row{padding:12px 0;border-top:1px solid var(--byl-border-subtle)}.sync-row:first-child{border-top:0}.sync-row div{display:grid;gap:3px}
@media(max-width:700px){.form-grid,.health-grid{grid-template-columns:1fr}.section-heading,.form-footer,.sync-row{align-items:flex-start;flex-direction:column}.sync-row .mvp-button{width:100%}}
</style>
