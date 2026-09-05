<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type KolRow = {
  id: number
  display_name: string
  country_code: string | null
  status: string
  relationship_stage: string
  owner: { id: number; name: string } | null
  primary_platform: string | null
  latest_followers: number | null
  is_100k_plus: boolean
  joined_at: string | null
  level: string | null
  categories: string[]
  products: string[]
  content_format: string | null
  product_quantity: number | null
  internal_contact: string | null
  mcn_agency: string | null
  is_mcn_managed: boolean
  historical_order_count: number
  has_published_content: boolean
  affiliate_account: {
    affiliate_id: number
    wordpress_user_id: number | null
    wordpress_user_login: string | null
  } | null
}

type SavedView = {
  id: number
  name: string
  view_type: string
  visibility: string
  filters_json: Record<string, string>
  is_pinned: boolean
  created_by: number
}

type OwnerOption = { value: number; label: string }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{
  kols: Paginated<KolRow>
  filters: Record<string, string>
  savedViews: SavedView[]
  ownerOptions: OwnerOption[]
  canOperate: boolean
  canManageViews: boolean
  isAllScope: boolean
}>()

const filters = reactive({
  search: props.filters.search ?? '',
  owner_id: props.filters.owner_id ?? '',
  status: props.filters.status ?? '',
  relationship_stage: props.filters.relationship_stage ?? '',
  country_code: props.filters.country_code ?? '',
  platform: props.filters.platform ?? '',
  followers_min: props.filters.followers_min ?? '',
  followers_max: props.filters.followers_max ?? '',
  level: props.filters.level ?? '',
  content_format: props.filters.content_format ?? '',
  mcn: props.filters.mcn ?? '',
  has_published_content: props.filters.has_published_content ?? '',
  publication_evidence: props.filters.publication_evidence ?? '',
  view: props.filters.view ?? '',
  sort: props.filters.sort ?? 'display_name',
  direction: props.filters.direction ?? 'asc',
})

const showNewViewModal = ref(false)
const newViewName = ref('')
const newViewVisibility = ref('private')
const newViewPinned = ref(false)

const submit = () => router.get('/kol/library', filters, { preserveState: true, replace: true })
const goToPage = (page: number) => router.get('/kol/library', { ...filters, page }, { preserveState: true, replace: true })
const applyView = (view: SavedView) => {
  Object.assign(filters, view.filters_json)
  filters.view = ''
  submit()
}
const applySystemView = (viewKey: string) => {
  Object.keys(filters).forEach(k => { (filters as Record<string, string>)[k] = '' })
  filters.view = viewKey
  filters.sort = 'display_name'
  filters.direction = 'asc'
  submit()
}

const stageLabel = (s: string) => ({ prospect: '潜在', contacted: '已联系', negotiating: '谈判中', cooperating: '合作中', long_term: '长期合作', paused: '暂停', ended: '已结束' }[s] ?? s)
const platformLabel = (p: string | null) => ({ instagram: 'INS', tiktok: 'TK', youtube: 'YTB', xiaohongshu: '小红书', weibo: '微博', wechat: '微信', twitter: 'X', facebook: 'FB', other: '其他' }[p ?? ''] ?? p ?? '—')
const followersLabel = (n: number | null) => n === null ? '—' : n >= 1000000 ? `${(n / 1000000).toFixed(1)}M` : n >= 1000 ? `${(n / 1000).toFixed(1)}K` : String(n)
const tagList = (values: string[], limit = 2) => values.length === 0 ? '—' : values.slice(0, limit).join('、') + (values.length > limit ? ` +${values.length - limit}` : '')

const systemViews = computed(() => {
  const views = [{ key: 'my_kols', label: '我的红人' }]
  if (props.isAllScope) {
    views.push({ key: 'all_kols', label: '全部红人' })
  }
  views.push(
    { key: 'prospect', label: '待跟进' },
    { key: 'negotiating', label: '沟通中' },
    { key: 'cooperating', label: '已合作' },
    { key: 'long_term', label: '长期合作' },
  )
  if (props.isAllScope) {
    views.push({ key: '100k_plus', label: '100K+' })
  }
  return views
})

const pinnedViews = computed(() => props.savedViews.filter(v => v.is_pinned))

const saveNewView = () => {
  router.post('/kol/views', {
    name: newViewName.value,
    visibility: newViewVisibility.value,
    filters_json: { ...filters },
    is_pinned: newViewPinned.value,
  }, {
    onSuccess: () => {
      showNewViewModal.value = false
      newViewName.value = ''
    },
  })
}

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '网红', '红人库']
</script>

<template>
  <Head title="红人库 · BYL ERP" />
  <AuthenticatedLayout title="红人库" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL Master / 红人库</p>
          <h2>红人库</h2>
          <p>KOL 主数据列表，支持 Owner 范围、业务画像筛选和保存视图。</p>
        </div>
        <div class="mvp-actions page-header-actions">
          <Link v-if="canOperate" class="mvp-button mvp-button-primary" href="/kol/library/create">新建 KOL</Link>
        </div>
      </div>

      <!-- Top Views Bar -->
      <div class="top-views-bar">
        <button
          v-for="sv in systemViews" :key="sv.key"
          class="view-tab" :class="{ active: filters.view === sv.key }"
          type="button" @click="applySystemView(sv.key)"
        >{{ sv.label }}</button>
        <button
          v-for="pv in pinnedViews" :key="pv.id"
          class="view-tab view-tab-custom"
          type="button" @click="applyView(pv)"
        >{{ pv.name }}</button>
        <button v-if="canManageViews" class="view-tab view-tab-add" type="button" @click="showNewViewModal = true">+ 新建视图</button>
      </div>

      <!-- Filters -->
      <form class="mvp-toolbar kol-toolbar" @submit.prevent="submit">
        <input v-model="filters.search" aria-label="搜索 KOL" placeholder="搜索名称…">
        <select v-if="isAllScope" v-model="filters.owner_id" aria-label="负责人">
          <option value="">全部负责人</option>
          <option v-for="o in ownerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option>
        </select>
        <select v-model="filters.status" aria-label="状态">
          <option value="">全部状态</option>
          <option value="active">活跃</option>
          <option value="inactive">停用</option>
        </select>
        <select v-model="filters.relationship_stage" aria-label="合作阶段">
          <option value="">全部阶段</option>
          <option value="prospect">潜在</option>
          <option value="contacted">已联系</option>
          <option value="negotiating">谈判中</option>
          <option value="cooperating">合作中</option>
          <option value="long_term">长期合作</option>
          <option value="paused">暂停</option>
          <option value="ended">已结束</option>
        </select>
        <select v-model="filters.platform" aria-label="平台">
          <option value="">全部平台</option>
          <option value="instagram">Instagram</option>
          <option value="tiktok">TikTok</option>
          <option value="youtube">YouTube</option>
          <option value="xiaohongshu">小红书</option>
          <option value="weibo">微博</option>
          <option value="wechat">微信</option>
          <option value="twitter">X/Twitter</option>
        </select>
        <select v-model="filters.level" aria-label="等级">
          <option value="">全部等级</option>
          <option value="头部">头部</option>
          <option value="腰部">腰部</option>
          <option value="尾部">尾部</option>
          <option value="素人">素人</option>
        </select>
        <select v-model="filters.content_format" aria-label="内容形式">
          <option value="">全部形式</option>
          <option value="图文">图文</option>
          <option value="短视频">短视频</option>
          <option value="直播">直播</option>
          <option value="图文+视频">图文+视频</option>
          <option value="混合">混合</option>
        </select>
        <select v-model="filters.mcn" aria-label="MCN 状态">
          <option value="">全部 MCN</option>
          <option value="yes">MCN 管理</option>
          <option value="no">非 MCN</option>
        </select>
        <select v-model="filters.has_published_content" aria-label="是否已发布">
          <option value="">全部发布状态</option>
          <option value="yes">已发布</option>
          <option value="no">未发布</option>
        </select>
        <select v-model="filters.publication_evidence" aria-label="发布证据">
          <option value="">全部发布证据</option>
          <option value="none">无证据</option>
          <option value="url">仅 URL</option>
          <option value="screenshot">仅截图</option>
          <option value="url_and_screenshot">URL + 截图</option>
        </select>
        <input v-model="filters.followers_min" type="number" min="0" aria-label="粉丝数最小值" placeholder="粉丝≥">
        <input v-model="filters.followers_max" type="number" min="0" aria-label="粉丝数最大值" placeholder="粉丝≤">
        <button class="mvp-button" type="submit">筛选</button>
      </form>

      <!-- Table -->
      <div class="mvp-table-wrap">
        <table class="mvp-table kol-dense-table">
          <thead>
            <tr>
              <th>红人名称</th>
              <th>账号</th>
              <th>等级</th>
              <th>城市</th>
              <th>主营品类</th>
              <th>主推产品</th>
              <th>内容形式</th>
              <th>产品数量</th>
              <th>店铺数量</th>
              <th>合作阶段</th>
              <th>MCN机构</th>
              <th>负责人</th>
              <th>粉丝数</th>
              <th>是否已发布</th>
              <th>历史带货数量</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="kol in kols.data" :key="kol.id">
              <td>
                <Link :href="`/kol/library/${kol.id}`">{{ kol.display_name }}</Link>
                <span v-if="kol.is_100k_plus" class="badge-100k">100K+</span>
              </td>
              <td class="account-cell">
                <template v-if="kol.affiliate_account">
                  <strong>WordPress: {{ kol.affiliate_account.wordpress_user_login || (kol.affiliate_account.wordpress_user_id ? `WP User #${kol.affiliate_account.wordpress_user_id}` : '—') }}</strong>
                  <small>AffiliateWP: #{{ kol.affiliate_account.affiliate_id }}</small>
                </template>
                <span v-else class="account-muted">未绑定 Affiliate</span>
              </td>
              <td><span v-if="kol.level" class="tag-chip">{{ kol.level }}</span><span v-else>—</span></td>
              <td>{{ tagList(kol.categories) }}</td>
              <td>{{ tagList(kol.products) }}</td>
              <td>{{ kol.content_format ?? '—' }}</td>
              <td>{{ kol.product_quantity ?? '—' }}</td>
              <td>{{ stageLabel(kol.relationship_stage) }}</td>
              <td>{{ kol.mcn_agency ?? (kol.is_mcn_managed ? '是' : '—') }}</td>
              <td>{{ kol.owner?.name ?? '—' }}</td>
              <td>{{ followersLabel(kol.latest_followers) }}<span v-if="kol.primary_platform" class="platform-hint"> ({{ platformLabel(kol.primary_platform) }})</span></td>
              <td><span :class="['tag-chip', kol.has_published_content ? 'is-published' : '']">{{ kol.has_published_content ? '已发布' : '未发布' }}</span></td>
              <td>{{ kol.historical_order_count }}</td>
              <td>
                <Link :href="`/kol/library/${kol.id}`">查看</Link>
              </td>
            </tr>
            <tr v-if="kols.data.length === 0">
              <td colspan="16" class="mvp-empty">暂无符合条件的 KOL</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="kols.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" type="button" :disabled="kols.current_page <= 1" @click="goToPage(kols.current_page - 1)">上一页</button>
        <span>第 {{ kols.current_page }} / {{ kols.last_page }} 页 · 共 {{ kols.total }} 条</span>
        <button class="mvp-button" type="button" :disabled="kols.current_page >= kols.last_page" @click="goToPage(kols.current_page + 1)">下一页</button>
      </div>
    </section>

    <!-- New View Modal -->
    <div v-if="showNewViewModal" class="modal-backdrop" @click.self="showNewViewModal = false">
      <div class="modal-box">
        <h3>新建视图</h3>
        <p>将当前筛选条件保存为视图。</p>
        <label>
          视图名称
          <input v-model="newViewName" type="text" placeholder="例如：Emma清单" maxlength="100">
        </label>
        <label>
          可见性
          <select v-model="newViewVisibility">
            <option value="private">仅自己可见</option>
            <option value="shared">团队共享</option>
          </select>
        </label>
        <label class="checkbox-label">
          <input v-model="newViewPinned" type="checkbox"> 固定到顶部导航
        </label>
        <div class="modal-actions">
          <button class="mvp-button" type="button" @click="showNewViewModal = false">取消</button>
          <button class="mvp-button mvp-button-primary" type="button" :disabled="!newViewName.trim()" @click="saveNewView">保存视图</button>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.top-views-bar { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; }
.view-tab { padding: 6px 14px; border: 1px solid #e5e7eb; border-radius: 20px; background: transparent; cursor: pointer; font-size: 13px; color: #374151; }
.view-tab:hover { border-color: #0f766e; color: #0f766e; }
.view-tab.active { background: #0f766e; color: #fff; border-color: #0f766e; }
.view-tab-custom { border-style: dashed; }
.view-tab-add { color: #0f766e; border-color: #0f766e; }
.kol-toolbar { flex-wrap: wrap; }
.kol-dense-table { font-size: 13px; }
.account-cell strong, .account-cell small { display: block; white-space: nowrap; }
.account-cell strong { font-size: 12px; font-weight: 600; color: #334155; }
.account-cell small, .account-muted { color: #94a3b8; font-size: 11px; }
.tag-chip { display: inline-block; padding: 1px 8px; background: #f0fdfa; color: #0f766e; border-radius: 10px; font-size: 11px; }
.platform-hint { color: #94a3b8; font-size: 11px; }
.badge-100k { margin-left: 6px; padding: 1px 6px; background: #fef3c7; color: #92400e; border-radius: 10px; font-size: 11px; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 100; }
.modal-box { background: #fff; border-radius: 12px; padding: 24px; width: 100%; max-width: 420px; display: flex; flex-direction: column; gap: 14px; }
.modal-box h3 { margin: 0; font-size: 18px; }
.modal-box label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.modal-box input, .modal-box select { padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; }
.checkbox-label { flex-direction: row !important; align-items: center; gap: 8px !important; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px; }
</style>
