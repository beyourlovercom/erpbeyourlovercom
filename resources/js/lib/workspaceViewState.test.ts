import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  WorkspaceViewState, WORKSPACE_VIEW_LIMIT, WORKSPACE_VIEW_MAX_AGE_MS,
} from './workspaceViewState'

describe('ERP V4 D5 visual state restore', () => {
  let cache: WorkspaceViewState
  beforeEach(() => {
    cache = new WorkspaceViewState()
    document.body.innerHTML = ''
    vi.stubGlobal('scrollTo', vi.fn())
  })

  it('restores horizontal Grid and vertical list/viewport scroll for same exact URL', () => {
    const grid = document.createElement('div')
    grid.className = 'oms-grid'
    const x = document.createElement('div')
    x.className = 'ag-body-horizontal-scroll-viewport'
    const y = document.createElement('div')
    y.className = 'ag-body-viewport'
    grid.append(x, y)
    document.body.append(grid)
    x.scrollLeft = 430
    y.scrollTop = 210

    const url = '/logistics/china?view=review&search=synthetic&page=3&per_page=50'
    expect(cache.capture(url, 1, document, window, 100)).toBe(true)
    x.scrollLeft = 0
    y.scrollTop = 0
    expect(cache.restore(url, 1, document, window, 200)).toBe(true)
    expect(x.scrollLeft).toBe(430)
    expect(y.scrollTop).toBe(210)
  })

  it('never overwrites another filter, page or user state', () => {
    document.body.innerHTML = '<div class="table-wrap orders"></div>'
    const table = document.querySelector<HTMLElement>('.table-wrap.orders')!
    table.scrollLeft = 97
    const url = '/orders?search=first&page=4'
    expect(cache.capture(url, 1, document, window, 100)).toBe(true)
    table.scrollLeft = 2
    expect(cache.restore('/orders?search=second&page=4', 1, document, window, 200)).toBe(false)
    expect(cache.restore('/orders?search=first&page=5', 1, document, window, 200)).toBe(false)
    expect(cache.restore(url, 2, document, window, 200)).toBe(false)
    expect(table.scrollLeft).toBe(2)
  })

  it('retains no more than 30 small snapshots and expires inactive views', () => {
    for (let i = 0; i < 31; i++) {
      cache.capture('/orders/' + i, 1, document, window, i)
    }
    expect(cache.size).toBe(WORKSPACE_VIEW_LIMIT)
    expect(cache.snapshot('/orders/0', 1, 31)).toBeNull()
    expect(cache.snapshot('/orders/1', 1, 1 + WORKSPACE_VIEW_MAX_AGE_MS + 1)).toBeNull()
    cache.clear()
    expect(cache.size).toBe(0)
  })

  it('discards external URLs and does not persist form values or sensitive data', () => {
    document.body.innerHTML = '<input name="password" value="synthetic-private"><div class="shell-content"></div>'
    expect(cache.capture('//example.invalid/steal', 1, document, window)).toBe(false)
    expect(cache.capture('/login', 1, document, window)).toBe(false)
    cache.capture('/orders', 1, document, window, 100)
    const serialized = JSON.stringify(cache.snapshot('/orders', 1, 101))
    expect(serialized).not.toContain('synthetic-private')
    expect(serialized).not.toContain('password')
  })
})
