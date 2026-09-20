<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type DashboardVisibility = {
  operations: boolean
  inventory: boolean
  sync: boolean
  finance: boolean
  procurement: boolean
}
type ProcurementRow = { id:number; procurement_number:string; status:string; source_order_erp_id:string|null; carrier:string|null; tracking_number:string|null; updated_at:string|null }
type ProcurementProgress = { active:number; counts:Record<'pending'|'purchasing'|'ordered'|'completed'|'cancelled', number>; recent:ProcurementRow[] }

const props = withDefaults(defineProps<{
  visibility: DashboardVisibility
  period?: '7d'|'30d'|'90d'|'custom'
  dateFrom?: string|null
  dateTo?: string|null
  procurementProgress?: ProcurementProgress|null
}>(), {
  period: '7d',
  dateFrom: null,
  dateTo: null,
  procurementProgress: null,
})

const periodOptions = [
  { value: '7d', label: '近 7 天' },
  { value: '30d', label: '近 30 天' },
  { value: '90d', label: '近 90 天' },
] as const
const periodHref = (period: string) => `/dashboard?period=${period}`
const statusMeta = [
  { value:'pending', label:'待采购' }, { value:'purchasing', label:'采购中' }, { value:'ordered', label:'已下单' },
  { value:'completed', label:'已完成' }, { value:'cancelled', label:'已取消' },
] as const
const statusLabel = (value:string) => statusMeta.find(item => item.value === value)?.label || value
</script>

<template>
  <Head title="仪表盘 · BYL ERP" />
  <AuthenticatedLayout title="仪表盘" :breadcrumbs="['BYL ERP', '仪表盘']">
    <section class="dashboard-shell-page">
      <header class="dashboard-hero">
        <div>
          <p class="mvp-kicker">BOSS / OPERATIONS</p>
          <h2>经营与运营总览</h2>
          <p>一屏查看履约、库存、同步和经营状态；具体处理仍回到各业务系统。</p>
        </div>
        <div class="dashboard-period" aria-label="经营时间范围">
          <Link
            v-for="item in periodOptions"
            :key="item.value"
            class="period-option"
            :class="{ active: props.period === item.value }"
            :href="periodHref(item.value)"
          >{{ item.label }}</Link>
          <Link class="period-option" :class="{ active: props.period === 'custom' }" href="/dashboard?period=custom">自定义</Link>
        </div>
      </header>

      <form v-if="props.period === 'custom'" class="dashboard-custom-period" method="get" action="/dashboard">
        <input type="hidden" name="period" value="custom">
        <label>开始日期<input type="date" name="date_from" :value="props.dateFrom || ''"></label>
        <label>结束日期<input type="date" name="date_to" :value="props.dateTo || ''"></label>
        <button class="mvp-button mvp-button-primary" type="submit">应用时间范围</button>
      </form>

      <nav class="dashboard-section-nav" aria-label="仪表盘分区">
        <a v-if="visibility.operations" href="#operations">当前运营</a>
        <a v-if="visibility.inventory" href="#inventory">库存风险</a>
        <a v-if="visibility.sync" href="#sync">同步健康</a>
        <a v-if="visibility.finance" href="#finance">经营财务</a>
        <a v-if="visibility.procurement" href="#procurement">采购</a>
      </nav>

      <section v-if="visibility.operations" id="operations" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">OPERATIONS</p><h3>当前运营</h3><p>履约链路的实时状态与异常入口。</p></div>
          <Link class="mvp-button" href="/logistics">进入 Logistics OS</Link>
        </div>
        <div class="dashboard-placeholder-grid dashboard-placeholder-grid-five" aria-label="当前运营指标骨架">
          <article><span>待发货</span><strong>—</strong></article>
          <article><span>已发货待物流号</span><strong>—</strong></article>
          <article><span>运输中</span><strong>—</strong></article>
          <article><span>异常单</span><strong>—</strong></article>
          <article><span>复核单</span><strong>—</strong></article>
        </div>
      </section>

      <section v-if="visibility.inventory" id="inventory" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">INVENTORY</p><h3>库存风险</h3><p>关注没货、库存紧张以及仓库数据缺口。</p></div>
          <Link class="mvp-button" href="/inventory">进入 Inventory OS</Link>
        </div>
        <div class="dashboard-placeholder-grid" aria-label="库存风险指标骨架">
          <article><span>库存预警</span><strong>—</strong></article>
          <article><span>没货</span><strong>—</strong></article>
          <article><span>库存紧张</span><strong>—</strong></article>
          <article><span>仓库数据缺口</span><strong>—</strong></article>
        </div>
      </section>

      <section v-if="visibility.sync" id="sync" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">SYNC</p><h3>同步健康</h3><p>人工同步和自动重试分开观察，不合并成虚假的总数。</p></div>
          <Link class="mvp-button" href="/sync">进入 Sync OS</Link>
        </div>
        <div class="dashboard-placeholder-grid" aria-label="同步健康指标骨架">
          <article><span>待同步</span><strong>—</strong></article>
          <article><span>重试到期</span><strong>—</strong></article>
          <article><span>同步失败</span><strong>—</strong></article>
          <article><span>冲突</span><strong>—</strong></article>
        </div>
      </section>

      <section v-if="visibility.finance" id="finance" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">FINANCE</p><h3>经营财务</h3><p>预估利润始终标记为“预估 · 非最终”，真实证据逐项替换后再升级。</p></div>
          <Link class="mvp-button" href="/accounting/order-costs">进入订单利润</Link>
        </div>
        <div class="dashboard-placeholder-grid" aria-label="经营财务指标骨架">
          <article><span>经营订单</span><strong>—</strong></article>
          <article><span>收入就绪</span><strong>—</strong></article>
          <article><span>已有预估利润</span><strong>—</strong></article>
          <article><span>最终利润覆盖率</span><strong>—</strong></article>
        </div>
        <div class="finance-preview">
          <div><span>经营趋势</span><strong>Revenue / Estimated Cost / Estimated Profit</strong></div>
          <small>金额趋势仅纳入可安全进入 USD 汇总的订单。</small>
        </div>
      </section>

      <section v-if="visibility.procurement" id="procurement" class="dashboard-section-shell procurement-section">
        <template v-if="procurementProgress && procurementProgress.active > 0">
          <div class="dashboard-section-heading">
            <div><p class="mvp-kicker">PURCHASE OS</p><h3>采购进度</h3><p>当前存在活跃采购，保留为运营工作区。</p></div>
            <Link class="mvp-button" href="/purchase">查看全部采购</Link>
          </div>
          <div class="dashboard-placeholder-grid procurement-stat-grid">
            <Link class="dashboard-live-card" href="/purchase"><span>活跃采购</span><strong>{{ procurementProgress.active }}</strong></Link>
            <Link v-for="item in statusMeta" :key="item.value" class="dashboard-live-card" :href="`/purchase?status=${item.value}`"><span>{{ item.label }}</span><strong>{{ procurementProgress.counts[item.value] }}</strong></Link>
          </div>
          <div class="procurement-recent">
            <Link v-for="row in procurementProgress.recent" :key="row.id" class="procurement-row" :href="`/purchase/orders/${row.id}`">
              <div class="procurement-id"><strong>{{ row.procurement_number }}</strong><span>源订单 {{ row.source_order_erp_id || '—' }}</span></div>
              <span class="procurement-status">{{ statusLabel(row.status) }}</span>
              <span class="procurement-logistics">{{ row.carrier || '暂无物流' }}<template v-if="row.tracking_number"> · {{ row.tracking_number }}</template></span>
            </Link>
          </div>
        </template>
        <Link v-else class="procurement-quiet" href="/purchase">
          <div><p class="mvp-kicker">PURCHASE OS</p><h3>采购</h3><p>当前无活跃采购，不占用首页主视线。</p></div>
          <span>进入 Purchase OS →</span>
        </Link>
      </section>

      <section v-if="!visibility.operations && !visibility.inventory && !visibility.sync && !visibility.finance && !visibility.procurement" class="dashboard-empty">
        当前账号暂无可展示的业务总览模块。
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-shell-page{display:grid;gap:18px;max-width:1440px;margin:0 auto;color:var(--byl-text)}
.dashboard-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;padding:6px 0 2px}
.dashboard-hero h2{margin:6px 0;font-size:30px;letter-spacing:-.02em}
.dashboard-hero>div>p:not(.mvp-kicker){margin:0;max-width:720px;color:var(--byl-text-secondary);line-height:1.7}
.dashboard-period{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.period-option,.dashboard-section-nav a{display:inline-flex;align-items:center;min-height:36px;padding:0 12px;border:1px solid var(--byl-border-control);border-radius:var(--byl-radius-round);background:var(--byl-surface);color:var(--byl-text-secondary);font-size:12px;text-decoration:none}
.period-option.active{border-color:var(--byl-accent);background:var(--byl-readonly-bg);color:var(--byl-accent);font-weight:700}
.dashboard-custom-period{display:flex;align-items:end;gap:10px;padding:14px 16px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-xl);background:var(--byl-surface)}
.dashboard-custom-period label{display:grid;gap:5px;color:var(--byl-text-muted);font-size:11px}
.dashboard-custom-period input{min-height:38px;border:1px solid var(--byl-border-control);border-radius:var(--byl-radius-md);padding:0 10px;background:var(--byl-surface);color:var(--byl-text);font:inherit}
.dashboard-section-nav{display:flex;gap:8px;overflow-x:auto;padding:2px 0 4px;scrollbar-width:none}
.dashboard-section-nav::-webkit-scrollbar{display:none}
.dashboard-section-nav a{white-space:nowrap}
.dashboard-section-nav a:hover{border-color:var(--byl-accent);color:var(--byl-accent)}
.dashboard-section-shell{scroll-margin-top:86px;padding:20px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-xl);background:var(--byl-surface);box-shadow:var(--byl-shadow-card)}
.dashboard-section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:16px}
.dashboard-section-heading h3,.procurement-quiet h3{margin:5px 0 6px;font-size:20px}
.dashboard-section-heading p:not(.mvp-kicker),.procurement-quiet p:not(.mvp-kicker){margin:0;color:var(--byl-text-secondary);font-size:13px}
.dashboard-placeholder-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.dashboard-placeholder-grid-five{grid-template-columns:repeat(5,minmax(0,1fr))}
.dashboard-placeholder-grid article,.dashboard-live-card{display:block;min-width:0;padding:15px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-readonly-bg);color:inherit;text-decoration:none}
.dashboard-placeholder-grid span,.dashboard-live-card span{display:block;color:var(--byl-text-muted);font-size:11px}
.dashboard-placeholder-grid strong,.dashboard-live-card strong{display:block;margin-top:8px;font-size:25px;color:var(--byl-text)}
.finance-preview{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:12px;padding:16px;border:1px dashed var(--byl-border-control);border-radius:var(--byl-radius-lg)}
.finance-preview div{display:grid;gap:4px}.finance-preview span,.finance-preview small{color:var(--byl-text-muted);font-size:11px}.finance-preview strong{font-size:13px}
.procurement-section{padding:0;overflow:hidden}
.procurement-section>.dashboard-section-heading,.procurement-section>.dashboard-placeholder-grid,.procurement-section>.procurement-recent{margin-left:20px;margin-right:20px}
.procurement-section>.dashboard-section-heading{margin-top:20px}
.procurement-recent{display:grid;gap:8px;padding:0 0 20px}
.procurement-row{display:grid;grid-template-columns:minmax(180px,1.2fr) 100px minmax(180px,1fr);gap:14px;align-items:center;padding:12px 14px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);text-decoration:none;color:inherit}
.procurement-id{display:grid;gap:3px}.procurement-id span,.procurement-logistics{font-size:12px;color:var(--byl-text-secondary)}.procurement-status{font-size:12px;font-weight:700}
.procurement-quiet{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;color:inherit;text-decoration:none}
.procurement-quiet span{color:var(--byl-accent);font-size:12px;font-weight:700}
.dashboard-empty{padding:36px;border:1px dashed var(--byl-border-control);border-radius:var(--byl-radius-xl);color:var(--byl-text-muted);text-align:center}
@media(max-width:1050px){.dashboard-placeholder-grid-five{grid-template-columns:repeat(3,minmax(0,1fr))}.dashboard-placeholder-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.dashboard-shell-page{gap:14px}.dashboard-hero{align-items:flex-start;flex-direction:column}.dashboard-hero h2{font-size:25px}.dashboard-period{justify-content:flex-start;width:100%;flex-wrap:nowrap;overflow-x:auto}.period-option{white-space:nowrap}.dashboard-custom-period{display:grid;grid-template-columns:1fr 1fr}.dashboard-custom-period button{grid-column:1/-1}.dashboard-section-shell{padding:16px}.dashboard-section-heading{display:grid}.dashboard-section-heading .mvp-button{justify-self:start}.dashboard-placeholder-grid,.dashboard-placeholder-grid-five{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-preview{align-items:flex-start;flex-direction:column}.procurement-section{padding:0}.procurement-row{grid-template-columns:1fr;gap:5px}.procurement-quiet{align-items:flex-start;flex-direction:column}}
@media(max-width:430px){.dashboard-placeholder-grid,.dashboard-placeholder-grid-five{grid-template-columns:1fr}.dashboard-custom-period{grid-template-columns:1fr}.dashboard-custom-period button{grid-column:auto}}
</style>
