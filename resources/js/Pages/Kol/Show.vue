<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type SalesPerformanceEvent = { id: number; order_count: number; recorded_at: string | null; source: string | null; note: string | null }
type KolDetail = {
  id: number; display_name: string; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  owner: { id: number; name: string } | null; joined_at: string | null; archived_at: string | null; notes: string | null
  contact_email: string | null; contact_phone: string | null; contact_whatsapp: string | null; other_contact: string | null
  preferred_contact_method: string
  tax_id: string | null; payout_method: string | null; payout_account: string | null
  shipping_recipient_name: string | null; shipping_country: string | null; shipping_province_state: string | null
  shipping_city: string | null; shipping_postal_code: string | null
  shipping_address_line_1: string | null; shipping_address_line_2: string | null
  level: string | null; city: string | null; categories: string[]; products: string[]
  content_format: string | null; product_quantity: number | null; store_count: number | null
  internal_contact: string | null; mcn_agency: string | null; is_mcn_managed: boolean
  historical_order_count: number
  sales_performance_history: SalesPerformanceEvent[]
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
const contactMethodLabel = (m: string) => ({ phone: '电话', email: 'Email', whatsapp: 'WhatsApp', other: '其他' }[m] ?? m)

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '红人库', href: '/kol/library' }, props.kol.display_name]
</script>

<template>
  <Head :title="`${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="kol.display_name" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL · {{ stageLabel(kol.relationship_stage) }}<span v-if="kol.level"> · {{ kol.level }}</span></p>
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

      <!-- 基本信息 -->
      <div class="detail-grid">
        <div class="mvp-card">
          <h3>基本信息</h3>
          <dl class="detail-list">
            <div><dt>国家/地区</dt><dd>{{ kol.country_code ?? '—' }}</dd></div>
            <div><dt>语言</dt><dd>{{ kol.language ?? '—' }}</dd></div>
            <div><dt>合作类型</dt><dd>{{ kol.cooperation_type ?? '—' }}</dd></div>
            <div><dt>加入时间</dt><dd>{{ kol.joined_at ?? '—' }}</dd></div>
            <div><dt>负责人</dt><dd>{{ kol.owner?.name ?? '—' }}</dd></div>
            <div><dt>状态</dt><dd>{{ kol.status === 'active' ? '活跃' : '停用' }}</dd></div>
          </dl>
        </div>

        <!-- 业务画像 -->
        <div class="mvp-card">
          <h3>业务画像</h3>
          <dl class="detail-list">
            <div><dt>等级</dt><dd>{{ kol.level ?? '—' }}</dd></div>
            <div><dt>城市</dt><dd>{{ kol.city ?? '—' }}</dd></div>
            <div><dt>主营品类</dt><dd>{{ kol.categories.length ? kol.categories.join('、') : '—' }}</dd></div>
            <div><dt>主推产品</dt><dd>{{ kol.products.length ? kol.products.join('、') : '—' }}</dd></div>
            <div><dt>内容形式</dt><dd>{{ kol.content_format ?? '—' }}</dd></div>
            <div><dt>产品数量</dt><dd>{{ kol.product_quantity ?? '—' }}</dd></div>
            <div><dt>店铺数量</dt><dd>{{ kol.store_count ?? '—' }}</dd></div>
            <div><dt>历史带货数量</dt><dd class="historical-count">{{ kol.historical_order_count }}</dd></div>
          </dl>
        </div>
      </div>

      <div class="detail-grid">
        <!-- 联系方式 -->
        <div class="mvp-card">
          <h3>联系方式</h3>
          <dl class="detail-list">
            <div><dt>首选方式</dt><dd>{{ contactMethodLabel(kol.preferred_contact_method) }}</dd></div>
            <div v-if="kol.contact_email"><dt>Email</dt><dd>{{ kol.contact_email }}</dd></div>
            <div v-if="kol.contact_phone"><dt>电话</dt><dd>{{ kol.contact_phone }}</dd></div>
            <div v-if="kol.contact_whatsapp"><dt>WhatsApp</dt><dd>{{ kol.contact_whatsapp }}</dd></div>
            <div v-if="kol.other_contact"><dt>其他</dt><dd>{{ kol.other_contact }}</dd></div>
          </dl>
        </div>

        <!-- 收件地址 -->
        <div class="mvp-card">
          <h3>收件地址</h3>
          <dl class="detail-list">
            <div v-if="kol.shipping_recipient_name"><dt>收件人</dt><dd>{{ kol.shipping_recipient_name }}</dd></div>
            <div v-if="kol.shipping_address_line_1"><dt>地址行 1</dt><dd>{{ kol.shipping_address_line_1 }}</dd></div>
            <div v-if="kol.shipping_address_line_2"><dt>地址行 2</dt><dd>{{ kol.shipping_address_line_2 }}</dd></div>
            <div v-if="kol.shipping_city || kol.shipping_province_state || kol.shipping_postal_code">
              <dt>城市/省州/邮编</dt>
              <dd>{{ [kol.shipping_city, kol.shipping_province_state, kol.shipping_postal_code].filter(Boolean).join(', ') || '—' }}</dd>
            </div>
            <div><dt>国家</dt><dd>{{ kol.shipping_country ?? '—' }}</dd></div>
          </dl>
        </div>
      </div>

      <div class="detail-grid">
        <!-- 税务与收款 -->
        <div class="mvp-card">
          <h3>税务与收款</h3>
          <dl class="detail-list">
            <div><dt>Tax ID</dt><dd>{{ kol.tax_id ?? '—' }}</dd></div>
            <div><dt>收款方式</dt><dd>{{ { paypal: 'PayPal', bank: '银行转账', other: '其他' }[kol.payout_method ?? ''] ?? '—' }}</dd></div>
            <div><dt>收款账户</dt><dd>{{ kol.payout_account ?? '—' }}</dd></div>
          </dl>
        </div>

        <!-- 内部运营信息 -->
        <div class="mvp-card">
          <h3>内部运营信息</h3>
          <dl class="detail-list">
            <div><dt>内部联系人</dt><dd>{{ kol.internal_contact ?? '—' }}</dd></div>
            <div><dt>MCN 机构</dt><dd>{{ kol.mcn_agency ?? '—' }}</dd></div>
            <div><dt>MCN 管理</dt><dd>{{ kol.is_mcn_managed ? '是' : '否' }}</dd></div>
          </dl>
        </div>
      </div>

      <!-- 社交账号 -->
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

      <!-- 历史带货记录 -->
      <div class="mvp-card">
        <h3>历史带货记录（累计 {{ kol.historical_order_count }}）</h3>
        <div v-if="kol.sales_performance_history.length === 0" class="empty-state">暂无带货记录</div>
        <div v-for="event in kol.sales_performance_history" :key="event.id" class="perf-row">
          <strong>{{ event.order_count }}</strong>
          <span>{{ event.recorded_at ?? '—' }}</span>
          <span v-if="event.source">{{ event.source }}</span>
          <span v-if="event.note" class="perf-note">{{ event.note }}</span>
        </div>
      </div>

      <!-- 更多资料 -->
      <div class="mvp-card">
        <h3>更多资料</h3>
        <dl class="detail-list">
          <div><dt>国家/地区</dt><dd>{{ kol.country_code ?? '—' }}</dd></div>
          <div><dt>语言</dt><dd>{{ kol.language ?? '—' }}</dd></div>
        </dl>
      </div>

      <!-- 备注 -->
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
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px; }
.detail-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.detail-list div { display: flex; gap: 8px; }
.detail-list dt { font-size: 12px; color: #64748b; min-width: 80px; }
.detail-list dd { font-size: 14px; margin: 0; }
.historical-count { font-weight: 600; }
.empty-state { font-size: 13px; color: #64748b; padding: 8px 0; }
.social-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.social-row:last-child { border-bottom: none; }
.social-platform { font-weight: 600; }
.badge-inactive { margin-left: 4px; padding: 1px 6px; background: #fee2e2; color: #991b1b; border-radius: 10px; font-size: 11px; font-weight: normal; }
.profile-link { color: #0f766e; text-decoration: underline; }
.followers { color: #374151; }
.measured-at { color: #94a3b8; }
.perf-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.perf-row:last-child { border-bottom: none; }
.perf-note { color: #64748b; }
.notes-text { white-space: pre-wrap; font-size: 14px; }
.planned-modules { opacity: 0.7; }
.planned-note { font-size: 13px; color: #64748b; }
.btn-danger { color: #dc2626; border-color: #dc2626; }
</style>
