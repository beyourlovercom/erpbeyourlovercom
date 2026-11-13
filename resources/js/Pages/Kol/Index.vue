<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import { useAccountGridPreferences, type GridColumnDefinition, type GridPreferenceValue } from '../../Composables/useAccountGridPreferences'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))

type KolRow = {
  id: number
  display_name: string
  contact_email?: string | null
  country_code: string | null
  shipping_city: string | null
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
type ColumnKey = 'account' | 'level' | 'city' | 'country_region' | 'categories' | 'products' | 'content_format' | 'product_quantity' | 'relationship_stage' | 'status' | 'mcn_agency' | 'owner' | 'followers' | 'published' | 'historical_order_count' | 'joined_at' | 'internal_contact'
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{
  kols: Paginated<KolRow>
  filters: Record<string, string>
  savedViews: SavedView[]
  ownerOptions: OwnerOption[]
  canOperate: boolean
  canManageViews: boolean
  isAllScope: boolean
  gridPreferenceNamespace: string
  gridPreference: GridPreferenceValue<ColumnKey> | null
}>()

const defaultColumns: GridColumnDefinition<ColumnKey>[] = [
  { key: 'account', label: '账号' },
  { key: 'level', label: '等级' },
  { key: 'city', label: '城市' },
  { key: 'country_region', label: '国家 / 地区', defaultVisible: false },
  { key: 'categories', label: '主营品类' },
  { key: 'products', label: '主推产品' },
  { key: 'content_format', label: '内容形式' },
  { key: 'product_quantity', label: '产品数量' },
  { key: 'relationship_stage', label: '合作阶段' },
  { key: 'status', label: '状态', defaultVisible: false },
  { key: 'mcn_agency', label: 'MCN机构' },
  { key: 'owner', label: '负责人' },
  { key: 'followers', label: '粉丝数' },
  { key: 'published', label: '是否已发布' },
  { key: 'historical_order_count', label: '历史带货数量' },
  { key: 'joined_at', label: '加入日期', defaultVisible: false },
  { key: 'internal_contact', label: '内部对接人', defaultVisible: false },
]

const columnSettingsOpen = ref(false)
// Default new Global Grid, with a read-only legacy display fallback for UAT.
const legacyTable = ref(props.filters.legacy_grid === '1')
const {
  columns: columnPreferences,
  visibleColumns,
  syncState: columnPreferenceSyncState,
  toggle: toggleColumn,
  startDrag: startColumnDrag,
  drop: dropColumn,
  reset: resetColumns,
} = useAccountGridPreferences<ColumnKey>({
  namespace: props.gridPreferenceNamespace,
  definitions: defaultColumns,
  serverValue: props.gridPreference,
})

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
  per_page: props.filters.per_page ?? '50',
  grid_name: props.filters.grid_name ?? '',
  grid_account: props.filters.grid_account ?? '',
  grid_city: props.filters.grid_city ?? '',
  grid_country: props.filters.grid_country ?? '',
  grid_owner: props.filters.grid_owner ?? '',
  grid_email: props.filters.grid_email ?? '',
})

const advancedFiltersOpen = ref(false)
const showNewViewModal = ref(false)
const newViewName = ref('')
const newViewVisibility = ref('private')
const newViewPinned = ref(false)

const nav = (overrides: Record<string, string | number> = {}) => router.get('/kol/library',
  { ...filters, legacy_grid: legacyTable.value ? '1' : '0', ...overrides },
  { preserveState: true, preserveScroll: true, replace: true })
const submit = () => nav({ page: 1 })
const goToPage = (page: number) => nav({ page })
const toggleGrid = () => {
  legacyTable.value = !legacyTable.value
  filters.per_page = legacyTable.value ? '30' : '50'
  nav({ page: 1 })
}
const gridPageSize = (size: number | 'all') => {
  if (size === 'all') return
  filters.per_page = String([20, 30, 50, 100].includes(size) ? size : 50)
  nav({ page: 1 })
}
const gridSearch = (search: string) => { filters.search = search; nav({ page: 1 }) }
const gridColumnFilter = ({ colId, value }: { colId: string; value: string }) => {
  const keys = ['name', 'account', 'city', 'country', 'owner', 'email']
  if (!keys.includes(colId)) return
  const name = ('grid_' + colId) as keyof typeof filters
  filters[name] = value
  nav({ page: 1 })
}
const sortFields: Record<string,string> = {
  name: 'display_name', status: 'status', level: 'level', city: 'shipping_city',
  country: 'country_code', stage: 'relationship_stage', content_format: 'content_format',
  product_quantity: 'product_quantity', joined_at: 'joined_at',
}
const gridSort = ({ colId, direction }: { colId: string; direction: 'asc' | 'desc' | null }) => {
  filters.sort = direction && sortFields[colId] ? sortFields[colId] : 'display_name'
  filters.direction = direction ?? 'asc'
  nav({ page: 1 })
}
const gridSortState = computed(() => ({
  colId: Object.entries(sortFields).find(([, key]) => key === filters.sort)?.[0] ?? 'name',
  direction: filters.direction === 'desc' ? 'desc' as const : 'asc' as const,
}))
const gridFilterValues = computed(() => ({
  name: filters.grid_name, account: filters.grid_account, city: filters.grid_city,
  country: filters.grid_country, owner: filters.grid_owner, email: filters.grid_email,
}))
const gridRows = computed<GridListRow[]>(() => props.kols.data.map(kol => ({
  id: kol.id, name: kol.display_name, detail_url: '/kol/library/' + kol.id,
  email: kol.contact_email ?? '—',
  account: kol.affiliate_account
    ? 'WordPress: ' + (kol.affiliate_account.wordpress_user_login
      || (kol.affiliate_account.wordpress_user_id ? 'WP User #' + kol.affiliate_account.wordpress_user_id : '—'))
    : '未绑定 Affiliate',
  affiliate_id: kol.affiliate_account?.affiliate_id ? '#' + kol.affiliate_account.affiliate_id : '—',
  level: kol.level ?? '—', city: kol.shipping_city ?? '—', country: kol.country_code ?? '—',
  categories: tagList(kol.categories), products: tagList(kol.products),
  content_format: kol.content_format ?? '—', product_quantity: kol.product_quantity ?? '—',
  stage: stageLabel(kol.relationship_stage), status: kol.status === 'active' ? '活跃' : '停用',
  mcn_agency: kol.mcn_agency ?? (kol.is_mcn_managed ? '是' : '—'),
  owner: kol.owner?.name ?? '—',
  followers: followersLabel(kol.latest_followers) + (kol.primary_platform ? ' (' + platformLabel(kol.primary_platform) + ')' : ''),
  published: kol.has_published_content ? '已发布' : '未发布',
  historical_order_count: kol.historical_order_count,
  joined_at: kol.joined_at ?? '—', internal_contact: kol.internal_contact ?? '—',
  audience_tier: kol.is_100k_plus ? '100K+' : '—',
  is_100k_plus: kol.is_100k_plus,
})))
const gridColumns: ColDef<GridListRow>[] = [
  gridColumn({ colId:'name',field:'name',headerName:'红人名称',width:210,sortable:true,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(row:GridListRow)=>String(row.detail_url)} }),
  gridColumn({ colId:'account',field:'account',headerName:'WordPress 账号',width:220 }),
  gridColumn({ colId:'affiliate_id',field:'affiliate_id',headerName:'AffiliateWP ID',width:130 }),
  gridColumn({ colId:'email',field:'email',headerName:'联系邮箱',width:210 }),
  gridColumn({ colId:'level',field:'level',headerName:'等级',width:100,sortable:true }),
  gridColumn({ colId:'city',field:'city',headerName:'城市',width:135,sortable:true }),
  gridColumn({ colId:'country',field:'country',headerName:'国家/地区',width:112,sortable:true,hide:true }),
  gridColumn({ colId:'categories',field:'categories',headerName:'主营品类',width:150 }),
  gridColumn({ colId:'products',field:'products',headerName:'主推产品',width:150 }),
  gridColumn({ colId:'content_format',field:'content_format',headerName:'内容形式',width:120,sortable:true }),
  gridColumn({ colId:'product_quantity',field:'product_quantity',headerName:'产品数量',width:115,sortable:true }),
  gridColumn({ colId:'stage',field:'stage',headerName:'合作阶段',width:135,sortable:true }),
  gridColumn({ colId:'status',field:'status',headerName:'状态',width:105,sortable:true,hide:true,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'} }),
  gridColumn({ colId:'mcn_agency',field:'mcn_agency',headerName:'MCN机构',width:160 }),
  gridColumn({ colId:'owner',field:'owner',headerName:'负责人',width:145 }),
  gridColumn({ colId:'followers',field:'followers',headerName:'粉丝数/平台',width:150 }),
  gridColumn({ colId:'audience_tier',field:'audience_tier',headerName:'100K+',width:95,hide:true,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'} }),
  gridColumn({ colId:'published',field:'published',headerName:'是否已发布',width:120,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'} }),
  gridColumn({ colId:'historical_order_count',field:'historical_order_count',headerName:'历史带货数量',width:145 }),
  gridColumn({ colId:'joined_at',field:'joined_at',headerName:'加入日期',width:150,sortable:true,hide:true }),
  gridColumn({ colId:'internal_contact',field:'internal_contact',headerName:'内部对接人',width:140,hide:true }),
  gridColumn({ colId:'actions',field:'detail_url',headerName:'操作',width:112,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(row:GridListRow)=>[
      {label:'查看',href:String(row.detail_url)},
      ...(props.canOperate ? [{label:'编辑',href:String(row.detail_url)+'/edit'}] : []),
    ],compactActions:true} }),
]
const gridPager = computed(() => ({
  current_page: props.kols.current_page, last_page: props.kols.last_page,
  per_page: props.kols.per_page, total: props.kols.total,
}))

const applyView = (view: SavedView) => {
  Object.assign(filters, view.filters_json)
  filters.view = ''
  submit()
}
const applySystemView = (viewKey: string) => {
  const existingPageSize = filters.per_page
  Object.keys(filters).forEach(k => { (filters as Record<string, string>)[k] = '' })
  filters.per_page = existingPageSize
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
    <section class="mvp-page kol-library-global-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL Master / 红人库</p>
          <h2>红人库</h2>
        </div>
        <div class="mvp-actions page-header-actions">
          <button type="button" class="mvp-button" data-testid="kol-grid-switch"
            @click="toggleGrid">{{ legacyTable ? '切回新表' : '查看原表' }}</button>
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
        <input v-if="legacyTable" v-model="filters.search" aria-label="搜索 KOL" placeholder="搜索名称…">
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
        <select v-if="advancedFiltersOpen" v-model="filters.content_format" aria-label="内容形式">
          <option value="">全部形式</option>
          <option value="图文">图文</option>
          <option value="短视频">短视频</option>
          <option value="直播">直播</option>
          <option value="图文+视频">图文+视频</option>
          <option value="混合">混合</option>
        </select>
        <select v-if="advancedFiltersOpen" v-model="filters.mcn" aria-label="MCN 状态">
          <option value="">全部 MCN</option>
          <option value="yes">MCN 管理</option>
          <option value="no">非 MCN</option>
        </select>
        <select v-if="advancedFiltersOpen" v-model="filters.has_published_content" aria-label="是否已发布">
          <option value="">全部发布状态</option>
          <option value="yes">已发布</option>
          <option value="no">未发布</option>
        </select>
        <select v-if="advancedFiltersOpen" v-model="filters.publication_evidence" aria-label="发布证据">
          <option value="">全部发布证据</option>
          <option value="none">无证据</option>
          <option value="url">仅 URL</option>
          <option value="screenshot">仅截图</option>
          <option value="url_and_screenshot">URL + 截图</option>
        </select>
        <input v-if="advancedFiltersOpen" v-model="filters.followers_min" type="number" min="0" aria-label="粉丝数最小值" placeholder="粉丝≥">
        <input v-if="advancedFiltersOpen" v-model="filters.followers_max" type="number" min="0" aria-label="粉丝数最大值" placeholder="粉丝≤">
        <button class="mvp-button" type="button" aria-label="高级筛选"
          :aria-expanded="advancedFiltersOpen" @click="advancedFiltersOpen = !advancedFiltersOpen">
          {{ advancedFiltersOpen ? '收起高级筛选' : '高级筛选' }}
        </button>
        <button class="mvp-button" type="submit">筛选</button>
      </form>

      <!-- Global Grid V1: default, server-paginated; no client-side full-data claims. -->
      <GlobalGrid v-if="!legacyTable"
        namespace="grid.kol.library.global.v1" title="网红档案"
        :rows="gridRows" :columns="gridColumns" :page="gridPager"
        :required-column-ids="['name','actions']" :default-pinned-right="1"
        :search="filters.search" :sort="gridSortState"
        :filter-columns="['name','account','email','city','country','owner']"
        :filter-values="gridFilterValues"
        search-placeholder="搜索网红名称 / 邮箱 / WordPress 账号..."
        :enable-selection="false" :client-csv-export="false"
        @page-change="goToPage" @page-size-change="gridPageSize"
        @search-change="gridSearch" @sort-change="gridSort"
        @column-search-change="gridColumnFilter"
      />
      <!-- Original table is available by explicit fallback only. -->
      <div v-else class="kol-grid-shell">
        <div class="kol-grid-settings-anchor">
          <button
            class="column-settings-trigger"
            type="button"
            aria-label="设置红人列表列"
            title="设置显示列和列顺序"
            @click="columnSettingsOpen = !columnSettingsOpen"
          >…</button>
          <div v-if="columnSettingsOpen" class="column-settings-panel" @click.stop>
            <div class="column-settings-heading">
              <strong>列设置</strong>
              <button type="button" class="column-reset-btn" @click="resetColumns">恢复默认</button>
            </div>
            <p class="column-settings-help">勾选显示列；按住拖动柄上下调整列顺序。设置绑定当前账号并跨设备同步。</p>
            <p v-if="columnPreferenceSyncState !== 'idle'" class="column-sync-state" :data-state="columnPreferenceSyncState">{{ columnPreferenceSyncState === 'saving' ? '正在同步…' : columnPreferenceSyncState === 'error' ? '同步失败，已保留本地缓存' : '已同步到账号' }}</p>
            <div class="column-setting-row column-setting-pinned">
              <span class="column-drag-handle column-drag-disabled">⋮⋮</span>
              <input type="checkbox" checked disabled aria-label="红人名称固定显示">
              <span>红人名称</span>
              <small>固定</small>
            </div>
            <div
              v-for="column in columnPreferences"
              :key="column.key"
              class="column-setting-row"
              draggable="true"
              :data-column-key="column.key"
              @dragstart="startColumnDrag(column.key)"
              @dragover.prevent
              @drop="dropColumn(column.key)"
            >
              <span class="column-drag-handle" title="拖动调整顺序">⋮⋮</span>
              <input type="checkbox" :checked="column.visible" :aria-label="`显示${column.label}`" @change="toggleColumn(column.key)">
              <span>{{ column.label }}</span>
            </div>
          </div>
        </div>
        <div class="mvp-table-wrap kol-grid-scroll">
          <table class="mvp-table kol-dense-table kol-data-grid">
            <thead>
              <tr>
                <th class="kol-grid-identity">红人名称</th>
                <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th>
                <th class="kol-grid-action-head"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="kol in kols.data" :key="kol.id">
                <td class="kol-grid-identity">
                  <Link :href="`/kol/library/${kol.id}`">{{ kol.display_name }}</Link>
                  <span v-if="kol.is_100k_plus" class="badge-100k">100K+</span>
                </td>
                <td v-for="column in visibleColumns" :key="column.key" :class="{ 'account-cell': column.key === 'account' }">
                  <template v-if="column.key === 'account'">
                    <template v-if="kol.affiliate_account">
                      <strong>WordPress: {{ kol.affiliate_account.wordpress_user_login || (kol.affiliate_account.wordpress_user_id ? `WP User #${kol.affiliate_account.wordpress_user_id}` : '—') }}</strong>
                      <small>AffiliateWP: #{{ kol.affiliate_account.affiliate_id }}</small>
                    </template>
                    <span v-else class="account-muted">未绑定 Affiliate</span>
                  </template>
                  <template v-else-if="column.key === 'level'"><span v-if="kol.level" class="tag-chip">{{ kol.level }}</span><span v-else>—</span></template>
                  <template v-else-if="column.key === 'city'">{{ kol.shipping_city ?? '—' }}</template>
                  <template v-else-if="column.key === 'country_region'">{{ kol.country_code ?? '—' }}</template>
                  <template v-else-if="column.key === 'categories'">{{ tagList(kol.categories) }}</template>
                  <template v-else-if="column.key === 'products'">{{ tagList(kol.products) }}</template>
                  <template v-else-if="column.key === 'content_format'">{{ kol.content_format ?? '—' }}</template>
                  <template v-else-if="column.key === 'product_quantity'">{{ kol.product_quantity ?? '—' }}</template>
                  <template v-else-if="column.key === 'relationship_stage'">{{ stageLabel(kol.relationship_stage) }}</template>
                  <template v-else-if="column.key === 'status'">{{ kol.status === 'active' ? '活跃' : kol.status === 'inactive' ? '停用' : kol.status }}</template>
                  <template v-else-if="column.key === 'mcn_agency'">{{ kol.mcn_agency ?? (kol.is_mcn_managed ? '是' : '—') }}</template>
                  <template v-else-if="column.key === 'owner'">{{ kol.owner?.name ?? '—' }}</template>
                  <template v-else-if="column.key === 'followers'">{{ followersLabel(kol.latest_followers) }}<span v-if="kol.primary_platform" class="platform-hint"> ({{ platformLabel(kol.primary_platform) }})</span></template>
                  <template v-else-if="column.key === 'published'"><span :class="['tag-chip', kol.has_published_content ? 'is-published' : '']">{{ kol.has_published_content ? '已发布' : '未发布' }}</span></template>
                  <template v-else-if="column.key === 'historical_order_count'">{{ kol.historical_order_count }}</template>
                  <template v-else-if="column.key === 'joined_at'">{{ kol.joined_at ?? '—' }}</template>
                  <template v-else-if="column.key === 'internal_contact'">{{ kol.internal_contact ?? '—' }}</template>
                </td>
                <td class="kol-grid-action-cell"><Link :href="`/kol/library/${kol.id}`">查看</Link></td>
              </tr>
              <tr v-if="kols.data.length === 0">
                <td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无符合条件的 KOL</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="legacyTable && kols.last_page > 1" class="mvp-pagination">
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
.kol-library-global-page { min-width: 0; }
.kol-library-global-page .mvp-page-heading { margin-bottom: 8px; }
.kol-library-global-page .mvp-kicker { font-size: 10px; }
.kol-library-global-page .mvp-toolbar { margin-bottom: 10px; gap: 6px; padding: 8px; }
.kol-library-global-page .mvp-page-heading h2 { margin: 0; }
.kol-library-global-page .top-views-bar { margin-bottom: 6px; }

.top-views-bar { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; }
.view-tab { padding: 6px 14px; border: 1px solid #e5e7eb; border-radius: 20px; background: transparent; cursor: pointer; font-size: 13px; color: #374151; }
.view-tab:hover { border-color: #0f766e; color: #0f766e; }
.view-tab.active { background: #0f766e; color: #fff; border-color: #0f766e; }
.view-tab-custom { border-style: dashed; }
.view-tab-add { color: #0f766e; border-color: #0f766e; }
.kol-toolbar { flex-wrap: wrap; }
.kol-dense-table { font-size: 13px; }
.kol-grid-shell { position: relative; }
.kol-grid-scroll { overflow-x: auto; scrollbar-width: thin; }
.kol-data-grid { min-width: 1420px; }
.kol-data-grid th, .kol-data-grid td { white-space: nowrap; }
.kol-grid-identity { position: sticky; left: 0; min-width: 190px; background: #fff; z-index: 3; }
thead .kol-grid-identity { z-index: 5; }
.kol-grid-action-head, .kol-grid-action-cell { position: sticky; right: 0; width: 76px; min-width: 76px; background: #fff; z-index: 4; }
.kol-grid-settings-anchor { position: absolute; top: 0; right: 0; width: 76px; height: 46px; display: flex; align-items: center; justify-content: center; background: #fff; border-left: 1px solid #E4EAED; border-bottom: 1px solid #E4EAED; z-index: 20; }
.column-settings-trigger { width: 42px; height: 32px; border: 1px solid #DCE5E8; border-radius: 8px; background: #fff; color: #344B53; font-size: 22px; line-height: 1; cursor: pointer; }
.column-settings-trigger:hover { background: #F4F7F8; }
.column-settings-panel { position: absolute; top: 40px; right: 0; width: 300px; max-height: 520px; overflow-y: auto; padding: 12px; background: #fff; border: 1px solid #DCE5E8; border-radius: 10px; box-shadow: 0 12px 32px rgba(20, 33, 38, 0.14); text-align: left; z-index: 40; }
.column-settings-heading { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.column-settings-help { margin: 8px 0; font-size: 11px; line-height: 1.45; color: #7A8C94; }
.column-sync-state { margin: 0 0 8px; font-size: 10px; color: #7A8C94; }
.column-sync-state[data-state="saved"] { color: #2D8C84; }
.column-sync-state[data-state="error"] { color: #B42318; }
.column-reset-btn { border: 0; background: transparent; color: #2D8C84; font-size: 12px; cursor: pointer; }
.column-setting-row { display: grid; grid-template-columns: 22px 22px 1fr auto; align-items: center; gap: 6px; min-height: 34px; padding: 4px 5px; border-radius: 6px; font-size: 13px; cursor: grab; }
.column-setting-row:hover { background: #F4F7F8; }
.column-setting-row:active { cursor: grabbing; }
.column-setting-pinned { background: #F8FAFA; cursor: default; }
.column-setting-row small { color: #7A8C94; font-size: 10px; }
.column-drag-handle { color: #7A8C94; letter-spacing: -2px; user-select: none; }
.column-drag-disabled { opacity: 0.35; }
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
