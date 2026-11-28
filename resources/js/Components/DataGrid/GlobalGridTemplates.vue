<script setup lang="ts">
import { computed, useSlots } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGrid from './GlobalGrid.vue'
import GridTemplateCell, { type TemplateRow } from './GridTemplateCell'
import type { FieldGroups } from './gridFieldCatalog'

defineOptions({ inheritAttrs: false })
const props = defineProps<{
  namespace: string
  title: string
  columns: ColDef<TemplateRow>[]
  rows: TemplateRow[]
  requiredColumnIds: string[]
  fieldGroups?: FieldGroups
}>()
const slots = useSlots()
// Explicit column IDs remain the sole field catalog. A named cell slot can
// only render a column already present in the domain-authorized definitions.
const resolvedColumns = computed(() => props.columns.map(col => {
  const name = 'cell-' + (col.colId ?? col.field ?? '')
  if (!slots[name]) return col
  return {
    ...col,
    cellRenderer: GridTemplateCell,
    cellRendererParams: {
      ...col.cellRendererParams,
      renderCell: (row: TemplateRow) => slots[name]?.({ row }),
      suppressMouseEventHandling: () => true,
    },
    suppressKeyboardEvent: (p: Parameters<NonNullable<ColDef<TemplateRow>['suppressKeyboardEvent']>>[0]) =>
      (p.event.target instanceof Element && !!p.event.target.closest('input,textarea,select,button,a,[contenteditable],[role="button"]'))
      || (col.suppressKeyboardEvent?.(p) ?? false),
  }
}))
</script>

<template>
  <GlobalGrid v-bind="$attrs" :namespace="namespace" :title="title" :columns="resolvedColumns"
    :rows="rows" :required-column-ids="requiredColumnIds" :field-groups="fieldGroups" :column-settings-version="2">
    <template #actions><slot name="actions" /></template>
    <template #footer-left="p"><slot name="footer-left" v-bind="p" /></template>
  </GlobalGrid>
</template>

<style>
.erp-grid-template-cell{height:100%;min-width:0;width:100%;display:flex;align-items:center;gap:4px;line-height:1.2}
.erp-grid-template-cell>div{min-width:0;max-width:100%}
.erp-grid-template-cell input:not([type=checkbox]){max-width:100%}
</style>
