<script setup lang="ts">
import { erp2GridPreferences as axios } from './erp2GridPreferences'
import { canonicalDevIdentity } from '#/router/erp-live-navigation'
import { computed, nextTick, onBeforeUnmount, onDeactivated, ref, watch } from 'vue'
import { gridFeatureKeys,gridFeatureLabels,gridFeatureEnabled,sanitizeGridFeatures,type GridFeature,type GridFeatures } from './unifiedGridFeatures'
import { queuePreferenceWrite,waitForPreferenceWrites } from './gridPreferenceQueue'
import { AgGridVue } from 'ag-grid-vue3'
import OmsServerFloatingFilter from './OmsServerFloatingFilter.vue'
import {
  ClientSideRowModelModule, ColumnApiModule, RowApiModule, RenderApiModule,
  PaginationModule, RowSelectionModule, TextFilterModule, NumberFilterModule,
  QuickFilterModule, CsvExportModule, TooltipModule, ColumnAutoSizeModule,
  GridStateModule, EventApiModule, CellApiModule, DragAndDropModule,
  CellStyleModule, LocaleModule, TextEditorModule, ModuleRegistry, themeBalham,
  type ColDef, type ColGroupDef, type ColumnState, type GridApi, type GridReadyEvent, type SortChangedEvent, type CellClickedEvent,
} from 'ag-grid-community'
import { clampPinnedCounts, pinnedStateForVisibleColumns, restoreColumnWidths, sanitizeOmsGridPreference, type OmsDensity, type OmsGridPreference } from './omsGridState'

// Only register features used by the shared table. Unused AG Grid community
// editors, infinite models and optional modules stay out of its lazy chunk.
// Tracking in the overseas workbench uses the native text editor.
ModuleRegistry.registerModules([
  ClientSideRowModelModule, ColumnApiModule, RowApiModule, RenderApiModule,
  PaginationModule, RowSelectionModule, TextFilterModule, NumberFilterModule,
  QuickFilterModule, CsvExportModule, TooltipModule, ColumnAutoSizeModule,
  GridStateModule, EventApiModule, CellApiModule, DragAndDropModule,
  CellStyleModule, LocaleModule, TextEditorModule,
])
type GridRow = Record<string, unknown>
type GridColumn = ColDef<GridRow> | ColGroupDef<GridRow>
type ServerPagination = { current_page: number; last_page: number; per_page: number; total: number; page_size_mode?: number | 'all' }
type ServerSort = { colId: string; direction: 'asc' | 'desc' }
const props = withDefaults(defineProps<{
  namespace: string
  columns: GridColumn[]
  rows: GridRow[]
  title?: string
  preferenceScope?: 'local' | 'account'
  legacyPreferenceNamespaces?: string[]
  defaultPinnedLeft?: number
  defaultPinnedRight?: number
  requiredColumnIds?: string[]
  serverPagination?: ServerPagination
  serverSort?: ServerSort
  serverSearch?: string
  serverColumnFilters?: Record<string, string>
  serverFilterColumns?: string[]
  rowIdentityKey?: string
  selectionOrderKey?: string
  selectedOrderIds?: number[]
  showSearch?: boolean
  searchLabel?: string
  searchPlaceholder?: string
  enableSelection?: boolean
  cellTextSelection?: boolean
  allowAllRows?: boolean
  showClientCsvExport?: boolean
}>(), {
  title: 'OMS Grid', preferenceScope: 'account', defaultPinnedLeft: 1,
  defaultPinnedRight: 1, requiredColumnIds: () => [], legacyPreferenceNamespaces: () => [],
  showSearch: true, enableSelection: true, cellTextSelection: false, allowAllRows: true, showClientCsvExport: true,
})

const emit = defineEmits<{
  (event: 'selection-change', rows: GridRow[]): void
  (event: 'cell-clicked', cell: CellClickedEvent<GridRow>): void
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'sort-change', sort: { colId: string; direction: 'asc' | 'desc' | null }): void
  (event: 'search-change', value: string): void
  (event: 'column-search-change', filter: { colId: string; value: string }): void
  (event: 'clear-column-filters'): void
}>()
const flatten = (definitions: GridColumn[]): ColDef<GridRow>[] =>
  definitions.flatMap(column => 'children' in column && column.children
    ? flatten(column.children as GridColumn[]) : [column as ColDef<GridRow>])
// Keep column definitions flat: no redundant first-level group header row.
const leafColumns = computed(() => flatten(props.columns).map(definition => {
  // Stateful hide/width/pinned are reapplied on every server response. Use
  // creation-only defaults for personal grids so query changes cannot erase edits.
  let col=definition
  {
    const {hide,width,pinned,...rest}=definition
    col={...rest,initialHide:hide??definition.initialHide,initialWidth:width??definition.initialWidth,initialPinned:pinned??definition.initialPinned}
  }
  if (!props.serverPagination) return col
  const key = col.colId ?? col.field ?? ''
  const filterEnabled = (props.serverFilterColumns?.includes(key) ?? false) && toolEnabled('filter')
  return {
    ...col,
    filter: filterEnabled,
    floatingFilter: filterEnabled,
    suppressHeaderFilterButton: true,
    suppressHeaderMenuButton: true,
    sortable:col.sortable===true && toolEnabled('sort'),
    sort: toolEnabled('sort') && props.serverSort?.colId === key ? props.serverSort.direction : null,
    ...(filterEnabled ? {
      // A column may supply a specialized server filter (e.g. a
      // controlled status dropdown); existing text filters remain default.
      floatingFilterComponent: col.floatingFilterComponent ?? OmsServerFloatingFilter,
      floatingFilterComponentParams: {
        ...(typeof col.floatingFilterComponentParams === 'object' && col.floatingFilterComponentParams !== null
          ? col.floatingFilterComponentParams : {}),
        filterValue: props.serverColumnFilters?.[key] ?? '',
        onServerFilter: (value: string) => emit('column-search-change', { colId: key, value }),
        suppressFilterButton: true,
      },
    } : {}),
  }
}))
const ids = computed(() => leafColumns.value.map(col => col.colId ?? col.field ?? '').filter(Boolean))
const names = computed(() => new Map(leafColumns.value.map(col => [col.colId ?? col.field ?? '', col.headerName ?? col.field ?? ''])))
const required = computed(() => new Set(props.requiredColumnIds))
const api = ref<GridApi<GridRow> | null>(null)
const states = ref<ColumnState[]>([])
const settingsOpen = ref(false)
const maximized = ref(false)
const density = ref<OmsDensity>('compact')
const pageSize = ref<number | 'all'>(props.serverPagination?.per_page ?? 50)
const featurePreferences=ref<GridFeatures>({})
const featureCapabilities=computed<GridFeatures>(()=>({search:props.showSearch,density:true,maximize:true,copy:props.cellTextSelection,selection:props.enableSelection,filter:!!props.serverFilterColumns?.length,sort:props.columns.some(c=> 'sortable' in c && c.sortable===true),pageSize:!!props.serverPagination}))
const toolEnabled=(key:GridFeature)=>gridFeatureEnabled(key,featureCapabilities.value,featurePreferences.value)
const availableTools=gridFeatureKeys
const pinnedLeft = ref(props.defaultPinnedLeft)
const pinnedRight = ref(props.defaultPinnedRight)
const query = ref(props.serverSearch ?? '')
const selectedCount = ref(0)
let restoringSelection = false
const visibleCount = ref(0)
const syncState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle')
const layoutReady = ref(false)
const actorId = computed(() => {
  const value = canonicalDevIdentity.value?.user?.id;
  return Number.isSafeInteger(value) && Number(value) > 0 ? Number(value) : 0;
})
const theme = themeBalham.withParams({ fontSize: 12, spacing: 5, headerFontSize: 12 })
const storageKey = computed(() => actorId.value > 0
  ? 'oms-grid-pref:user:' + actorId.value + ':' + props.namespace : null)
const scopedStorageKey = (namespace: string) => 'oms-grid-pref:user:' + actorId.value + ':' + namespace

const defaultColDef: ColDef<GridRow> = { sortable: true, filter: true, floatingFilter: true, resizable: true, minWidth: 85 }
const effectiveDefaultColDef = computed<ColDef<GridRow>>(() => props.serverPagination
  ? { ...defaultColDef, sortable: false, filter: false, floatingFilter: false }
  : defaultColDef)
const rowSelection = { mode: 'multiRow' as const, selectAll: 'currentPage' as const }
const localeText = { page: '页', to: '至', of: '共', nextPage: '下一页', previousPage: '上一页', firstPage: '首页', lastPage: '末页', pageSizeSelectorLabel: '每页', noRowsToShow: '暂无数据' }
const rowHeight = computed(() => density.value === 'compact' ? 32 : 42)
const headerHeight = computed(() => density.value === 'compact' ? 31 : 38)
// Few loaded server rows: size the Grid to its content, not to the viewport.
// Full-screen mode and larger lists retain bounded height + row virtualization.
const autoHeight = computed(() => !!props.serverPagination && props.rows.length <= 10 && !maximized.value)
const isEmptyShortList = computed(() => autoHeight.value && props.rows.length === 0)
const orderedColumns = computed(() => states.value.filter(state => ids.value.includes(state.colId)))
const visibleColumns = computed(() => orderedColumns.value.filter(column => !column.hide))
const resolvedPageSize = () => pageSize.value === 'all'
  ? Math.max(1, api.value?.getDisplayedRowCount() ?? props.rows.length)
  : pageSize.value
// "全部" applies to the loaded and filtered rows only; R3+ must preserve server-side paging contracts.
const onFiltered = () => {
  if (!props.serverPagination && pageSize.value === 'all' && api.value) {
    const count = resolvedPageSize()
    if (api.value.paginationGetPageSize() !== count) api.value.setGridOption('paginationPageSize', count)
  }
  refresh()
}
let restoring = false
let timer: ReturnType<typeof setTimeout> | undefined
let searchTimer: ReturnType<typeof setTimeout> | undefined
const submitSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  const value = query.value.trim()
  if (props.serverPagination) {
    if (value !== (props.serverSearch ?? '').trim()) emit('search-change', value)
  } else api.value?.setGridOption('quickFilterText', value)
}
const searchInput = () => {
  if (!props.serverPagination) return
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(submitSearch, 450)
}
const clearSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  query.value = ''
  submitSearch()
}
watch(() => props.serverSearch, value => {
  if (props.serverPagination && value !== undefined && value !== query.value) query.value = value
})
let dragged: string | null = null

const refresh = () => {
  if (!api.value) return
  states.value = api.value.getColumnState() ?? []
  visibleCount.value = props.serverPagination?.total ?? api.value.getDisplayedRowCount()
  selectedCount.value = props.selectionOrderKey ? (props.selectedOrderIds?.length ?? 0) : api.value.getSelectedRows().length
}
const orderKey = (row: GridRow | undefined): number | null => {
  const raw = row?.[props.selectionOrderKey ?? '']
  const id = Number(raw)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}
const restoreSelections = () => {
  if (!props.selectionOrderKey || !api.value || !toolEnabled('selection')) return
  const selected = new Set(props.selectedOrderIds ?? [])
  restoringSelection = true
  try {
    api.value.forEachNode(node => {
      const shouldSelect = selected.has(orderKey(node.data) ?? -1)
      if (node.isSelected() !== shouldSelect) node.setSelected(shouldSelect)
    })
  } finally { restoringSelection = false }
  refresh()
}
const onRowSelected = (event: { node: { data?: GridRow; isSelected(): boolean }; api: GridApi<GridRow> }) => {
  if (!props.selectionOrderKey || restoringSelection || !toolEnabled('selection')) return
  const id = orderKey(event.node.data)
  if (id === null) return
  restoringSelection = true
  try {
    event.api.forEachNode(node => {
      if (node !== event.node && orderKey(node.data) === id && node.isSelected() !== event.node.isSelected()) {
        node.setSelected(event.node.isSelected())
      }
    })
  } finally { restoringSelection = false }
  // Some grid versions emit a selectionChanged source that is not in the
  // checkbox allowlist. Persist a real row toggle immediately, before a
  // model refresh can restore the previous controlled selection.
  emit('selection-change', event.api.getSelectedRows())
}
const onSelectionChanged = (event: { api: GridApi<GridRow>; source?: string }) => {
  if (restoringSelection || !toolEnabled('selection')) return
  if (props.selectionOrderKey) {
    // AG Grid emits selectionChanged when new server-page rows replace old
    // ones. That is NOT the user unchecking orders on the previous page.
    const userSources = ['checkboxSelected', 'rowClicked', 'spaceKey',
      'keyboardSelectAll', 'uiSelectAll', 'uiSelectAllFiltered', 'uiSelectAllCurrentPage']
    if (!userSources.includes(event.source ?? '')) return
  }
  refresh()
  emit('selection-change', event.api.getSelectedRows())
}
watch(() => props.selectedOrderIds, () => restoreSelections())
const getRowId = (params: { data: GridRow }) => String(params.data[props.rowIdentityKey ?? 'id'] ?? '')
const snapshot = (): OmsGridPreference => ({
  version: 1, columns: (api.value?.getColumnState() ?? []).filter(c => ids.value.includes(c.colId)),
  pinnedLeft: pinnedLeft.value, pinnedRight: pinnedRight.value,
  density: density.value, pageSize: pageSize.value, features: {...featurePreferences.value},
})
const readCache = (key: string): OmsGridPreference | null => {
  try {
    return sanitizeOmsGridPreference(JSON.parse(window.localStorage.getItem(key) ?? 'null'),ids.value)
  } catch { return null }
}
const cache = (value: OmsGridPreference) => {
  if (!storageKey.value) return
  try { window.localStorage.setItem(storageKey.value, JSON.stringify(value)) } catch { /* local cache is optional */ }
}
// Capture edits at the time they happen, not after a debounce or a page refresh.
// Serialize writes so a slow older PUT cannot overwrite the newest layout.
const ownerAtMount=actorId.value
const namespaceAtMount=props.namespace
const preferenceKey=String(ownerAtMount)+':'+namespaceAtMount
let disposed=false
let saveRevision=0
let pendingValue: OmsGridPreference | null = null
const save = async () => {
  if (props.preferenceScope === 'local') { syncState.value = 'saved'; return }
  if (!pendingValue || actorId.value!==ownerAtMount || ownerAtMount<=0) return
  const value=pendingValue
  pendingValue=null
  const revision=++saveRevision
  syncState.value='saving'
  try {
    await queuePreferenceWrite(preferenceKey,async()=>{
      if(actorId.value!==ownerAtMount)throw Error('GRID_ACTOR_CHANGED')
      await axios.put('/ui/preferences/' + encodeURIComponent(namespaceAtMount),{value})
    })
    if(revision===saveRevision)syncState.value='saved'
  } catch {
    if(revision===saveRevision){pendingValue??=value;syncState.value='error'}
  }
}
const scheduleSave = () => {
  if (restoring || !api.value || !layoutReady.value) return
  refresh()
  pendingValue = snapshot()
  cache(pendingValue)
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { void save() }, 450)
}
const applyPins = () => {
  if (!api.value) return
  const columnState = api.value.getColumnState()
  if (!Array.isArray(columnState)) return // Grid columns not initialized yet
  const state = columnState.filter(c => ids.value.includes(c.colId))
  // Saved column ORDER must not override domain-fixed actions.
  const locked = {
    left: leafColumns.value.filter(col => col.lockPinned && (col.pinned??col.initialPinned) === 'left')
      .map(col => col.colId ?? col.field ?? ''),
    right: leafColumns.value.filter(col => col.lockPinned && (col.pinned??col.initialPinned) === 'right')
      .map(col => col.colId ?? col.field ?? ''),
  }
  const counts = clampPinnedCounts(state.filter(c => !c.hide).length,
    Math.max(pinnedLeft.value,locked.left.length),Math.max(pinnedRight.value,locked.right.length))
  pinnedLeft.value = counts.left
  pinnedRight.value = counts.right
  const pinned = pinnedStateForVisibleColumns(state, counts.left, counts.right, locked)
  api.value.applyColumnState({ state: pinned })
  refresh()
}
// Header pin/unpin through AG Grid also updates the numeric settings and
// the stored account preference, not just the column's transient CSS state.
const onColumnPinned = () => {
  if (restoring || !api.value || !layoutReady.value) return
  const visible = api.value.getColumnState().filter(column => !column.hide)
  pinnedLeft.value = visible.filter(column => column.pinned === 'left').length
  pinnedRight.value = visible.filter(column => column.pinned === 'right').length
  scheduleSave()
}
const changePins = () => {
  restoring = true
  applyPins()
  restoring = false
  scheduleSave()
}
const restore = (value: OmsGridPreference) => {
  if (!api.value) return
  restoring = true
  density.value = value.density
  pageSize.value = props.serverPagination && value.pageSize==='all' && !props.allowAllRows ? 20 : value.pageSize
  featurePreferences.value=sanitizeGridFeatures(value.features)
  pinnedLeft.value = value.pinnedLeft
  pinnedRight.value = value.pinnedRight
  const limits = Object.fromEntries(leafColumns.value.map(col => [
    col.colId ?? col.field ?? '', { minWidth: col.minWidth, maxWidth: col.maxWidth },
  ]))
  const state = restoreColumnWidths(value.columns, limits).map(col => ({
    ...col,
    hide: required.value.has(col.colId) ? false : col.hide,
    sort: props.serverPagination
      ? (props.serverSort?.colId === col.colId ? props.serverSort.direction : null)
      : col.sort,
  }))
  api.value.applyColumnState({ state, applyOrder: true })
  applyPins()
  if (!props.serverPagination) api.value.setGridOption('paginationPageSize', resolvedPageSize())
  restoring = false
  refresh()
}
// Never expose AG Grid's default column order before personal settings are
// applied. A user-scoped local snapshot is instant on repeat navigation, and
// requires no network request; cold browsers fetch the account copy ONCE.
const load = async () => {
  const current = storageKey.value ? readCache(storageKey.value) : null
  const getServer = async (namespace: string): Promise<OmsGridPreference | null> => {
    if (props.preferenceScope !== 'account') return null
    try {
      const response = await axios.get('/ui/preferences/' + encodeURIComponent(namespace),{timeout:2000})
      return sanitizeOmsGridPreference(response.data?.value,ids.value)
    } catch { return null }
  }
  await waitForPreferenceWrites(preferenceKey)
  if(disposed || actorId.value!==ownerAtMount)return
  let value = await getServer(namespaceAtMount)
  if (!value) {
    for (const namespace of props.legacyPreferenceNamespaces) {
      if (!namespace || namespace === props.namespace) continue
      value = (actorId.value > 0 ? readCache(scopedStorageKey(namespace)) : null)
        ?? await getServer(namespace)
      if (value) break
    }
  }
  // Unscoped legacy browser values have no owner identity. Never import another
  // person's layout into an authenticated account on a shared browser.
  if(disposed || actorId.value!==ownerAtMount)return
  if (value || current) { restore((value || current)!); cache(snapshot()) }
  else { restoring = true; applyPins(); restoring = false }
}
const onReady = (event: GridReadyEvent<GridRow>) => {
  api.value = event.api
  restoring = true
  refresh()
  restoreSelections()
  void load().finally(() => {
    if(disposed)return
    restoring = false
    // A failed preference request still reveals a usable stable default.
    layoutReady.value = true
    refresh()
    if(props.serverPagination && pageSize.value!==props.serverPagination.per_page) emit('page-size-change',pageSize.value)
    // The layout preference service may move/reset horizontal columns
    // *after* Inertia's page mount. Notify D5 only once layout is stable.
    // Event is harmless outside the opted-in workspace.
    void nextTick(() => window.requestAnimationFrame(() => {
      window.dispatchEvent(new CustomEvent('erp:workspace-grid-layout-ready'))
    }))
  })
}
const toggleColumn = (colId: string, show: boolean) => {
  if (!api.value || (required.value.has(colId) && !show)) return
  api.value.setColumnsVisible([colId], show)
  restoring = true
  applyPins()
  restoring = false
  scheduleSave()
}
const startDrag = (event: DragEvent, colId: string) => {
  dragged = colId
  event.dataTransfer?.setData('text/plain', colId)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}
const dropColumn = (event: DragEvent, target: string) => {
  event.preventDefault()
  const source = dragged ?? event.dataTransfer?.getData('text/plain')
  dragged = null
  if (!source || source === target || !api.value) return
  const state = api.value.getColumnState()
  if (!Array.isArray(state)) return
  const from = state.findIndex(col => col.colId === source)
  const to = state.findIndex(col => col.colId === target)
  if (from < 0 || to < 0) return
  const [moved] = state.splice(from, 1)
  if (!moved) return
  state.splice(to, 0, moved)
  restoring = true
  api.value.applyColumnState({ state, applyOrder: true })
  applyPins()
  restoring = false
  scheduleSave()
}
const resetGrid = () => {
  if (!api.value) return
  restoring = true
  api.value.resetColumnState()
  // AG Grid's resetColumnState can retain a previously hidden column when a
  // locked pinned column becomes freely movable. "恢复默认" must explicitly
  // restore original per-definition visibility before recomputing pins.
  api.value.applyColumnState({ state: leafColumns.value.map(column => ({
    colId: column.colId ?? column.field ?? '',
    hide: (column.hide??column.initialHide) === true,
  })).filter(column => column.colId) })
  api.value.setFilterModel(null)
  api.value.setGridOption('quickFilterText', '')
  query.value = ''
  density.value = 'compact'
  pageSize.value = props.serverPagination ? 20 : 50
  featurePreferences.value={}
  if (!props.serverPagination) api.value.setGridOption('paginationPageSize', 50)
  pinnedLeft.value = props.defaultPinnedLeft
  pinnedRight.value = props.defaultPinnedRight
  applyPins()
  // Explicit Grid API visibility is needed for formerly lockVisible columns:
  // resetting their state and pinning alone does not always re-show them.
  api.value.setColumnsVisible(leafColumns.value
    .filter(column => (column.hide??column.initialHide) !== true)
    .map(column => column.colId ?? column.field ?? '').filter(Boolean), true)
  restoring = false
  // Commit the preference cache BEFORE a real server-page navigation.
  // Previously every reset emitted page-size-change(50), including when
  // already 50, immediately reloading an obsolete server-saved layout.
  scheduleSave()
  if (props.serverPagination && (props.serverPagination.page_size_mode ?? props.serverPagination.per_page) !== pageSize.value) {
    emit('page-size-change', pageSize.value)
  }
}
const changePageSize = (event: Event) => {
  const selector = event.target as HTMLSelectElement
  if (selector.value === 'all') {
    if (!props.allowAllRows) { selector.value = String(pageSize.value); return }
    const count = api.value?.getDisplayedRowCount() ?? props.rows.length
    const confirmed = window.confirm(`“全部”将展示当前筛选结果的 ${count} 条记录。数据过多可能使页面卡顿并增加浏览器负载。是否继续？`)
    if (!confirmed) { selector.value = String(pageSize.value); return }
    pageSize.value = 'all'
  } else {
    const next = Number(selector.value)
    if (![20, 50, 100].includes(next)) { selector.value = String(pageSize.value); return }
    pageSize.value = next
  }
  api.value?.setGridOption('paginationPageSize', resolvedPageSize())
  scheduleSave()
}
const changeDensity = () => { density.value = density.value === 'compact' ? 'standard' : 'compact'; scheduleSave() }
const changeServerPageSize = (event: Event) => {
  const selector = event.target as HTMLSelectElement
  const previous = String(props.serverPagination?.page_size_mode ?? props.serverPagination?.per_page ?? 50)
  if (selector.value === 'all') {
    if (!props.allowAllRows) { selector.value = previous; return }
    const total = props.serverPagination?.total ?? 0
    if (total > 200) {
      window.alert('当前筛选共有 ' + total + ' 条订单，超过 200 条安全上限。请先缩小筛选范围，再选择“全部”；导出仍可使用原有服务器导出功能。')
      selector.value = previous
      return
    }
    if (!window.confirm('将加载当前筛选的全部 ' + total + ' 条订单，可能增加浏览器压力。是否继续？')) {
      selector.value = previous
      return
    }
    pageSize.value='all'; scheduleSave(); emit('page-size-change', 'all')
  } else {
    const next = Number(selector.value)
    if (![20, 50, 100].includes(next)) { selector.value = previous; return }
    pageSize.value=next; scheduleSave(); emit('page-size-change', next)
  }
}
const onSortChanged = (event: SortChangedEvent<GridRow>) => {
  scheduleSave()
  if (!props.serverPagination || restoring || !api.value || !toolEnabled('sort')) return
  const chosen = (event.api.getColumnState() ?? []).find(column => column.sort === 'asc' || column.sort === 'desc')
  const colId = chosen?.colId ?? 'date'
  const direction = chosen?.sort === 'asc' || chosen?.sort === 'desc' ? chosen.sort : null
  if (props.serverSort?.colId === colId && props.serverSort.direction === (direction ?? 'desc')) return
  emit('sort-change', { colId, direction })
}
const toggleMaximize = async () => {
  maximized.value = !maximized.value
  document.body.style.overflow = maximized.value ? 'hidden' : ''
  await nextTick()
  api.value?.refreshHeader()
}
watch(() => props.serverPagination, () => refresh(), { deep: true })
const setTool=(key:GridFeature,enabled:boolean)=>{
  if(!featureCapabilities.value[key]||!layoutReady.value)return
  featurePreferences.value={...featurePreferences.value,[key]:enabled}
  if(!enabled && key==='maximize' && maximized.value)void toggleMaximize()
  if(!enabled && key==='search')clearSearch()
  if(!enabled && key==='filter')emit('clear-column-filters')
  if(!enabled && key==='sort')emit('sort-change',{colId:props.serverSort?.colId??'line',direction:null})
  if(!enabled && key==='selection'){api.value?.deselectAll();emit('selection-change',[])}
  scheduleSave()
}
const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && settingsOpen.value) {settingsOpen.value=false;return}
  if (event.key === 'Escape' && maximized.value) void toggleMaximize()
}
if (typeof window !== 'undefined') window.addEventListener('keydown', onKeydown)
onDeactivated(()=>{
  settingsOpen.value=false
  if(maximized.value){maximized.value=false;document.body.style.overflow=''}
})
onBeforeUnmount(() => {
  disposed=true
  if (timer) { clearTimeout(timer); timer = undefined }
  // An Inertia navigation or successful batch review may unmount this Grid
  // before the 450ms debounce expires. Persist the captured human edit now.
  if (pendingValue) void save()
  if (searchTimer) clearTimeout(searchTimer)
  if (typeof window !== 'undefined') window.removeEventListener('keydown', onKeydown)
  if (maximized.value) document.body.style.overflow = ''
})
</script>

<template>
  <section class="oms-grid" :class="{ 'is-maximized': maximized, 'is-auto-height': autoHeight, 'is-empty-short-list': isEmptyShortList }" aria-label="BYL ERP OMS 统一表格">
    <div class="oms-toolbar">
      <div class="oms-title"><strong>{{ title }}</strong></div>
      <div class="oms-actions"><slot name="actions" /></div>
      <div class="oms-spacer" />
      <input v-if="toolEnabled('search')" v-model="query" class="oms-search" :aria-label="searchLabel ?? (serverPagination ? '搜索全部订单' : '搜索列表')" :placeholder="searchPlaceholder ?? (serverPagination ? '搜索全部订单 / 收件人 / 来源单号...' : '搜索当前列表...')" @input="searchInput" @keydown.enter.prevent="submitSearch" />
      <button v-if="toolEnabled('search')" type="button" @click="submitSearch">搜索</button>
      <button v-if="toolEnabled('search') && query" type="button" aria-label="清空表格搜索" @click="clearSearch">×</button>
      <button v-if="!serverPagination && showClientCsvExport" type="button" title="导出当前表格为 CSV" @click="api?.exportDataAsCsv()">导出 CSV</button>
      <button type="button" :disabled="!layoutReady" :aria-pressed="settingsOpen" @click="settingsOpen = !settingsOpen">⚙ 列设置</button>
      <button v-if="toolEnabled('density')" type="button" :disabled="!layoutReady" @click="changeDensity">密度：{{ density === 'compact' ? '紧凑' : '标准' }}</button>
      <button v-if="toolEnabled('maximize') || maximized" type="button" :aria-label="maximized ? '退出最大化' : '最大化列表'" :title="maximized ? '退出最大化 (Esc)' : '最大化列表' " @click="toggleMaximize">{{ maximized ? '⤢ 退出' : '⛶ 最大化' }}</button>
    </div>
    <div class="oms-workspace">
      <div class="oms-main">
        <AgGridVue
          class="oms-ag-grid"
          :style="{visibility:layoutReady ? 'visible' : 'hidden'}"
          :dom-layout="autoHeight ? 'autoHeight' : 'normal'"
          :theme="theme"
          :row-data="rows"
          :enable-cell-text-selection="toolEnabled('copy')"
          :ensure-dom-order="toolEnabled('copy')"
          :column-defs="leafColumns"
          :maintain-column-order="true"
          :default-col-def="effectiveDefaultColDef"
          :row-selection="toolEnabled('selection') ? rowSelection : undefined"
          :get-row-id="rowIdentityKey ? getRowId : undefined"
          :row-height="rowHeight"
          :header-height="headerHeight"
          :floating-filters-height="headerHeight"
          :pagination="!serverPagination"
          :pagination-page-size="50"
          :pagination-page-size-selector="false"
          :locale-text="localeText"
          :animate-rows="false"
          @grid-ready="onReady"
          @cell-clicked="emit('cell-clicked',$event)"
          @column-moved="scheduleSave"
          @column-resized="scheduleSave"
          @column-visible="scheduleSave"
          @column-pinned="onColumnPinned"
          @sort-changed="onSortChanged"
          @filter-changed="onFiltered"
          @model-updated="() => { onFiltered(); restoreSelections() }"
          @row-selected="onRowSelected"
          @selection-changed="onSelectionChanged"
        />
        <div v-if="!layoutReady" class="oms-grid-layout-loading" role="status">正在恢复表格布局…</div>
        <div v-if="!serverPagination" class="oms-pagination-size"><label for="oms-grid-page-size">每页</label><select id="oms-grid-page-size" :value="pageSize" aria-label="每页显示条数" @change="changePageSize"><option value="20">20</option><option value="50">50</option><option value="100">100</option><option v-if="allowAllRows" value="all">全部</option></select></div>
        <div v-if="serverPagination" class="oms-server-pagination">
          <div class="oms-footer-left"><slot name="footer-left" :selected-count="selectedCount" /></div>
          <div class="oms-footer-right">
            <label v-if="toolEnabled('pageSize')">每页 <select aria-label="服务端每页条数" :value="serverPagination.page_size_mode ?? serverPagination.per_page" @change="changeServerPageSize"><option value="20">20</option><option value="50">50</option><option value="100">100</option><option v-if="allowAllRows" value="all">全部</option></select></label>
            <span>共 {{ serverPagination.total }} 条 · 第 {{ serverPagination.current_page }} / {{ Math.max(1, serverPagination.last_page) }} 页</span>
            <button type="button" aria-label="上一页" :disabled="serverPagination.current_page <= 1" @click="emit('page-change', serverPagination.current_page - 1)">‹</button>
            <button type="button" aria-label="下一页" :disabled="serverPagination.current_page >= serverPagination.last_page" @click="emit('page-change', serverPagination.current_page + 1)">›</button>
          </div>
        </div>
      </div>
    </div>
    <Teleport to="body">
      <aside v-if="settingsOpen" class="oms-side" aria-label="列设置" role="dialog" data-testid="unified-grid-settings">
        <div class="oms-side-head"><strong>列设置</strong><button type="button" aria-label="关闭列设置" @click="settingsOpen = false">×</button></div>
        <div class="oms-side-scroll" data-testid="unified-grid-settings-scroll">
        <p class="oms-side-help">拖拽调整顺序；取消勾选隐藏列。</p>
        <div class="oms-pin-settings">
          <strong>固定列</strong>
          <div class="oms-pin-row"><label>左侧固定 <input v-model.number="pinnedLeft" type="number" min="0" :max="Math.max(0, visibleColumns.length - 1)" @change="changePins" /> 列</label><label>右侧固定 <input v-model.number="pinnedRight" type="number" min="0" :max="Math.max(0, visibleColumns.length - 1)" @change="changePins" /> 列</label></div>
          <small>不包含独立的勾选列；始终保留中间滚动区域。</small>
        </div>
        <div class="oms-pin-settings" data-testid="unified-grid-tools"><strong>表格功能</strong>
          <label v-for="key in availableTools" :key="key" class="oms-tool-setting"><input type="checkbox" :checked="toolEnabled(key)" :disabled="!featureCapabilities[key]" @change="setTool(key,($event.target as HTMLInputElement).checked)" />{{ gridFeatureLabels[key] }}<small v-if="!featureCapabilities[key]">当前页面不支持</small></label>
        </div>
        <div class="oms-column-list">
          <div v-for="column in orderedColumns" :key="column.colId" class="oms-column-row" draggable="true" @dragstart="startDrag($event, column.colId)" @dragover.prevent @drop="dropColumn($event, column.colId)">
            <span class="oms-drag" aria-hidden="true">⋮⋮</span>
            <input type="checkbox" :aria-label="'显示'+(names.get(column.colId)||column.colId)"
              :checked="!column.hide" :disabled="required.has(column.colId)" @change="toggleColumn(column.colId, ($event.target as HTMLInputElement).checked)" />
            <span>{{ names.get(column.colId) || column.colId }}</span>
            <small>{{ column.pinned === 'left' ? '左固定' : column.pinned === 'right' ? '右固定' : '' }}</small>
          </div>
        </div>
        </div>
        <div class="oms-side-bottom"><button type="button" @click="resetGrid">恢复默认</button><button class="primary" type="button" @click="settingsOpen = false">完成</button></div>
      </aside>
    </Teleport>
  </section>
</template>
<style scoped>
.oms-grid{display:flex;flex-direction:column;width:100%;height:calc(100vh - 164px);min-height:500px;background:#fff;border:1px solid #dbe1e9;border-radius:8px;overflow:hidden;color:#1e293b}
/* AG Grid's autoHeight includes only visible rows and keeps pager immediately
   below them, without the 500px minimum used for large virtualized lists. */
.oms-grid.is-auto-height{height:auto;min-height:0}
.oms-grid.is-auto-height .oms-workspace{flex:0 0 auto}
.oms-grid.is-auto-height .oms-ag-grid{flex:0 0 auto}
.oms-grid.is-maximized{position:fixed;inset:0;z-index:10000;width:100vw;height:100dvh;min-height:0;border:0;border-radius:0}
.oms-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border-bottom:1px solid #dbe1e9}
.oms-toolbar button,.oms-pagination-size select,.oms-side button{border:1px solid #cbd5e1;background:white;border-radius:5px;padding:6px 10px;font-size:12px;cursor:pointer;color:#1e293b}
.oms-toolbar button:hover,.oms-side button:hover{border-color:#2563eb;color:#1d4ed8}
.oms-title{display:flex;flex-direction:column;min-width:155px;gap:2px;font-size:14px}.oms-title small{font-size:11px;color:#64748b}
.oms-spacer{flex:1}.oms-actions{display:flex;gap:6px}.oms-search{width:min(200px,35vw);height:30px;padding:5px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px}
.oms-workspace{display:flex;min-height:0;flex:1}.oms-main{position:relative;display:flex;flex-direction:column;min-width:0;flex:1}.oms-ag-grid{min-height:0;flex:1;width:100%}
.oms-grid-layout-loading{position:absolute;inset:40px 0 40px;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:12px;pointer-events:none}
.oms-pagination-size{position:absolute;left:12px;bottom:0;z-index:2;height:32px;display:flex;align-items:center;gap:6px;background:#fff;font-size:11px;color:#64748b}.oms-pagination-size select{padding:3px 5px;min-width:66px;font-size:12px}
.oms-server-pagination{height:38px;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 14px;border-top:1px solid #dbe1e9;font-size:12px;color:#526072;white-space:nowrap}
.oms-footer-left{display:flex;align-items:center;min-width:0;flex:1;gap:8px}
.oms-footer-right{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-shrink:0}
.oms-server-pagination select,.oms-server-pagination button{padding:4px 8px;border:1px solid #cbd5e1;background:#fff;border-radius:5px;color:#1e293b}
.oms-server-pagination button:disabled{opacity:.4;cursor:default}
.oms-side{position:fixed;top:12px;right:12px;bottom:12px;z-index:10010;width:min(340px,calc(100vw - 24px));display:flex;flex-direction:column;min-height:0;max-height:calc(100dvh - 24px);overflow:hidden;border:1px solid #d6deea;border-radius:8px;background:#fff;color:#1e293b;box-shadow:0 8px 30px #0f172a26}
.oms-side-scroll{flex:1 1 0;min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable;touch-action:pan-y}
.oms-side-head,.oms-side-bottom{flex:none;background:#fff}
.oms-tool-setting{display:flex;align-items:center;gap:6px;margin:5px 0}.oms-tool-setting small{margin-left:auto}
.oms-side-head{padding:12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0}.oms-side-head button{border:none;font-size:20px;padding:0 5px}
.oms-side-help{font-size:11px;color:#64748b;margin:9px 12px}.oms-pin-settings{margin:0 10px 10px;padding:10px;background:#f1f5ff;border:1px solid #d4defa;border-radius:6px;font-size:12px}
.oms-pin-row{display:flex;gap:8px;margin:9px 0}.oms-pin-row label{font-size:11px;flex:1}.oms-pin-row input{width:47px;padding:3px;border:1px solid #cbd5e1;border-radius:4px}.oms-pin-settings small{color:#64748b;font-size:10px}
.oms-column-list{padding:0 10px 10px}.oms-column-row{display:flex;align-items:center;gap:7px;min-height:29px;font-size:12px;cursor:grab}.oms-column-row input{accent-color:#2563eb}.oms-column-row small{margin-left:auto;color:#2563eb;font-size:10px}.oms-drag{color:#94a3b8;letter-spacing:-2px}
.oms-side-bottom{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0}.oms-side-bottom button{flex:1}.oms-side-bottom .primary{background:#155dfb;border-color:#155dfb;color:white}
@media(max-width:850px){.oms-side{width:min(340px,calc(100vw - 24px))}.oms-title{min-width:100px}.oms-toolbar{gap:5px}.oms-toolbar button{padding:5px 7px}}
</style>
