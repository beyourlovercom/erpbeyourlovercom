import { router } from '@inertiajs/vue3'
import type { ColDef } from 'ag-grid-community'

/** Thin reusable route adapter; all business mutations stay in their original screens. */
export type GridListRow = Record<string, unknown>
type QueryValue = string | number | undefined
type Options = {
  path: string
  enabled: () => boolean
  search: () => string
  pageSize: () => number
  context?: () => Record<string, QueryValue>
}
export const gridColumn = <Row extends GridListRow>(definition: ColDef<Row>): ColDef<Row> =>
  ({ sortable: false, filter: false, ...definition })

export function useGlobalGridListAdapter(options: Options) {
  const navigate = (overrides: Record<string, QueryValue> = {}) => {
    const term = options.search().trim()
    router.get(options.path, {
      ...(options.context?.() ?? {}),
      ...(term ? { search: term } : {}),
      ...(options.enabled() ? { global_grid: '1', per_page: options.pageSize() } : {}),
      ...overrides,
    }, { preserveScroll: true, preserveState: true, replace: true })
  }
  return {
    toggle: () => navigate({ global_grid: options.enabled() ? '0' : '1', per_page: 50, page: 1 }),
    search: (value: string) => navigate({ search: value || undefined, page: 1 }),
    page: (value: number) => navigate({ page: value }),
    pageSize: (value: number | 'all') => { if (value !== 'all') navigate({ per_page: value, page: 1 }) },
  }
}
