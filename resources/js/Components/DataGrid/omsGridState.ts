import type { ColumnState } from 'ag-grid-community'

export type OmsDensity = 'compact' | 'standard'
export type OmsGridPreference = {
  version: 1
  columns: ColumnState[]
  pinnedLeft: number
  pinnedRight: number
  density: OmsDensity
  pageSize: number | 'all'
}

const count = (n: unknown): number => Number.isFinite(Number(n)) ? Math.max(0, Math.floor(Number(n))) : 0

export function clampPinnedCounts(total: number, requestedLeft: number, requestedRight: number) {
  const size = Math.max(0, Math.floor(total))
  // Always preserve at least one unpinned data column when possible.
  const maxPinned = Math.max(0, size - 1)
  const left = Math.min(count(requestedLeft), maxPinned)
  const right = Math.min(count(requestedRight), maxPinned - left)
  return { left, right }
}

export function pinnedStateForVisibleColumns(
  columns: Pick<ColumnState, 'colId' | 'hide'>[],
  left: number,
  right: number,
  locked: { left?: string[]; right?: string[] } = {},
): Array<{ colId: string; pinned: 'left' | 'right' | null }> {
  const visible = columns.filter(column => !column.hide).map(column => column.colId)
  const visibleSet = new Set(visible)
  const fixedLeft = new Set((locked.left ?? []).filter(id => visibleSet.has(id)))
  const fixedRight = new Set((locked.right ?? []).filter(id => visibleSet.has(id) && !fixedLeft.has(id)))
  const counts = clampPinnedCounts(visible.length,
    Math.max(left, fixedLeft.size),Math.max(right,fixedRight.size))
  const free = visible.filter(id => !fixedLeft.has(id) && !fixedRight.has(id))
  const leftIds = new Set([...fixedLeft,...free.slice(0,Math.max(0,counts.left-fixedLeft.size))])
  const available = free.filter(id => !leftIds.has(id))
  const rightIds = new Set([...fixedRight,
    ...available.slice(Math.max(0,available.length-Math.max(0,counts.right-fixedRight.size)))])
  return columns.map(column => ({
    colId: column.colId,
    pinned: leftIds.has(column.colId) ? 'left' : rightIds.has(column.colId) ? 'right' : null,
  }))
}

export function sanitizeOmsGridPreference(
  raw: unknown,
  allowedIds: string[],
): OmsGridPreference | null {
  if (!raw || typeof raw !== 'object') return null
  const value = raw as Partial<OmsGridPreference>
  if (!Array.isArray(value.columns)) return null
  const valid = new Set(allowedIds)
  const used = new Set<string>()
  const columns: ColumnState[] = []
  for (const input of value.columns) {
    if (!input || typeof input !== 'object' || typeof input.colId !== 'string') continue
    if (!valid.has(input.colId) || used.has(input.colId)) continue
    used.add(input.colId)
    columns.push({
      colId: input.colId,
      hide: input.hide === true,
      width: typeof input.width === 'number' && Number.isFinite(input.width) ? Math.max(65, Math.min(800, input.width)) : undefined,
      sort: input.sort === 'asc' || input.sort === 'desc' ? input.sort : null,
    })
  }
  if (!columns.length) return null
  const pageSize = value.pageSize === 'all' ? 'all' : [20, 50, 100].includes(Number(value.pageSize)) ? Number(value.pageSize) : 50
  return {
    version: 1,
    columns,
    pinnedLeft: count(value.pinnedLeft),
    pinnedRight: count(value.pinnedRight),
    density: value.density === 'standard' ? 'standard' : 'compact',
    pageSize,
  }
}
