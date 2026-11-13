<script setup lang="ts">
import { computed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGrid from '../../../Components/DataGrid/GlobalGrid.vue'
import GlobalGridCell from '../../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, type GridListRow } from '../../../Components/DataGrid/useGlobalGridListAdapter'

export type KolModuleColumn = {
  key: string; label: string; width?: number; hidden?: boolean
  kind?: 'text' | 'link' | 'badge' | 'actions'
  hrefFor?: (row: GridListRow) => string
  actionsFor?: (row: GridListRow) => {label: string; href?: string; onClick?: () => void; disabled?: boolean}[]
}
const props = withDefaults(defineProps<{
  namespace: string; title: string; rows: GridListRow[]; columns: KolModuleColumn[]
  page?: {current_page: number; last_page: number; per_page: number; total: number} | null
  search?: string; searchPlaceholder?: string
  enableSelection?: boolean; selectedIds?: number[]; rowKey?: string
}>(), {page: null, enableSelection: false, selectedIds: () => [], rowKey: 'id'})
const emit = defineEmits<{
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'search-change', term: string): void
  (event: 'selection-change', rows: GridListRow[]): void
}>()
const definitions = computed<ColDef<GridListRow>[]>(() => props.columns.map((column) => gridColumn({
  colId: column.key, field: column.key, headerName: column.label, width: column.width ?? 155,
  hide: column.hidden ?? false, sortable: !props.page,
  ...(column.kind && column.kind !== 'text' ? {
    cellRenderer: GlobalGridCell,
    cellRendererParams: {
      mode: column.kind,
      ...(column.hrefFor ? {hrefFor: column.hrefFor} : {}),
      ...(column.actionsFor ? {actionsFor: column.actionsFor, compactActions: false} : {}),
    },
  } : {}),
})))
const identityKey = computed(() => props.columns.find(c => c.kind === 'link')?.key ?? props.columns[0]?.key ?? 'id')
const required = computed(() => props.columns.some(c => c.kind === 'actions') ? [identityKey.value, 'actions'] : [identityKey.value])
</script>
<template>
  <div class="kol-module-global-grid" data-testid="kol-module-global-grid">
    <GlobalGrid
      :namespace="namespace" :title="title" :rows="rows" :columns="definitions"
      :required-column-ids="required" :row-identity-key="rowKey"
      :default-pinned-right="columns.some(c=>c.kind==='actions') ? 1 : 0"
      :page="page" :search="search" :show-search="true"
      :search-placeholder="searchPlaceholder ?? (page ? '搜索数据库记录...' : '搜索已加载记录...')"
      :enable-selection="enableSelection"
      :selected-order-ids="selectedIds" :client-csv-export="false"
      @page-change="emit('page-change',$event)"
      @page-size-change="emit('page-size-change',$event)"
      @search-change="emit('search-change',$event)"
      @selection-change="emit('selection-change',$event)"
    />
    <small v-if="!page" class="kol-grid-loaded-note">筛选当前已载入的 {{ rows.length }} 条记录；实时完整数据以原同步/服务器查询为准。</small>
  </div>
</template>
<style scoped>
.kol-module-global-grid { min-width:0; width:100%; }
.kol-grid-loaded-note { display:block; margin:4px 0 0; font-size:11px; color:#64748b; }
</style>
