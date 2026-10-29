<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const props = defineProps<{
  preview: {
    mode: string
    auto_match_enabled: boolean
    requires_manual_evidence: boolean
    total_kol_orders: number
    explicitly_linked: number
    existing_collaboration_only: number
    unlinked: number
    ambiguous_collaborations: number
    unlinked_sample: { id: number; erp_order_id: string | null; status: string }[]
    next_action: string
  }
}>()
</script>

<template>
  <Head title="网红订单历史关联预览 · BYL ERP" />
  <AuthenticatedLayout title="历史关联预览" :breadcrumbs="[{ label: '网红', href: '/kol' }, { label: '网红订单', href: '/kol/orders' }, '历史关联预览']">
    <div class="erp-form-page">
      <div class="erp-form-content">
        <div class="erp-form-header-row">
          <div>
            <p class="ord-kicker">KOL IDENTITY · READ ONLY</p>
            <h1 class="erp-form-title">历史订单关联预览</h1>
            <p class="erp-form-subtitle">只读查看，禁止按姓名、邮箱、业务订单号猜测归属；不会创建或修改任何关联。</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <Link class="erp-btn erp-btn-secondary" href="/kol/orders">返回网红订单</Link>
          </div>
        </div>
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">关联统计</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field"><label class="erp-form-label">总网红订单</label><div class="erp-readonly-input">{{ props.preview.total_kol_orders }}</div></div>
            <div class="erp-form-field"><label class="erp-form-label">显式已关联</label><div class="erp-readonly-input">{{ props.preview.explicitly_linked }}</div></div>
            <div class="erp-form-field"><label class="erp-form-label">仅正式合作关联</label><div class="erp-readonly-input">{{ props.preview.existing_collaboration_only }}</div></div>
            <div class="erp-form-field"><label class="erp-form-label">缺少可信 KOL ID</label><div class="erp-readonly-input">{{ props.preview.unlinked }}</div></div>
            <div class="erp-form-field"><label class="erp-form-label">歧义合作记录</label><div class="erp-readonly-input">{{ props.preview.ambiguous_collaborations }}</div></div>
            <div class="erp-form-field"><label class="erp-form-label">自动匹配</label><div class="erp-readonly-input">关闭（仅预览）</div></div>
          </div>
        </div>
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">待核对订单 · 最近 20 笔</h2>
          <p class="erp-form-subtitle">{{ props.preview.next_action }}</p>
          <table style="width:100%;text-align:left;border-collapse:collapse" aria-label="未关联的网红订单">
            <thead><tr><th style="padding:10px">ERP ID</th><th style="padding:10px">ERP 订单号</th><th style="padding:10px">状态</th></tr></thead>
            <tbody>
              <tr v-for="item in props.preview.unlinked_sample" :key="item.id">
                <td style="padding:10px">{{ item.id }}</td>
                <td style="padding:10px"><Link :href="'/kol/orders/' + item.id">{{ item.erp_order_id || '—' }}</Link></td>
                <td style="padding:10px">缺少可信 KOL ID</td>
              </tr>
              <tr v-if="props.preview.unlinked_sample.length === 0"><td colspan="3" style="padding:10px">当前无待核对订单</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
