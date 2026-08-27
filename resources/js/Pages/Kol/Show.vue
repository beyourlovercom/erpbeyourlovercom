<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type KolDetail = {
  id: number; display_name: string; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  owner: { id: number; name: string } | null; joined_at: string | null; archived_at: string | null; notes: string | null
  social_accounts: SocialAccount[]
}

const props = defineProps<{
  kol: KolDetail
  canOperate: boolean
  canManageViews: boolean
}>()

const archive = () => {
  if (confirm(`确认归档 ${props.kol.display_name}？`)) {
    router.post(`/kol/library/${props.kol.id}/archive`)
  }
}
const reactivate = () => router.post(`/kol/library/${props.kol.id}/reactivate`)

const stageLabel = (s: string) => ({ prospect: '潜在', contacted: '已联系', negotiating: '谈判中', cooperating: '合作中', long_term: '长期合作', paused: '暂停', ended: '已结束' }[s] ?? s)
const platformLabel = (p: string) => ({ instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', xiaohongshu: '小红书', weibo: '微博', wechat: '微信', twitter: 'X/Twitter', facebook: 'Facebook', other: '其他' }[p] ?? p)
const followersLabel = (n: number | null) => n === null ? '—' : n >= 1000000 ? `${(n / 1000000).toFixed(1)}M` : n >= 1000 ? `${(n / 1000).toFixed(1)}K` : String(n)

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '红人库', href: '/kol/library' }, props.kol.display_name]
</script>

<template>
  <Head :title="`${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="kol.display_name" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL · {{ stageLabel(kol.relationship_stage) }}</p>
          <h2>{{ kol.display_name }}</h2>
          <p v-if="kol.legal_name" class="legal-name">法定名称：{{ kol.legal_name }}</p>
        </div>
        <div v-if="canOperate" class="mvp-actions">
          <Link class="mvp-button" :href="`/kol/library/${kol.id}/edit`">编辑</Link>
          <button v-if="!kol.archived_at" class="mvp-button btn-danger" type="button" @click="archive">归档</button>
          <button v-else class="mvp-button" type="button" @click="reactivate">重新激活</button>
        </div>
      </div>

      <div v-if="kol.archived_at" class="archived-banner">此 KOL 已归档（{{ kol.archived_at }}）</div>

      <div class="detail-grid">
        <div class="mvp-card">
          <h3>基本资料</h3>
          <dl class="detail-list">
            <div><dt>国家/地区</dt><dd>{{ kol.country_code ?? '—' }}</dd></div>
            <div><dt>语言</dt><dd>{{ kol.language ?? '—' }}</dd></div>
            <div><dt>合作类型</dt><dd>{{ kol.cooperation_type ?? '—' }}</dd></div>
            <div><dt>加入时间</dt><dd>{{ kol.joined_at ?? '—' }}</dd></div>
            <div><dt>负责人</dt><dd>{{ kol.owner?.name ?? '—' }}</dd></div>
            <div><dt>状态</dt><dd>{{ kol.status === 'active' ? '活跃' : '停用' }}</dd></div>
          </dl>
        </div>

        <div class="mvp-card">
          <h3>社交账号</h3>
          <div v-if="kol.social_accounts.length === 0" class="empty-state">暂无社交账号</div>
          <div v-for="sa in kol.social_accounts" :key="sa.id" class="social-row">
            <div class="social-platform">{{ platformLabel(sa.platform) }}<span v-if="!sa.is_active" class="badge-inactive">停用</span></div>
            <div v-if="sa.handle">@{{ sa.handle }}</div>
            <a v-if="sa.profile_url" :href="sa.profile_url" target="_blank" rel="noopener noreferrer" class="profile-link">主页</a>
            <div v-if="sa.latest_metric" class="followers">
              粉丝：{{ followersLabel(sa.latest_metric.followers) }}
              <span v-if="sa.latest_metric.raw_followers_value"> ({{ sa.latest_metric.raw_followers_value }})</span>
              <span class="measured-at" v-if="sa.latest_metric.measured_at"> · {{ sa.latest_metric.measured_at }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="kol.notes" class="mvp-card">
        <h3>备注</h3>
        <p class="notes-text">{{ kol.notes }}</p>
      </div>

      <div class="mvp-card planned-modules">
        <h3>后续功能（待交付）</h3>
        <p class="planned-note">合作机会、沟通跟进 (M1.1) · 合作记录、样品与发货 (M1.2) · 内容与发布 (M1.3) · 佣金与结算 (M1.4)</p>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.legal-name { font-size: 13px; color: #64748b; }
.archived-banner { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.detail-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.detail-list div { display: flex; gap: 8px; }
.detail-list dt { font-size: 12px; color: #64748b; min-width: 80px; }
.detail-list dd { font-size: 14px; margin: 0; }
.empty-state { font-size: 13px; color: #64748b; padding: 8px 0; }
.social-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.social-row:last-child { border-bottom: none; }
.social-platform { font-weight: 600; }
.badge-inactive { margin-left: 4px; padding: 1px 6px; background: #fee2e2; color: #991b1b; border-radius: 10px; font-size: 11px; font-weight: normal; }
.profile-link { color: #0f766e; text-decoration: underline; }
.followers { color: #374151; }
.measured-at { color: #94a3b8; }
.notes-text { white-space: pre-wrap; font-size: 14px; }
.planned-modules { opacity: 0.7; }
.planned-note { font-size: 13px; color: #64748b; }
.btn-danger { color: #dc2626; border-color: #dc2626; }
</style>
