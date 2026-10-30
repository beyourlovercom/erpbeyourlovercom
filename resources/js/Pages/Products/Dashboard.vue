<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Metrics = {
  erpActive: number
  wooPublishedSnapshot: number
  needsData: number
  pendingReview: number
}
type RecentProduct = { id: number; sku: string | null; name: string; updated_at: string | null }
type AttentionKey =
  | 'missing_image' | 'missing_brand' | 'missing_barcode_69'
  | 'missing_shipping_weight' | 'missing_dimensions' | 'pending_review'

const props = defineProps<{
  totalProducts: number
  metrics: Metrics
  attentionCounts: Record<string, number>
  recentProducts: RecentProduct[]
  canOperate: boolean
}>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '产品管理']
const search = ref('')
const attentionItems: { key: AttentionKey; label: string; hint: string }[] = [
  { key: 'missing_image', label: '未关联 Woo 主图', hint: '按当前 Woo 连接检查主图关联，不等于文件损坏' },
  { key: 'missing_brand', label: '缺少品牌', hint: '产品主表 Brand 字段为空' },
  { key: 'missing_barcode_69', label: '缺少 69 条码', hint: '制造商产品识别码待补充' },
  { key: 'missing_shipping_weight', label: '缺少运重', hint: '发货重量尚未登记' },
  { key: 'missing_dimensions', label: '缺少商品尺寸', hint: '商品长、宽、高存在空项' },
  { key: 'pending_review', label: '工厂资料待审核', hint: '存在已提交、尚未审核的资料记录' },
]
const recentCount = computed(() => props.recentProducts.length)
// Order Detail-style sticky left navigation, with a reserved gutter so
// it cannot overlap ERP's persistent module sidebar at desktop widths.
const sections = [
  { id: 'product-section-overview', label: '产品概览' },
  { id: 'product-section-shortcuts', label: '快捷操作' },
  { id: 'product-section-attention', label: '待办与更新' },
] as const
type SectionId = typeof sections[number]['id']
const activeSection = ref<SectionId>('product-section-overview')
const updateActiveSection = () => {
  if (typeof document === 'undefined') return
  const doc = document.scrollingElement ?? document.documentElement
  if (doc.scrollHeight > window.innerHeight && window.scrollY + window.innerHeight >= doc.scrollHeight - 12) {
    activeSection.value = sections[sections.length - 1].id
    return
  }
  let current: SectionId = sections[0].id
  for (const section of sections) {
    const target = document.getElementById(section.id)
    if (target && target.getBoundingClientRect().top <= 148) current = section.id
  }
  activeSection.value = current
}
const jumpToSection = (id: SectionId) => {
  const target = document.getElementById(id)
  if (!target) return
  activeSection.value = id
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
onMounted(() => {
  updateActiveSection()
  window.addEventListener('scroll', updateActiveSection, { passive: true })
  window.addEventListener('resize', updateActiveSection)
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateActiveSection)
  window.removeEventListener('resize', updateActiveSection)
})

const applySearch = () => {
  const value = search.value.trim()
  if (value) router.get('/products/list', { search: value })
  else router.get('/products/list')
}
</script>

<template>
  <Head title="产品管理 · BYL ERP" />
  <AuthenticatedLayout title="产品管理" :breadcrumbs="breadcrumbs">
    <section class="mvp-page product-home">
      <div class="product-home-layout">
        <aside class="product-home-nav-rail" aria-label="产品模块快捷定位">
          <nav class="product-home-nav" aria-label="产品工作台页面导航">
            <span class="product-home-nav-caption">快捷定位</span>
            <button v-for="section in sections" :key="section.id" type="button"
              class="product-home-nav-item" :class="{ 'is-active': activeSection === section.id }"
              :aria-current="activeSection === section.id ? 'location' : undefined"
              @click="jumpToSection(section.id)">{{ section.label }}</button>
            <button type="button" class="product-home-nav-top"
              aria-label="返回产品页面顶部" @click="jumpToSection('product-section-overview')">↑ 返回顶部</button>
          </nav>
        </aside>
        <div class="product-home-content">
      <div id="product-section-overview" class="product-home-heading">
        <div>
          <p class="mvp-kicker">PRODUCT OS · 产品主数据</p>
          <h2>产品管理工作台</h2>
          <p class="product-home-subtitle">查看产品概况、补齐资料、快速进入产品 360</p>
        </div>
        <div class="product-home-actions">
          <a class="mvp-button mvp-button-secondary" href="/products/list">全部产品</a>
          <details v-if="canOperate" class="product-home-tools">
            <summary class="mvp-button mvp-button-secondary">管理工具 ▾</summary>
            <nav aria-label="产品管理工具">
              <a href="/products/import">导入 CSV</a>
              <a href="/products/barcode-69/import">导入 69 条码</a>
              <a href="/products/weight-evidence">重量证据管理</a>
            </nav>
          </details>
          <a v-if="canOperate" class="mvp-button mvp-button-primary" href="/products/create">+ 新增产品</a>
        </div>
      </div>

      <form class="product-home-search" role="search" @submit.prevent="applySearch">
        <span class="product-home-search-icon" aria-hidden="true">⌕</span>
        <input v-model="search" type="search" aria-label="搜索产品 SKU、名称或 69 条码"
          placeholder="搜索 SKU / 产品名称 / 69 条码 / GTIN" />
        <button type="submit">搜索产品</button>
      </form>

      <div class="product-home-stats" aria-label="真实产品数据概览">
        <a class="product-home-stat" href="/products/list">
          <small>产品总数</small>
          <strong>{{ totalProducts }}</strong>
          <span>ERP 产品主表 <b>›</b></span>
        </a>
        <article class="product-home-stat">
          <small>ERP 已启用</small>
          <strong>{{ metrics.erpActive }}</strong>
          <span>仅代表 ERP 启用状态，不等于 Woo 已上架</span>
        </article>
        <article class="product-home-stat">
          <small>Woo 已发布 · 最近快照</small>
          <strong>{{ metrics.wooPublishedSnapshot }}</strong>
          <span>依据当前 Woo 连接已保存的发布状态快照</span>
        </article>
        <a class="product-home-stat product-home-stat-attention" href="/products/list?attention=needs_data">
          <small>资料需补齐</small>
          <strong>{{ metrics.needsData }}</strong>
          <span>图片 / 品牌 / 条码 / 运重 / 商品尺寸 <b>›</b></span>
        </a>
      </div>

      <div id="product-section-shortcuts" class="product-home-section-head">
        <h3>快捷操作</h3>
        <span>复用现有功能 · 不建立第二份产品数据</span>
      </div>
      <div class="product-home-shortcuts">
        <a class="product-home-shortcut" href="/products/list">
          <span class="shortcut-icon">▦</span><span><strong>产品列表</strong><small>完整产品资料与 XLSX 导出</small></span>
        </a>
        <a class="product-home-shortcut" href="/products/list?view=measurements">
          <span class="shortcut-icon">▤</span><span><strong>重量与尺寸</strong><small>工厂参数与资料状态</small></span>
        </a>
        <a class="product-home-shortcut" href="/products/list?view=accessories">
          <span class="shortcut-icon">▧</span><span><strong>包装与配件</strong><small>查看已登记的包装配件</small></span>
        </a>
        <a class="product-home-shortcut" href="/products/list?attention=missing_barcode_69">
          <span class="shortcut-icon">＃</span><span><strong>69 条码</strong><small>查看缺少制造商识别码的 SKU</small></span>
        </a>
      </div>

      <div class="product-home-main">
        <section id="product-section-attention" class="product-home-panel">
          <div class="product-home-panel-head">
            <div>
              <h3>需要关注的产品</h3>
              <p>点击条目，直接打开已筛选的产品列表</p>
            </div>
            <span class="product-home-pill">{{ metrics.pendingReview }} 件待审核</span>
          </div>
          <div class="product-home-tasks">
            <a v-for="task in attentionItems" :key="task.key"
              class="product-home-task" :href="'/products/list?attention=' + task.key">
              <span class="product-home-task-marker" aria-hidden="true"></span>
              <span class="product-home-task-body">
                <strong>{{ task.label }}</strong>
                <small>{{ task.hint }}</small>
              </span>
              <span class="product-home-task-count">{{ attentionCounts[task.key] ?? 0 }}</span>
              <span class="product-home-chevron" aria-hidden="true">›</span>
            </a>
          </div>
          <p class="product-home-note">“未关联主图”是元数据检查；未知库存不会被当作 0，也不在此页误报缺货。</p>
        </section>

        <section id="product-section-recent" class="product-home-panel">
          <div class="product-home-panel-head">
            <div>
              <h3>最近更新的产品</h3>
              <p>按 ERP 产品记录更新时间排序</p>
            </div>
            <a class="product-home-more" href="/products/list">查看全部 ›</a>
          </div>
          <div v-if="recentCount" class="product-home-recents">
            <div v-for="p in recentProducts" :key="p.id" class="product-home-recent">
              <span class="product-home-product-icon" aria-hidden="true">P</span>
              <span class="product-home-recent-main">
                <a :href="'/products/' + p.id">{{ p.name }}</a>
                <small>{{ p.sku || 'SKU 未填写' }} · {{ p.updated_at || '更新时间未知' }}</small>
              </span>
              <a v-if="canOperate" class="product-home-edit" :href="'/products/' + p.id + '/edit'">编辑</a>
            </div>
          </div>
          <div v-else class="product-home-empty">
            <strong>暂无产品记录</strong>
            <p>导入或创建产品后，这里会显示最新更新的记录。</p>
          </div>
        </section>
      </div>

      <a class="product-home-alias-link" href="/products/sku-aliases">SKU 别名管理 ›</a>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.product-home{max-width:none;color:#232c32;padding:20px 18px 32px}
.product-home-layout{width:100%;margin:0 auto;display:grid;grid-template-columns:126px minmax(0,1080px);column-gap:5px;justify-content:center;align-items:start}
.product-home-content{grid-column:2;grid-row:1;min-width:0;width:100%;max-width:1080px;margin:0 auto}
.product-home-nav-rail{grid-column:1;grid-row:1;position:sticky;top:108px;align-self:start;justify-self:end;width:126px;min-width:0;z-index:5}
.product-home-nav{display:flex;flex-direction:column;padding:12px 8px;border:1px solid #e4eaed;border-radius:12px;background:#fff;box-shadow:0 2px 7px #14212609;max-height:calc(100vh - 110px);overflow-y:auto}
.product-home-nav-caption{color:#829198;font-size:11px;font-weight:600;padding:2px 10px 8px}
.product-home-nav-item,.product-home-nav-top{text-align:left;border:0;border-radius:7px;background:transparent;color:#556a73;font-size:12px;padding:8px 10px;line-height:1.35;cursor:pointer}
.product-home-nav-item:hover,.product-home-nav-top:hover{background:#f9f1f4}
.product-home-nav-item.is-active{color:#81334e;background:#faedf2;font-weight:700;box-shadow:inset 2px 0 #81334e}
.product-home-nav-item:focus-visible,.product-home-nav-top:focus-visible{outline:2px solid #81334e;outline-offset:-2px}
.product-home-nav-top{color:#607880;border-top:1px solid #edf0f2;margin-top:6px;padding-top:11px}
.product-home [id^="product-section-"]{scroll-margin-top:92px}
@media(max-width:1250px){.product-home-layout{grid-template-columns:112px minmax(0,1080px)}.product-home-nav-rail{width:112px}}

.product-home-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:18px}
.product-home-heading h2{font-size:24px;letter-spacing:-.4px;margin:3px 0 4px}
.product-home-subtitle{color:#6d7781;font-size:13px;margin:0}
.product-home-actions{display:flex;align-items:center;gap:8px;white-space:nowrap}
.product-home-tools{position:relative}
.product-home-tools summary{cursor:pointer;list-style:none}
.product-home-tools summary::-webkit-details-marker{display:none}
.product-home-tools nav{position:absolute;z-index:30;right:0;top:calc(100% + 7px);min-width:174px;background:#fff;border:1px solid #e8dfe2;border-radius:9px;box-shadow:0 8px 26px #31252b20;padding:6px}
.product-home-tools nav a{display:block;text-decoration:none;color:#4f3741;font-size:12px;padding:9px;border-radius:6px}
.product-home-tools nav a:hover{background:#fff4f7}
.product-home-alias-link{display:inline-block;margin:14px 5px 2px;font-size:12px;text-decoration:none;color:#81334e}
.product-home-search{display:flex;align-items:center;gap:12px;width:100%;padding:7px 7px 7px 15px;background:#fff;border:1px solid #dfe3e7;border-radius:10px;box-shadow:0 2px 9px #25304406}
.product-home-search:focus-within{border-color:#8e425c}
.product-home-search-icon{font-size:24px;line-height:1;color:#86929a}
.product-home-search input{flex:1;min-width:0;font-size:14px;border:0;outline:0;background:transparent;color:#263038}
.product-home-search button{background:#81334e;color:#fff;font-weight:650;font-size:13px;border:0;border-radius:7px;padding:11px 18px;cursor:pointer}
.product-home-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:18px}
.product-home-stat{background:#fff;border:1px solid #e5e7e9;border-radius:12px;min-height:128px;padding:18px;display:flex;flex-direction:column;gap:10px;text-decoration:none;color:inherit}
.product-home-stat small{font-size:12px;color:#68747b;font-weight:650}
.product-home-stat strong{font-size:30px;font-weight:740;line-height:1.1;font-variant-numeric:tabular-nums}
.product-home-stat span{color:#8b929a;font-size:11px;line-height:1.4}
.product-home-stat b{font-size:15px;color:#81334e}
.product-home-stat-attention{border-color:#e8d7dd;background:#fffbfc}
.product-home-stat-attention strong{color:#81334e}
.product-home-section-head{display:flex;align-items:baseline;gap:14px;margin:21px 0 11px}
.product-home-section-head h3,.product-home-panel-head h3{font-size:15px;font-weight:720;margin:0}
.product-home-section-head span{font-size:11px;color:#879097}
.product-home-shortcuts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.product-home-shortcut{display:flex;align-items:center;gap:11px;background:#fff;border:1px solid #e5e7e9;border-radius:10px;padding:15px;text-decoration:none;color:#263038;min-width:0}
.product-home-shortcut:hover,.product-home-task:hover{border-color:#cda6b2;background:#fffbfc}
.shortcut-icon{display:grid;place-items:center;width:38px;height:38px;flex:none;border-radius:9px;background:#f7edf1;color:#84334f;font-weight:700;font-size:20px}
.product-home-shortcut span:last-child{display:flex;flex-direction:column;gap:5px;min-width:0}
.product-home-shortcut strong{font-size:13px;white-space:nowrap}
.product-home-shortcut small{font-size:11px;color:#869099;line-height:1.35}
.product-home-main{display:grid;grid-template-columns:1.15fr 1fr;gap:15px;margin-top:19px;align-items:start}
.product-home-panel{min-width:0;background:#fff;border:1px solid #e5e7e9;border-radius:12px;overflow:hidden}
.product-home-panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:17px 18px;border-bottom:1px solid #f0f1f3}
.product-home-panel-head p{margin:6px 0 0;font-size:11px;color:#889199}
.product-home-pill{white-space:nowrap;background:#fff5ec;color:#9b5b18;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:650}
.product-home-task{display:flex;align-items:center;gap:11px;padding:12px 18px;min-height:66px;border-bottom:1px solid #f0f1f3;text-decoration:none;color:inherit}
.product-home-task:last-child{border-bottom:0}
.product-home-task-marker{width:8px;height:8px;border-radius:50%;background:#c58f9f;flex:none}
.product-home-task-body{min-width:0;flex:1;display:flex;flex-direction:column;gap:4px}
.product-home-task-body strong{font-size:12px}
.product-home-task-body small{font-size:11px;color:#879099}
.product-home-task-count{min-width:30px;text-align:right;font-size:16px;font-weight:750;font-variant-numeric:tabular-nums}
.product-home-chevron{font-size:19px;color:#aeb6bc}
.product-home-note{padding:12px 18px;margin:0;color:#8c9399;font-size:11px;border-top:1px solid #f1f2f3;line-height:1.5}
.product-home-more{font-size:12px;color:#81334e;text-decoration:none;white-space:nowrap}
.product-home-recents{padding:2px 0}
.product-home-recent{display:flex;align-items:center;gap:11px;padding:11px 16px;border-bottom:1px solid #f0f1f3;min-width:0}
.product-home-recent:last-child{border-bottom:0}
.product-home-product-icon{width:35px;height:35px;border-radius:8px;background:#f2f4f6;display:grid;place-items:center;font-size:15px;font-weight:750;color:#9ca5ae;flex:none}
.product-home-recent-main{flex:1;display:flex;flex-direction:column;min-width:0;gap:5px}
.product-home-recent-main a{color:#28343a;font-size:12px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
.product-home-recent-main a:hover{color:#81334e}
.product-home-recent-main small{font-size:10px;color:#8a949b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-home-edit{font-size:12px;color:#81334e;text-decoration:none}
.product-home-empty{padding:50px 18px;text-align:center;font-size:12px;color:#7d8890}
.product-home-empty p{margin-top:8px}
.product-home-bottom-actions{display:flex;gap:16px;justify-content:flex-end;margin:15px 4px 0;font-size:12px}
.product-home-bottom-actions a{color:#7f4255;text-decoration:none}
</style>
