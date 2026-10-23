<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { onBeforeUnmount, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type ProbeResult = {
  status: string
  http_status: number | null
  code: string | null
  warehouse_count: number | null
}
const props = defineProps<{ probeEnabled: boolean; endpoint: string }>()
const appKey = ref('')
const appSecret = ref('')
const showSecret = ref(false)
const isTesting = ref(false)
const result = ref<ProbeResult | null>(null)
const errorText = ref('')
const messageFor = (status: string): string => ({
  verified: '连接验证成功：API 已返回明确的业务成功状态。仓库代码和 SKU 仍需单独核对。',
  rejected: '认证被拒绝（HTTP 401/403）。请确认 App Key / Secret 和 OMS 权限。',
  business_rejected: 'OMS 返回业务拒绝状态。请确认密钥和客户 API 权限。',
  unconfirmed: 'OMS 已响应，但返回格式尚不能确认认证成功。请将状态码交由技术人员核对。',
  unexpected_response: 'OMS 返回了非预期数据结构，暂不能确认为验证成功。',
  network_error: '网络连接失败或超时，请检查 ERP 服务器访问 OMS 的网络。',
  http_error: 'OMS HTTP 请求未成功。请检查权限、服务状态或网络。',
  disabled: '当前环境未启用手动只读认证测试。',
})[status] ?? '未能确认连接结果'
const handleVerify = async () => {
  if (isTesting.value || !props.probeEnabled || !appKey.value.trim() || !appSecret.value.trim()) return
  isTesting.value = true
  result.value = null
  errorText.value = ''
  try {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    if (!token) throw new Error('CSRF')
    const response = await fetch('/settings/warehouses/xlwms/verify', {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'error',
      cache: 'no-store',
      referrerPolicy: 'no-referrer',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': token,
      },
      body: JSON.stringify({ app_key: appKey.value.trim(), app_secret: appSecret.value.trim() }),
    })
    if (!response.ok) {
      errorText.value = response.status === 422
        ? '请输入完整有效的 App Key 和 App Secret。'
        : response.status === 419 ? '页面登录会话过期，请刷新后重试。'
          : response.status === 429 ? '测试次数过多，请稍后再试。'
            : '测试未完成，请确认管理员权限和服务器配置。'
      return
    }
    const data: ProbeResult = await response.json()
    result.value = data
  } catch {
    errorText.value = '请求失败，请检查 ERP 网络或刷新后重试。'
  } finally {
    // Never retain the secret in the page state after the one-shot test.
    appSecret.value = ''
    showSecret.value = false
    isTesting.value = false
  }
}
onBeforeUnmount(() => { appKey.value = ''; appSecret.value = ''; result.value = null })
</script>

<template>
  <Head title="领星 OMS API 连接测试 · BYL ERP" />
  <AuthenticatedLayout title="设置" :breadcrumbs="['BYL ERP', '设置', '仓库设置', '领星 OMS']">
    <section class="mvp-page xlwms-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Warehouse Integrations / XLWMS OpenAPI v1</p>
          <h2>领星 OMS · OpenAPI 设置</h2>
          <p>仓库 OpenAPI 的一次性连接调试。本阶段仅验证 API 身份，不会创建出库单或修改真实订单。</p>
        </div>
        <div class="mvp-actions"><Link class="mvp-button" href="/settings/warehouses">返回仓库设置</Link></div>
      </div>
      <div class="mvp-card xlwms-card">
        <div class="xlwms-status" :class="props.probeEnabled ? 'ready' : 'off'">
          {{ props.probeEnabled ? '允许只读验证 · 自动发货关闭' : '当前环境尚未启用连接测试' }}
        </div>
        <p class="xlwms-muted">固定官方接口：{{ props.endpoint }}</p>
        <p class="xlwms-security">安全说明：密钥仅用于本次 HTTPS API 请求；不保存到数据库、Session 或 GitHub，也不会显示在测试结果中。</p>
        <form autocomplete="off" @submit.prevent="handleVerify">
          <label for="xlwms-app-key">App Key</label>
          <input id="xlwms-app-key" v-model.trim="appKey" type="text" autocomplete="off" spellcheck="false" maxlength="256" placeholder="填写仓库提供的完整 App Key" required :disabled="isTesting" />
          <label for="xlwms-app-secret">App Secret</label>
          <div class="xlwms-secret">
            <input id="xlwms-app-secret" v-model="appSecret" :type="showSecret ? 'text' : 'password'" autocomplete="new-password" spellcheck="false" maxlength="256" placeholder="填写完整 App Secret" required :disabled="isTesting" />
            <button type="button" class="mvp-button" :disabled="isTesting" @click="showSecret = !showSecret">{{ showSecret ? '隐藏' : '显示' }}</button>
          </div>
          <div class="mvp-actions xlwms-actions">
            <button type="submit" class="mvp-button mvp-button-primary" :disabled="!props.probeEnabled || isTesting || !appKey || !appSecret">
              {{ isTesting ? '正在安全验证…' : '测试连接（仅查询仓库）' }}
            </button>
          </div>
        </form>
        <p v-if="errorText" class="mvp-error" role="alert">{{ errorText }}</p>
        <div v-if="result" class="xlwms-result" role="status">
          <strong>{{ messageFor(result.status) }}</strong>
          <p>连接状态：{{ result.status }} <span v-if="result.http_status">· HTTP {{ result.http_status }}</span><span v-if="result.code"> · OMS 代码 {{ result.code }}</span></p>
          <p v-if="result.warehouse_count !== null">返回仓库记录：{{ result.warehouse_count }}（尚未映射到 ERP）</p>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.xlwms-page { max-width: 1100px; }
.xlwms-card { width: min(100%, 750px); padding: 24px; }
.xlwms-card form { display: grid; gap: 10px; margin-top: 22px; }
.xlwms-card label { font-size: 14px; font-weight: 700; margin-top: 8px; }
.xlwms-card input { width: 100%; height: 38px; padding: 7px 11px; border: 1px solid #cbd5e1; border-radius: 6px; background: var(--surface, #fff); color: var(--text, #111); }
.xlwms-status { padding: 11px 14px; font-weight: 700; border-radius: 7px; background: #f4f4f1; color: #25332b; }
.xlwms-status.off { background: #fff4e5; color: #694300; }
.xlwms-muted { margin-top: 14px; color: #64748b; font-size: 12px; word-break: break-all; }
.xlwms-security { margin-top: 12px; color: #374151; }
.xlwms-secret { display: flex; gap: 8px; align-items: center; }
.xlwms-secret input { flex: 1; min-width: 0; }
.xlwms-actions { justify-content: flex-start; margin-top: 15px; }
.xlwms-result { margin-top: 22px; border: 1px solid #d4d4d4; padding: 15px; border-radius: 6px; background: #fafafa; }
.xlwms-result p { color: #475569; margin: 8px 0 0; }
</style>
