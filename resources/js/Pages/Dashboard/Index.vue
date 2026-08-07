<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type MetricKey = 'pending_orders' | 'pending_shipment' | 'shipped_pending_sync' | 'completed_today'
type MetricGroup = 'priority' | 'completed'

const props = defineProps<{ metrics: Record<MetricKey, number> }>()

const metricCards = computed(() => ([
    { key: 'pending_orders', label: '待处理订单', description: '需要确认并进入发货流程', href: '/customers/orders?order_status=pending_shipment', group: 'priority' },
    { key: 'pending_shipment', label: '待发货', description: '已确认订单中的待发货项', href: '/logistics/pending', group: 'priority' },
    { key: 'shipped_pending_sync', label: '已发货待同步网站', description: '等待手动同步到网站', href: '/sync?status=pending', group: 'priority' },
    { key: 'completed_today', label: '今日已完成', description: '今日已完成的人工同步', href: '/sync?status=completed', group: 'completed' },
] satisfies ReadonlyArray<{ key: MetricKey; label: string; description: string; href: string; group: MetricGroup }>).map((card) => ({ ...card, value: props.metrics[card.key] })))

const priorityCards = computed(() => metricCards.value.filter((card) => card.group === 'priority'))
const completedCards = computed(() => metricCards.value.filter((card) => card.group === 'completed'))
const openTotal = computed(() => priorityCards.value.reduce((total, card) => total + card.value, 0))
</script>

<template>
  <Head title="Dashboard · BYL ERP" />
  <AuthenticatedLayout title="Dashboard" :breadcrumbs="['BYL ERP', 'Dashboard']">
    <section class="dashboard-page" aria-labelledby="dashboard-title">
      <header class="dashboard-heading">
        <div>
          <p class="dashboard-eyebrow">业务工作台 / 运营概览</p>
          <h1 id="dashboard-title">运营总览</h1>
          <p class="dashboard-intro">快速查看订单、发货与人工同步的当前进度。</p>
        </div>
        <p class="dashboard-status" :class="{ 'status-clear': openTotal === 0 }" data-testid="dashboard-status">
          <span class="status-dot" aria-hidden="true"></span>
          <span v-if="openTotal > 0">待处理合计 <strong class="status-total" data-testid="dashboard-open-total">{{ openTotal }}</strong> 项</span>
          <span v-else>待办事项均已清空</span>
        </p>
      </header>

      <section class="dashboard-section" aria-labelledby="dashboard-priority-heading">
        <div class="section-heading">
          <div>
            <p class="section-index">01</p>
            <h2 id="dashboard-priority-heading" data-testid="dashboard-priority-heading">需要关注</h2>
          </div>
          <p>优先处理会阻塞后续流程的事项</p>
        </div>
        <div class="metric-grid metric-grid-priority">
          <a v-for="card in priorityCards" :key="card.key" :href="card.href" class="metric-card" :class="{ 'is-clear': card.value === 0 }" data-metric-card :data-testid="`metric-${card.key.replaceAll('_', '-')}`">
            <span class="metric-icon" aria-hidden="true">
              <svg v-if="card.value === 0" viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6" /></svg>
              <svg v-else-if="card.key === 'pending_orders'" viewBox="0 0 24 24" fill="none"><path d="M5 5.5h14v13H5zM8 9h8M8 12h8M8 15h5" /></svg>
              <svg v-else-if="card.key === 'pending_shipment'" viewBox="0 0 24 24" fill="none"><path d="m4 7 8-4 8 4v10l-8 4-8-4zM4 7l8 4 8-4M12 11v10" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="none"><path d="M4 12h13M13 7l5 5-5 5M4 5v14" /></svg>
            </span>
            <span class="metric-copy"><span class="metric-label">{{ card.label }}</span><span class="metric-description">{{ card.description }}</span></span>
            <strong class="metric-value" data-testid="metric-value">{{ card.value }}</strong>
            <span class="metric-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" /></svg></span>
          </a>
        </div>
      </section>

      <section class="dashboard-section" aria-labelledby="dashboard-completed-heading">
        <div class="section-heading">
          <div>
            <p class="section-index">02</p>
            <h2 id="dashboard-completed-heading" data-testid="dashboard-completed-heading">今日完成</h2>
          </div>
          <p>已完成的人工同步记录</p>
        </div>
        <div class="metric-grid">
          <a v-for="card in completedCards" :key="card.key" :href="card.href" class="metric-card metric-card-complete" :class="{ 'is-active': card.value > 0 }" data-metric-card :data-testid="`metric-${card.key.replaceAll('_', '-')}`">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6" /></svg></span>
            <span class="metric-copy"><span class="metric-label">{{ card.label }}</span><span class="metric-description">{{ card.description }}</span></span>
            <strong class="metric-value" data-testid="metric-value">{{ card.value }}</strong>
            <span class="metric-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" /></svg></span>
          </a>
        </div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-page { min-height: 100%; padding: var(--byl-space-28) clamp(var(--byl-space-20), 4vw, var(--byl-space-56)) var(--byl-space-48); color: var(--byl-color-shell-ink); background: var(--byl-color-shell-canvas); }
.dashboard-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--byl-space-24); max-width: 1180px; margin: 0 auto var(--byl-space-28); padding-bottom: var(--byl-space-24); border-bottom: 1px solid var(--byl-color-shell-border-subtle); }
.dashboard-eyebrow, .section-index { margin: 0 0 var(--byl-space-8); color: var(--byl-color-shell-accent-hover); font-size: var(--byl-font-size-12); font-weight: var(--byl-font-weight-semibold); letter-spacing: .08em; }
.dashboard-heading h1 { margin: 0; font-size: clamp(var(--byl-font-size-24), 3vw, var(--byl-font-size-36)); line-height: var(--byl-line-tight); letter-spacing: -.035em; }
.dashboard-intro { margin: var(--byl-space-10) 0 0; color: var(--byl-color-shell-text-secondary); font-size: var(--byl-font-size-14); }
.dashboard-status { display: flex; align-items: center; gap: var(--byl-space-8); margin: var(--byl-space-4) 0 0; padding: var(--byl-space-6) var(--byl-space-12); color: var(--byl-color-shell-text-secondary); font-size: var(--byl-font-size-12); white-space: nowrap; background: var(--byl-color-white); border: 1px solid var(--byl-color-shell-border-subtle); border-radius: var(--byl-radius-round); }
.status-dot { width: 7px; height: 7px; border-radius: var(--byl-radius-round); background: var(--byl-color-shell-accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--byl-color-shell-accent) 12%, transparent); }
.dashboard-status.status-clear { color: var(--byl-color-shell-text-muted); }
.dashboard-status.status-clear .status-dot { background: var(--byl-color-shell-border-control); box-shadow: none; }
.status-total { color: var(--byl-color-shell-ink); font-size: var(--byl-font-size-14); font-weight: var(--byl-font-weight-semibold); font-variant-numeric: tabular-nums; }
.dashboard-section { max-width: 1180px; margin: 0 auto var(--byl-space-28); }
.section-heading { display: flex; justify-content: space-between; align-items: end; gap: var(--byl-space-20); margin-bottom: var(--byl-space-12); }
.section-heading h2 { margin: 0; font-size: var(--byl-font-size-18); letter-spacing: -.02em; }
.section-heading > p { margin: 0 0 var(--byl-space-4); color: var(--byl-color-shell-text-secondary); font-size: var(--byl-font-size-12); }
.section-index { margin-bottom: var(--byl-space-4); font-size: var(--byl-font-size-10); }
.metric-grid { display: grid; gap: var(--byl-space-10); }
.metric-grid-priority { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.metric-card { position: relative; display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: var(--byl-space-14); min-height: 96px; padding: var(--byl-space-16) var(--byl-space-20); color: inherit; text-decoration: none; background: var(--byl-color-white); border: 1px solid var(--byl-color-shell-border-subtle); border-left: 3px solid var(--byl-color-shell-accent); border-radius: var(--byl-radius-md); box-shadow: var(--byl-shadow-card); transition: border-color var(--byl-motion-fast) var(--byl-motion-ease), box-shadow var(--byl-motion-fast) var(--byl-motion-ease), transform var(--byl-motion-fast) var(--byl-motion-ease); }
.metric-card:hover { border-color: var(--byl-color-shell-accent); box-shadow: 0 8px 20px color-mix(in srgb, var(--byl-color-shell-ink) 10%, transparent); transform: translateY(-1px); }
.metric-card:active { transform: translateY(1px); }
.metric-card:focus-visible { outline: var(--byl-focus-width) solid var(--byl-color-shell-focus); outline-offset: var(--byl-focus-offset); }
.metric-icon { display: grid; place-items: center; width: 38px; height: 38px; color: var(--byl-color-shell-accent); background: color-mix(in srgb, var(--byl-color-shell-accent) 11%, var(--byl-color-white)); border-radius: var(--byl-radius-sm); }
.metric-icon svg { width: 19px; height: 19px; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.metric-copy { display: grid; gap: var(--byl-space-4); min-width: 0; }
.metric-label { font-size: var(--byl-font-size-14); font-weight: var(--byl-font-weight-semibold); }
.metric-description { overflow: hidden; color: var(--byl-color-shell-text-secondary); font-size: var(--byl-font-size-12); text-overflow: ellipsis; white-space: nowrap; }
.metric-value { color: var(--byl-color-shell-ink); font-size: var(--byl-font-size-30); font-variant-numeric: tabular-nums; line-height: 1; }
.metric-arrow { align-self: start; color: var(--byl-color-shell-accent); }
.metric-arrow svg { display: block; width: var(--byl-space-16); height: var(--byl-space-16); stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.metric-card.is-clear { border-left-color: var(--byl-color-shell-border-subtle); }
.metric-card.is-clear:hover { border-color: var(--byl-color-shell-border-control); }
.metric-card.is-clear .metric-icon { color: var(--byl-color-shell-text-muted); background: var(--byl-color-shell-canvas); }
.metric-card.is-clear .metric-value { color: var(--byl-color-shell-text-muted); }
.metric-card.is-clear .metric-arrow { color: var(--byl-color-shell-border-control); }
.metric-card-complete { border-left-color: var(--byl-color-shell-border-subtle); }
.metric-card-complete .metric-icon { color: var(--byl-color-shell-text-muted); background: var(--byl-color-shell-canvas); }
.metric-card-complete .metric-value { color: var(--byl-color-shell-text-muted); }
.metric-card-complete .metric-arrow { color: var(--byl-color-shell-border-control); }
.metric-card-complete.is-active { background: color-mix(in srgb, var(--byl-color-shell-accent) 7%, var(--byl-color-white)); border-left-color: var(--byl-color-shell-accent); }
.metric-card-complete.is-active .metric-icon { color: var(--byl-color-shell-accent); background: color-mix(in srgb, var(--byl-color-shell-accent) 13%, var(--byl-color-white)); }
.metric-card-complete.is-active .metric-value { color: var(--byl-color-shell-accent-hover); }
.metric-card-complete.is-active .metric-arrow { color: var(--byl-color-shell-accent); }
@media (max-width: 900px) { .metric-grid-priority { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .dashboard-page { padding: var(--byl-space-22) var(--byl-space-16) var(--byl-space-30); } .dashboard-heading, .section-heading { display: grid; gap: var(--byl-space-14); } .dashboard-heading { margin-bottom: var(--byl-space-26); } .dashboard-status { justify-self: start; } .metric-grid-priority { grid-template-columns: 1fr; } .metric-card { min-height: 88px; padding: var(--byl-space-14) var(--byl-space-16); } .metric-description { white-space: normal; } }
@media (prefers-reduced-motion: reduce) { .metric-card { transition: none; } .metric-card:hover, .metric-card:active { transform: none; } }
</style>
