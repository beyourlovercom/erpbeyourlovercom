<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Overview = {
  total: number
  mine: number | null
  hundred_k_plus: number
  by_stage: Record<string, number>
}

defineProps<{
  overview: Overview
  canOperate: boolean
  canManageViews: boolean
}>()

const stageLabel = (stage: string): string => ({
  prospect: '潜在',
  contacted: '已联系',
  negotiating: '谈判中',
  cooperating: '合作中',
  long_term: '长期合作',
  paused: '暂停',
  ended: '已结束',
}[stage] ?? stage)

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '网红', 'KOL 总览']
</script>

<template>
  <Head title="KOL 总览 · BYL ERP" />
  <AuthenticatedLayout title="KOL 总览" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL Master / 网红管理</p>
          <h2>KOL 总览</h2>
          <p>KOL 数据基础总览，基于 M1.0 可用事实。</p>
        </div>
        <div class="mvp-actions">
          <Link class="mvp-button" href="/kol/library">红人库</Link>
          <Link v-if="canOperate" class="mvp-button mvp-button-primary" href="/kol/library/create">新建 KOL</Link>
        </div>
      </div>

      <div class="mvp-stat-grid">
        <article class="mvp-card mvp-stat-card">
          <small>KOL 总数</small>
          <strong>{{ overview.total }}</strong>
        </article>
        <article v-if="overview.mine !== null" class="mvp-card mvp-stat-card">
          <small>我的 KOL</small>
          <strong>{{ overview.mine }}</strong>
        </article>
        <article class="mvp-card mvp-stat-card">
          <small>100K+ 粉丝</small>
          <strong>{{ overview.hundred_k_plus }}</strong>
        </article>
      </div>

      <div v-if="Object.keys(overview.by_stage).length > 0" class="mvp-card">
        <h3>关系阶段分布</h3>
        <div class="stage-grid">
          <div v-for="(count, stage) in overview.by_stage" :key="stage" class="stage-item">
            <span class="stage-label">{{ stageLabel(stage) }}</span>
            <span class="stage-count">{{ count }}</span>
          </div>
        </div>
      </div>

      <div class="mvp-card kol-nav-grid">
        <h3>KOL 功能模块</h3>
        <div class="nav-items">
          <Link class="nav-item nav-item-active" href="/kol/library">
            <strong>红人库</strong>
            <span>搜索、筛选和管理 KOL 主数据</span>
          </Link>
          <div class="nav-item nav-item-planned">
            <strong>合作机会</strong>
            <span>M1.1 CRM 包交付</span>
          </div>
          <div class="nav-item nav-item-planned">
            <strong>合作记录</strong>
            <span>M1.2 合作包交付</span>
          </div>
          <div class="nav-item nav-item-planned">
            <strong>沟通跟进</strong>
            <span>M1.1 CRM 包交付</span>
          </div>
          <div class="nav-item nav-item-planned">
            <strong>内容与发布</strong>
            <span>M1.3 内容包交付</span>
          </div>
          <div class="nav-item nav-item-planned">
            <strong>佣金与结算</strong>
            <span>M1.4 财务包交付</span>
          </div>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.stage-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
.stage-item { display: flex; flex-direction: column; gap: 4px; padding: 10px 16px; border: 1px solid #e5e7eb; border-radius: 8px; min-width: 100px; }
.stage-label { font-size: 12px; color: #64748b; }
.stage-count { font-size: 20px; font-weight: 600; }
.kol-nav-grid .nav-items { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 12px; }
.nav-item { display: flex; flex-direction: column; gap: 6px; padding: 14px; border: 1px solid #e5e7eb; border-radius: 10px; text-decoration: none; color: inherit; }
.nav-item-active { cursor: pointer; }
.nav-item-active:hover { border-color: #0f766e; }
.nav-item-planned { opacity: 0.55; cursor: default; }
.nav-item strong { font-size: 14px; }
.nav-item span { font-size: 12px; color: #64748b; }
</style>
