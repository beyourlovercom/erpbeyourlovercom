<script setup lang="ts">
import axios from 'axios'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { AgGridVue } from 'ag-grid-vue3'
import {
  AllCommunityModule, ModuleRegistry, themeBalham,
  type ColDef, type ColGroupDef, type ColumnState, type GridApi, type GridReadyEvent, type SortChangedEvent,
} from 'ag-grid-community'
import { clampPinnedCounts, pinnedStateForVisibleColumns, sanitizeOmsGridPreference, type OmsDensity, type OmsGridPreference } from './omsGridState'

ModuleRegistry.registerModules([AllCommunityModule])
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
  defaultPinnedLeft?: number
  defaultPinnedRight?: number
  requiredColumnIds?: string[]
  serverPagination?: ServerPagination
  serverSort?: ServerSort
}>(), {
  title: 'OMS Grid', preferenceScope: 'account', defaultPinnedLeft: 1,
  defaultPinnedRight: 1, requiredColumnIds: () => [],
})

const emit = defineEmits<{
  (event: 'selection-change', rows: GridRow[]): void
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'sort-change', sort: { colId: string; direction: 'asc' | 'desc' | null }): void
}>()
const flatten = (definitions: GridColumn[]): ColDef<GridRow>[] =>
  definitions.flatMap(column => 'children' in column && column.children
    ? flatten(column.children as GridColumn[]) : [column as ColDef<GridRow>])
// Keep column definitions flat: no redundant first-level group header row.
const leafColumns = computed(() => flatten(props.columns).map(col => {
  if (!props.serverPagination) return col
  const key = col.colId ?? col.field ?? ''
  return { ...col, filter: false, floatingFilter: false,
    sort: props.serverSort?.colId === key ? props.serverSort.direction : null }
}))
const ids = computed(() => leafColumns.value.map(col => col.colId ?? col.field ?? '').filter(Boolean))
const names = computed(() => new Map(leafColumns.value.map(col => [col.colId ?? col.field ?? '', col.headerName ?? col.field ?? ''])))
const required = computed(() => new Set(props.requiredColumnIds))
const api = ref<GridApi<GridRow> | null>(null)
const states = ref<ColumnState[]>([])
const settingsOpen = ref(false)
const maximized = ref(false)
const density = ref<OmsDensity>('compact')
const pageSize = ref<number | 'all'>(50)
const pinnedLeft = ref(props.defaultPinnedLeft)
const pinnedRight = ref(props.defaultPinnedRight)
const query = ref('')
const selectedCount = ref(0)
const visibleCount = ref(0)
const syncState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle')
const theme = themeBalham.withParams({ fontSize: 12, spacing: 5, headerFontSize: 12 })
const storageKey = computed(() => 'oms-grid-pref:' + props.namespace)
const defaultColDef: ColDef<GridRow> = { sortable: true, filter: true, floatingFilter: true, resizable: true, minWidth: 85 }
const effectiveDefaultColDef = computed<ColDef<GridRow>>(() => props.serverPagination
  ? { ...defaultColDef, sortable: false, filter: false, floatingFilter: false }
  : defaultColDef)
const rowSelection = { mode: 'multiRow' as const, selectAll: 'currentPage' as const }
const localeText = { page: '页', to: '至', of: '共', nextPage: '下一页', previousPage: '上一页', firstPage: '首页', lastPage: '末页', pageSizeSelectorLabel: '每页', noRowsToShow: '暂无数据' }
const rowHeight = computed(() => density.value === 'compact' ? 32 : 42)
const headerHeight = computed(() => density.value === 'compact' ? 31 : 38)
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
let dragged: string | null = null

const refresh = () => {
  if (!api.value) return
  states.value = api.value.getColumnState()
  visibleCount.value = props.serverPagination?.total ?? api.value.getDisplayedRowCount()
  selectedCount.value = api.value.getSelectedRows().length
}
const snapshot = (): OmsGridPreference => ({
  version: 1, columns: api.value?.getColumnState().filter(c => ids.value.includes(c.colId)) ?? [],
  pinnedLeft: pinnedLeft.value, pinnedRight: pinnedRight.value,
  density: density.value, pageSize: pageSize.value,
})
const cache = (value: OmsGridPreference) => {
  try { window.localStorage.setItem(storageKey.value, JSON.stringify(value)) } catch { /* local cache is optional */ }
}
const save = async () => {
  if (!api.value) return
  const value = snapshot()
  cache(value)
  if (props.preferenceScope === 'local') { syncState.value = 'saved'; return }
  syncState.value = 'saving'
  try {
    await axios.put('/ui/preferences/' + encodeURIComponent(props.namespace), { value })
    syncState.value = 'saved'
  } catch { syncState.value = 'error' }
}
const scheduleSave = () => {
  if (restoring || !api.value) return
  refresh()
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { void save() }, 450)
}
const applyPins = () => {
  if (!api.value) return
  const state = api.value.getColumnState().filter(c => ids.value.includes(c.colId))
  const counts = clampPinnedCounts(state.filter(c => !c.hide).length, pinnedLeft.value, pinnedRight.value)
  pinnedLeft.value = counts.left
  pinnedRight.value = counts.right
  const pinned = pinnedStateForVisibleColumns(state, counts.left, counts.right)
  api.value.applyColumnState({ state: pinned })
  refresh()
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
  if (!props.serverPagination) pageSize.value = value.pageSize
  pinnedLeft.value = value.pinnedLeft
  pinnedRight.value = value.pinnedRight
  const state = value.columns.map(col => ({
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
const load = async () => {
  let raw: unknown = null
  try { raw = JSON.parse(window.localStorage.getItem(storageKey.value) ?? 'null') } catch { /* ignore */ }
  if (props.preferenceScope === 'account') {
    try {
      const response = await axios.get('/ui/preferences/' + encodeURIComponent(props.namespace))
      if (response.data?.value) raw = response.data.value
    } catch { /* use local cache when disconnected */ }
  }
  const value = sanitizeOmsGridPreference(raw, ids.value)
  if (value) restore(value)
  else { restoring = true; applyPins(); restoring = false }
}
const onReady = (event: GridReadyEvent<GridRow>) => {
  api.value = event.api
  refresh()
  void load()
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
  const from = state.findIndex(col => col.colId === source)
  const to = state.findIndex(col => col.colId === target)
  if (from < 0 || to < 0) return
  const [moved] = state.splice(from, 1)
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
  api.value.setFilterModel(null)
  api.value.setGridOption('quickFilterText', '')
  query.value = ''
  density.value = 'compact'
  pageSize.value = 50
  if (!props.serverPagination) api.value.setGridOption('paginationPageSize', 50)
  else emit('page-size-change', 50)
  pinnedLeft.value = props.defaultPinnedLeft
  pinnedRight.value = props.defaultPinnedRight
  applyPins()
  restoring = false
  scheduleSave()
}
const changePageSize = (event: Event) => {
  const selector = event.target as HTMLSelectElement
  if (selector.value === 'all') {
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
    emit('page-size-change', 'all')
  } else {
    const next = Number(selector.value)
    if (![20, 50, 100].includes(next)) { selector.value = previous; return }
    emit('page-size-change', next)
  }
}
const onSortChanged = (event: SortChangedEvent<GridRow>) => {
  scheduleSave()
  if (!props.serverPagination || restoring || !api.value) return
  const chosen = event.api.getColumnState().find(column => column.sort === 'asc' || column.sort === 'desc')
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
const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && maximized.value) void toggleMaximize()
}
if (typeof window !== 'undefined') window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
  if (typeof window !== 'undefined') window.removeEventListener('keydown', onKeydown)
  if (maximized.value) document.body.style.overflow = ''
})
</script>

<template>
  <section class="oms-grid" :class="{ 'is-maximized': maximized }" aria-label="BYL ERP OMS 统一表格">
    <div class="oms-toolbar">
      <div class="oms-title"><strong>{{ title }}</strong><small>共 {{ (serverPagination?.total ?? rows.length).toLocaleString('zh-CN') }} 条 · 已选 {{ selectedCount }} 条</small></div>
      <div class="oms-actions"><slot name="actions" /></div>
      <div class="oms-spacer" />
      <input v-if="!serverPagination" v-model="query" class="oms-search" aria-label="搜索列表" placeholder="搜索当前列表..." />
      <button v-if="!serverPagination" type="button" @click="api?.setGridOption('quickFilterText', query)">搜索</button>
      <button v-if="!serverPagination" type="button" title="导出当前表格为 CSV" @click="api?.exportDataAsCsv()">导出 CSV</button>
      <button type="button" :aria-pressed="settingsOpen" @click="settingsOpen = !settingsOpen">⚙ 列设置</button>
      <button type="button" @click="changeDensity">密度：{{ density === 'compact' ? '紧凑' : '标准' }}</button>
      <button type="button" :aria-label="maximized ? '退出最大化' : '最大化列表'" :title="maximized ? '退出最大化 (Esc)' : '最大化列表' " @click="toggleMaximize">{{ maximized ? '⤢ 退出' : '⛶ 最大化' }}</button>
    </div>
    <div class="oms-workspace">
      <div class="oms-main">
        <AgGridVue
          class="oms-ag-grid"
          :theme="theme"
          :row-data="rows"
          :column-defs="leafColumns"
          :default-col-def="effectiveDefaultColDef"
          :row-selection="rowSelection"
          :row-height="rowHeight"
          :header-height="headerHeight"
          :floating-filters-height="headerHeight"
          :pagination="!serverPagination"
          :pagination-page-size="50"
          :pagination-page-size-selector="false"
          :locale-text="localeText"
          :animate-rows="false"
          @grid-ready="onReady"
          @column-moved="scheduleSave"
          @column-resized="scheduleSave"
          @column-visible="scheduleSave"
          @column-pinned="scheduleSave"
          @sort-changed="onSortChanged"
          @filter-changed="onFiltered"
          @model-updated="onFiltered"
          @selection-changed="(e: any) => { refresh(); emit('selection-change', e.api.getSelectedRows()) }"
        />
        <div v-if="!serverPagination" class="oms-pagination-size"><label for="oms-grid-page-size">每页</label><select id="oms-grid-page-size" :value="pageSize" aria-label="每页显示条数" @change="changePageSize"><option value="20">20</option><option value="50">50</option><option value="100">100</option><option value="all">全部</option></select></div>
        <div v-if="serverPagination" class="oms-server-pagination">
          <label>每页 <select aria-label="服务端每页条数" :value="serverPagination.page_size_mode ?? serverPagination.per_page" @change="changeServerPageSize"><option value="20">20</option><option value="50">50</option><option value="100">100</option><option value="all">全部</option></select></label>
          <span>共 {{ serverPagination.total }} 条 · 第 {{ serverPagination.current_page }} / {{ Math.max(1, serverPagination.last_page) }} 页</span>
          <button type="button" aria-label="上一页" :disabled="serverPagination.current_page <= 1" @click="emit('page-change', serverPagination.current_page - 1)">‹</button>
          <button type="button" aria-label="下一页" :disabled="serverPagination.current_page >= serverPagination.last_page" @click="emit('page-change', serverPagination.current_page + 1)">›</button>
        </div>
      </div>
      <aside v-if="settingsOpen" class="oms-side" aria-label="列设置">
        <div class="oms-side-head"><strong>列设置</strong><button type="button" aria-label="关闭列设置" @click="settingsOpen = false">×</button></div>
        <p class="oms-side-help">拖拽调整顺序；取消勾选隐藏列。</p>
        <div class="oms-pin-settings">
          <strong>固定列</strong>
          <div class="oms-pin-row"><label>左侧固定 <input v-model.number="pinnedLeft" type="number" min="0" :max="Math.max(0, visibleColumns.length - 1)" @change="changePins" /> 列</label><label>右侧固定 <input v-model.number="pinnedRight" type="number" min="0" :max="Math.max(0, visibleColumns.length - 1)" @change="changePins" /> 列</label></div>
          <small>不包含独立的勾选列；始终保留中间滚动区域。</small>
        </div>
        <div class="oms-column-list">
          <label v-for="(column, index) in orderedColumns" :key="column.colId" class="oms-column-row" draggable="true" @dragstart="startDrag($event, column.colId)" @dragover.prevent @drop="dropColumn($event, column.colId)">
            <span class="oms-drag">⋮⋮</span><input type="checkbox" :checked="!column.hide" :disabled="required.has(column.colId)" @change="toggleColumn(column.colId, ($event.target as HTMLInputElement).checked)" /><span>{{ names.get(column.colId) || column.colId }}</span><small>{{ index < pinnedLeft && !column.hide ? '左固定' : !column.hide && index >= orderedColumns.length - pinnedRight ? '右固定' : '' }}</small>
          </label>
        </div>
        <div class="oms-side-bottom"><button type="button" @click="resetGrid">恢复默认</button><button class="primary" type="button" @click="settingsOpen = false">完成</button></div>
      </aside>
    </div>
  </section>
</template>
<style scoped>
.oms-grid{display:flex;flex-direction:column;width:100%;height:calc(100vh - 164px);min-height:500px;background:#fff;border:1px solid #dbe1e9;border-radius:8px;overflow:hidden;color:#1e293b}
.oms-grid.is-maximized{position:fixed;inset:0;z-index:10000;width:100vw;height:100dvh;min-height:0;border:0;border-radius:0}
.oms-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border-bottom:1px solid #dbe1e9}
.oms-toolbar button,.oms-pagination-size select,.oms-side button{border:1px solid #cbd5e1;background:white;border-radius:5px;padding:6px 10px;font-size:12px;cursor:pointer;color:#1e293b}
.oms-toolbar button:hover,.oms-side button:hover{border-color:#2563eb;color:#1d4ed8}
.oms-title{display:flex;flex-direction:column;min-width:155px;gap:2px;font-size:14px}.oms-title small{font-size:11px;color:#64748b}
.oms-spacer{flex:1}.oms-actions{display:flex;gap:6px}.oms-search{width:min(200px,35vw);height:30px;padding:5px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px}
.oms-workspace{display:flex;min-height:0;flex:1}.oms-main{position:relative;display:flex;flex-direction:column;min-width:0;flex:1}.oms-ag-grid{min-height:0;flex:1;width:100%}
.oms-pagination-size{position:absolute;left:12px;bottom:0;z-index:2;height:32px;display:flex;align-items:center;gap:6px;background:#fff;font-size:11px;color:#64748b}.oms-pagination-size select{padding:3px 5px;min-width:66px;font-size:12px}
.oms-server-pagination{height:38px;display:flex;align-items:center;justify-content:flex-end;gap:12px;padding:0 14px;border-top:1px solid #dbe1e9;font-size:12px;color:#526072;white-space:nowrap}
.oms-server-pagination select,.oms-server-pagination button{padding:4px 8px;border:1px solid #cbd5e1;background:#fff;border-radius:5px;color:#1e293b}
.oms-server-pagination button:disabled{opacity:.4;cursor:default}
.oms-side{width:268px;flex:none;display:flex;flex-direction:column;min-height:0;border-left:1px solid #d6deea;background:#fff}
.oms-side-head{padding:12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0}.oms-side-head button{border:none;font-size:20px;padding:0 5px}
.oms-side-help{font-size:11px;color:#64748b;margin:9px 12px}.oms-pin-settings{margin:0 10px 10px;padding:10px;background:#f1f5ff;border:1px solid #d4defa;border-radius:6px;font-size:12px}
.oms-pin-row{display:flex;gap:8px;margin:9px 0}.oms-pin-row label{font-size:11px;flex:1}.oms-pin-row input{width:47px;padding:3px;border:1px solid #cbd5e1;border-radius:4px}.oms-pin-settings small{color:#64748b;font-size:10px}
.oms-column-list{flex:1;overflow-y:auto;padding:0 10px}.oms-column-row{display:flex;align-items:center;gap:7px;min-height:29px;font-size:12px;cursor:grab}.oms-column-row input{accent-color:#2563eb}.oms-column-row small{margin-left:auto;color:#2563eb;font-size:10px}.oms-drag{color:#94a3b8;letter-spacing:-2px}
.oms-side-bottom{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0}.oms-side-bottom button{flex:1}.oms-side-bottom .primary{background:#155dfb;border-color:#155dfb;color:white}
@media(max-width:850px){.oms-side{width:215px}.oms-title{min-width:100px}.oms-toolbar{gap:5px}.oms-toolbar button{padding:5px 7px}}
</style>
