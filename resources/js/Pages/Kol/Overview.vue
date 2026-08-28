<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type OwnerRankingRow = { owner_id: number; owner_name: string; count: number }
type Overview = {
  total: number
  mine: number | null
  monthly_new: number
  collaborated: number
  long_term: number
  hundred_k_plus: number
  by_stage: Record<string, number>
  by_level: Record<string, number>
  by_content_format: Record<string, number>
  by_platform: Record<string, number>
  owner_ranking: OwnerRankingRow[]
  pending_publication_count: number | null
  pending_commission_amount: number | null
}
type OwnerOption = { value: number; label: string }

const props = defineProps<{
  overview: Overview
  filters: Record<string, string>
  ownerOptions: OwnerOption[]
  canOperate: boolean
  canManageViews: boolean
}>()

const filters = reactive({
  owner_id: props.filters.owner_id ?? '',
  platform: props.filters.platform ?? '',
  country_code: props.filters.country_code ?? '',
  level: props.filters.level ?? '',
  relationship_stage: props.filters.relationship_stage ?? '',
  date_from: props.filters.date_from ?? '',
  date_to: props.filters.date_to ?? '',
})

const submit = () => router.get('/kol', filters, { preserveState: true, replace: true })

const stageLabel = (stage: string): string => ({
  prospect: '潜在',
  contacted: '已联系',
  negotiating: '谈判中',
  cooperating: '合作中',
  long_term: '长期合作',
  paused: '暂停',
  ended: '已结束',
}[stage] ?? stage)

const platformLabel = (p: string): string => ({ instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', xiaohongshu: '小红书', weibo: '微博', wechat: '微信', twitter: 'X/Twitter', facebook: 'Facebook', other: '其他' }[p] ?? p)

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '网红']
</script>

<template>
  <Head title="KOL 总览 · BYL ERP" />
  <AuthenticatedLayout title="KOL 总览" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL Master / 网红管理</p>
          <h2>KOL 总览</h2>
          <p>KOL 运营数据看板，基于当前可用的规范数据事实。</p>
        </div>
        <div class="mvp-actions">
          <Link class="mvp-button" href="/kol/library">红人库</Link>
          <Link v-if="canOperate" class="mvp-button mvp-button-primary" href="/kol/library/create">新建 KOL</Link>
        </div>
      </div>

      <!-- Dashboard filters -->
      <form class="mvp-toolbar" @submit.prevent="submit">
        <select v-if="canManageViews" v-model="filters.owner_id" aria-label="负责人">
          <option value="">全部负责人</option>
          <option v-for="o in ownerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option>
        </select>
        <select v-model="filters.platform" aria-label="平台">
          <option value="">全部平台</option>
          <option value="instagram">Instagram</option>
          <option value="tiktok">TikTok</option>
          <option value="youtube">YouTube</option>
          <option value="xiaohongshu">小红书</option>
          <option value="weibo">微博</option>
        </select>
        <input v-model="filters.country_code" aria-label="国家/地区" placeholder="国家/地区">
        <select v-model="filters.level" aria-label="等级">
          <option value="">全部等级</option>
          <option value="头部">头部</option>
          <option value="腰部">腰部</option>
          <option value="尾部">尾部</option>
          <option value="素人">素人</option>
        </select>
        <select v-model="filters.relationship_stage" aria-label="合作阶段">
          <option value="">全部阶段</option>
          <option value="prospect">潜在</option>
          <option value="contacted">已联系</option>
          <option value="negotiating">谈判中</option>
          <option value="cooperating">合作中</option>
          <option value="long_term">长期合作</option>
        </select>
        <input v-model="filters.date_from" type="date" aria-label="起始日期">
        <input v-model="filters.date_to" type="date" aria-label="结束日期">
        <button class="mvp-button" type="submit">筛选</button>
      </form>

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
          <small>本月新增</small>
          <strong>{{ overview.monthly_new }}</strong>
        </article>
        <article class="mvp-card mvp-stat-card">
          <small>已合作</small>
          <strong>{{ overview.collaborated }}</strong>
        </article>
        <article class="mvp-card mvp-stat-card">
          <small>长期合作</small>
          <strong>{{ overview.long_term }}</strong>
        </article>
        <article class="mvp-card mvp-stat-card">
          <small>100K+ 粉丝</small>
          <strong>{{ overview.hundred_k_plus }}</strong>
        </article>
      </div>

      <div class="distribution-grid">
        <div v-if="Object.keys(overview.by_stage).length > 0" class="mvp-card">
          <h3>合作阶段漏斗</h3>
          <div class="stage-grid">
            <div v-for="(count, stage) in overview.by_stage" :key="stage" class="stage-item">
              <span class="stage-label">{{ stageLabel(String(stage)) }}</span>
              <span class="stage-count">{{ count }}</span>
            </div>
          </div>
        </div>

        <div v-if="Object.keys(overview.by_level).length > 0" class="mvp-card">
          <h3>等级分布</h3>
          <div class="stage-grid">
            <div v-for="(count, level) in overview.by_level" :key="level" class="stage-item">
              <span class="stage-label">{{ level }}</span>
              <span class="stage-count">{{ count }}</span>
            </div>
          </div>
        </div>

        <div v-if="Object.keys(overview.by_content_format).length > 0" class="mvp-card">
          <h3>内容形式分布</h3>
          <div class="stage-grid">
            <div v-for="(count, format) in overview.by_content_format" :key="format" class="stage-item">
              <span class="stage-label">{{ format }}</span>
              <span class="stage-count">{{ count }}</span>
            </div>
          </div>
        </div>

        <div v-if="Object.keys(overview.by_platform).length > 0" class="mvp-card">
          <h3>平台分布</h3>
          <div class="stage-grid">
            <div v-for="(count, platform) in overview.by_platform" :key="platform" class="stage-item">
              <span class="stage-label">{{ platformLabel(String(platform)) }}</span>
              <span class="stage-count">{{ count }}</span>
            </div>
          </div>
        </div>

      </div>

      <div class="mvp-card not-connected-card">
        <h3>待接入指标</h3>
        <p class="not-connected-note">以下指标依赖尚未交付的后续包，暂不显示编造数据：</p>
        <div class="stage-grid">
          <div class="stage-item">
            <span class="stage-label">待发布内容</span>
            <span class="stage-count">{{ overview.pending_publication_count === null ? '暂未接入' : overview.pending_publication_count }}</span>
          </div>
          <div class="stage-item">
            <span class="stage-label">待结算佣金</span>
            <span class="stage-count">{{ overview.pending_commission_amount === null ? '暂未接入' : overview.pending_commission_amount }}</span>
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
.distribution-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px; }
.stage-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
.stage-item { display: flex; flex-direction: column; gap: 4px; padding: 10px 16px; border: 1px solid #e5e7eb; border-radius: 8px; min-width: 100px; }
.stage-label { font-size: 12px; color: #64748b; }
.stage-count { font-size: 20px; font-weight: 600; }
.not-connected-card { opacity: 0.75; margin-bottom: 16px; }
.not-connected-note { font-size: 13px; color: #64748b; }
.kol-nav-grid .nav-items { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 12px; }
.nav-item { display: flex; flex-direction: column; gap: 6px; padding: 14px; border: 1px solid #e5e7eb; border-radius: 10px; text-decoration: none; color: inherit; }
.nav-item-active { cursor: pointer; }
.nav-item-active:hover { border-color: #0f766e; }
.nav-item-planned { opacity: 0.55; cursor: default; }
.nav-item strong { font-size: 14px; }
.nav-item span { font-size: 12px; color: #64748b; }
</style>
