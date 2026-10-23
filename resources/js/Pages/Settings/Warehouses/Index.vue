<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Integration = {
  id: string
  label: string
  url: string
  capability: string
  configured: boolean
  auto_dispatch_enabled: boolean
}
defineProps<{ integrations: Integration[] }>()
</script>

<template>
  <Head title="仓库设置 · BYL ERP" />
  <AuthenticatedLayout title="设置" :breadcrumbs="['BYL ERP', '设置', '仓库设置']">
    <section class="mvp-page warehouse-settings-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">WAREHOUSE SETTINGS / OPENAPI</p>
          <h2>仓库设置</h2>
          <p>集中管理仓库 API 授权、连接检查和一次性仓库绑定。日常出库、订单审核和 Tracking 仍在物流模块操作。</p>
        </div>
        <Link class="mvp-button" href="/settings">返回系统设置</Link>
      </div>
      <article class="mvp-card">
        <div class="setting-heading">
          <div><h3>OpenAPI 集成</h3><p>按仓库服务商配置一次，不需要重复设置 SKU 或物流渠道映射。</p></div>
        </div>
        <div v-for="entry in integrations" :key="entry.id" class="integration-row">
          <div class="integration-description">
            <strong>{{ entry.label }}</strong>
            <span>服务商连接 · {{ entry.configured ? '已保存配置' : '尚未保存持久配置' }}</span>
            <small>自动发货：{{ entry.auto_dispatch_enabled ? '已开启' : '关闭' }}。SKU 同名直连；物流渠道使用 ERP 已登记的仓库原生代码。</small>
          </div>
          <Link class="mvp-button mvp-button-primary" :href="entry.url">进入 API 设置</Link>
        </div>
        <p class="setting-footnote">仓库属于哪个 ERP 仓库，需要由管理员确认绑定；不根据仓库名称自动匹配。</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.warehouse-settings-page{max-width:1120px}.setting-heading{padding-bottom:14px;border-bottom:1px solid #dde4e8}
.setting-heading h3{margin:0 0 5px;font-size:18px}.setting-heading p,.setting-footnote{color:#64748b;font-size:13px}
.integration-row{display:flex;align-items:center;justify-content:space-between;gap:22px;padding:20px 0}
.integration-description{display:grid;gap:6px}.integration-description strong{font-size:17px}
.integration-description span,.integration-description small{color:#475569}.integration-description small{font-size:12px}
@media(max-width:700px){.integration-row{align-items:flex-start;flex-direction:column}}
</style>
