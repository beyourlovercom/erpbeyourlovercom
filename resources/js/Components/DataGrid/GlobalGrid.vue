<script setup lang="ts">
import type { ColDef } from 'ag-grid-community'
import OmsGrid from './OmsGrid.vue'

type Row = Record<string, unknown>
type Pager = { current_page: number; last_page: number; per_page: number; total: number }
const props = withDefaults(defineProps<{
  namespace: string
  legacyPreferenceNamespaces?: string[]
  title: string
  columns: ColDef<Row>[]
  rows: Row[]
  requiredColumnIds: string[]
  page?: Pager | null
  rowIdentityKey?: string
  search?: string
  searchPlaceholder?: string
  sort?: { colId: string; direction: 'asc' | 'desc' }
  filterColumns?: string[]
  filterValues?: Record<string, string>
  showSearch?: boolean
  enableSelection?: boolean
  selectedOrderIds?: number[]
  clientCsvExport?: boolean
  defaultPinnedRight?: number
}>(), {
  page: null, rowIdentityKey: 'id', showSearch: true, enableSelection: false, clientCsvExport: false, defaultPinnedRight: 1,
  filterColumns: () => [], filterValues: () => ({}),
})
const emit = defineEmits<{
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'sort-change', sort: { colId: string; direction: 'asc' | 'desc' | null }): void
  (event: 'search-change', value: string): void
  (event: 'column-search-change', filter: { colId: string; value: string }): void
  (event: 'selection-change', rows: Row[]): void
}>()
</script>

<template>
  <div data-testid="erp-global-grid-v1" class="erp-global-grid-v1">
    <OmsGrid
      :namespace="namespace"
      :legacy-preference-namespaces="legacyPreferenceNamespaces"
      :title="title"
      :columns="columns"
      :rows="rows"
      :required-column-ids="requiredColumnIds"
      :server-pagination="props.page ?? undefined"
      :row-identity-key="rowIdentityKey"
      :server-search="search"
      :server-sort="sort"
      :server-filter-columns="filterColumns"
      :server-column-filters="filterValues"
      :show-search="showSearch"
      search-label="表格内搜索全部记录"
      :search-placeholder="searchPlaceholder ?? '搜索全部记录...'"
      :enable-selection="enableSelection"
      :selection-order-key="enableSelection ? rowIdentityKey : undefined"
      :selected-order-ids="selectedOrderIds"
      :allow-all-rows="false"
      :show-client-csv-export="clientCsvExport"
      preference-scope="account"
      :default-pinned-left="1"
      :default-pinned-right="defaultPinnedRight"
      @selection-change="emit('selection-change', $event)"
      @page-change="emit('page-change', $event)"
      @page-size-change="emit('page-size-change', $event)"
      @search-change="emit('search-change', $event)"
      @sort-change="emit('sort-change', $event)"
      @column-search-change="emit('column-search-change', $event)"
    >
      <template #actions><slot name="actions" /></template>
      <template #footer-left="slotProps"><slot name="footer-left" v-bind="slotProps" /></template>
    </OmsGrid>
  </div>
</template>
<style scoped>
.erp-global-grid-v1{width:100%;min-width:0}
:deep(.oms-grid){height:clamp(460px,65vh,800px)}
/* Short server-backed lists override the large-list height. */
:deep(.oms-grid.is-auto-height){height:auto;min-height:0}
/* AG Grid autoHeight keeps a 150px internal scrolling-row minimum even
   when only one record exists. Override it ONLY for short server lists. */
:deep(.oms-grid.is-auto-height .ag-grid-scrolling-rows){min-height:0}
/* Empty results still need room for AG Grid's "暂无数据" overlay. */
:deep(.oms-grid.is-auto-height.is-empty-short-list .ag-grid-scrolling-rows){min-height:72px}
@media(max-width:767px){:deep(.oms-grid){height:clamp(400px,61vh,650px)}}
</style>
