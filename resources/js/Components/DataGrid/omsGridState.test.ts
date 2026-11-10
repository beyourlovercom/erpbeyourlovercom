import { describe, expect, it } from 'vitest'
import { clampPinnedCounts, pinnedStateForVisibleColumns, sanitizeOmsGridPreference } from './omsGridState'

describe('OMS Grid preference normalization', () => {
  it('reserves a scrollable middle column and clamps invalid pin counts', () => {
    expect(clampPinnedCounts(6, 2, 1)).toEqual({ left: 2, right: 1 })
    expect(clampPinnedCounts(3, 9, 9)).toEqual({ left: 2, right: 0 })
    expect(clampPinnedCounts(1, 1, 1)).toEqual({ left: 0, right: 0 })
    expect(clampPinnedCounts(6, -2, Number.NaN)).toEqual({ left: 0, right: 0 })
  })
  it('pins only visible columns, leaving hidden columns unpinned', () => {
    const state = [
      { colId: 'orderNumber', hide: false },
      { colId: 'hidden', hide: true },
      { colId: 'sku', hide: false },
      { colId: 'warehouse', hide: false },
      { colId: 'actions', hide: false },
    ]
    expect(pinnedStateForVisibleColumns(state, 2, 1)).toEqual([
      { colId: 'orderNumber', pinned: 'left' },
      { colId: 'hidden', pinned: null },
      { colId: 'sku', pinned: 'left' },
      { colId: 'warehouse', pinned: null },
      { colId: 'actions', pinned: 'right' },
    ])
  })
  it('always keeps an explicitly locked right operation column pinned despite a saved middle order', () => {
    const state=[
      {colId:'order',hide:false},
      {colId:'actions',hide:false},
      {colId:'quantity',hide:false},
      {colId:'sku',hide:false},
    ]
    expect(pinnedStateForVisibleColumns(state,1,1,{right:['actions']})).toEqual([
      {colId:'order',pinned:'left'},
      {colId:'actions',pinned:'right'},
      {colId:'quantity',pinned:null},
      {colId:'sku',pinned:null},
    ])
  })
  it('rejects unknown columns and extreme or invalid persisted values', () => {
    const value = sanitizeOmsGridPreference({
      columns: [
        { colId: 'orderNumber', width: 99999, sort: 'bad', hide: false },
        { colId: 'admin', hide: false },
        { colId: 'orderNumber', hide: true },
        { colId: 'actions', width: 12, sort: 'asc' },
      ],
      pinnedLeft: 1, pinnedRight: 1, density: 'standard', pageSize: 250,
    }, ['orderNumber', 'actions'])
    expect(value?.columns).toHaveLength(2)
    expect(value?.columns[0]).toMatchObject({ colId: 'orderNumber', width: 800, sort: null })
    expect(value?.columns[1]).toMatchObject({ colId: 'actions', width: 65, sort: 'asc' })
    expect(value?.pageSize).toBe(50)
    expect(value?.density).toBe('standard')
    expect(sanitizeOmsGridPreference({ columns: [] }, ['orderNumber'])).toBeNull()
  })
  it('round-trips the all-rows pagination preference without treating it as NaN', () => {
    const saved = sanitizeOmsGridPreference({
      columns: [{ colId: 'orderNumber', hide: false }],
      pinnedLeft: 0, pinnedRight: 0, density: 'compact', pageSize: 'all',
    }, ['orderNumber'])
    expect(saved?.pageSize).toBe('all')
    expect(sanitizeOmsGridPreference(saved, ['orderNumber'])?.pageSize).toBe('all')
  })
})
