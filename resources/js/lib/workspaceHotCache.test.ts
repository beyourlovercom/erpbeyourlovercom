import { describe, expect, it } from 'vitest'
import type { Page } from '@inertiajs/core'
import {
  WorkspaceHotCache, WORKSPACE_HOT_LIMIT, WORKSPACE_HOT_MAX_AGE_MS,
  WORKSPACE_HOT_MAX_BYTES,
} from './workspaceHotCache'

function page(path: string, id = 1, version = 'r3d-v1', component = 'Orders/Index', blob = ''): Page {
  return {
    url: path, component, version, props: {
      auth: { user: { id } }, errors: {}, rows: { data: [{ reference: 'synthetic-only', text: blob }] },
    },
    flash: { messages: [] }, rescuedProps: [], rememberedState: {},
  } as unknown as Page
}

describe('R3-D workspace hot response snapshots (in-memory only)', () => {
  it('retains at most three entries, evicts least-recently-used one', () => {
    const cache = new WorkspaceHotCache()
    for (let i = 1; i <= WORKSPACE_HOT_LIMIT; i++) {
      expect(cache.capture(page('/orders?page=' + i), 1, i)).toBe(true)
    }
    // /orders maps to the same tab key; use distinct paths to simulate pages.
    expect(cache.size).toBe(1)
    cache.capture(page('/orders'), 1, 100)
    cache.capture(page('/dashboard', 1, 'r3d-v1', 'Dashboard/Index'), 1, 200)
    cache.capture(page('/products', 1, 'r3d-v1', 'Products/Index'), 1, 300)
    expect(cache.size).toBe(3)
    cache.get('/dashboard', 1, 'r3d-v1', 350)
    cache.capture(page('/logistics/china?view=review', 1, 'r3d-v1', 'Logistics/Domestic/Index'), 1, 360)
    expect(cache.size).toBe(3)
    expect(cache.listKeys()).toContain('/dashboard')
    expect(cache.listKeys()).not.toContain('/orders')
  })

  it('rejects stale snapshots, wrong user, wrong asset version and changed filters', () => {
    const cache = new WorkspaceHotCache()
    cache.capture(page('/orders?status=processing'), 1, 100)
    expect(cache.get('/orders?status=processing', 2, 'r3d-v1', 101)).toBeNull()
    cache.capture(page('/orders?status=processing'), 1, 100)
    expect(cache.get('/orders?status=processing', 1, 'r3d-v2', 101)).toBeNull()
    cache.capture(page('/orders?status=processing'), 1, 100)
    expect(cache.get('/orders?status=completed', 1, 'r3d-v1', 101)).toBeNull()
    cache.capture(page('/orders?status=processing'), 1, 100)
    expect(cache.get('/orders?status=processing', 1, 'r3d-v1', 100 + WORKSPACE_HOT_MAX_AGE_MS + 1)).toBeNull()
  })

  it('rejects oversize pages, credentials with another user and sensitive nonallowlisted screens', () => {
    const cache = new WorkspaceHotCache()
    expect(cache.capture(page('/orders', 3), 4)).toBe(false)
    expect(cache.capture(page('/orders', 3, 'r3d-v1', 'Orders/Show'), 3)).toBe(false)
    expect(cache.capture(page('/orders', 3, 'r3d-v1', 'Orders/Index', 'A'.repeat(WORKSPACE_HOT_MAX_BYTES)), 3)).toBe(false)
    expect(cache.size).toBe(0)
  })

  it('copies page props instead of retaining mutable Inertia proxies and can sleep others', () => {
    const cache = new WorkspaceHotCache()
    const source = page('/orders')
    cache.capture(source, 1, 100)
    const result = cache.get('/orders', 1, 'r3d-v1', 101)
    expect(result).not.toBeNull()
    expect(result!.props).not.toBe(source.props)
    cache.capture(page('/dashboard', 1, 'r3d-v1', 'Dashboard/Index'), 1, 110)
    cache.sleepOthers('/dashboard')
    expect(cache.size).toBe(1)
    cache.clear()
    expect(cache.size).toBe(0)
  })

  it('does not let client-side cached navigation refresh stale cache freshness', () => {
    const cache = new WorkspaceHotCache()
    cache.capture(page('/orders'), 1, 100)
    cache.markClientRestore('/orders')
    expect(cache.capture(page('/orders'), 1, 200)).toBe(false)
    expect(cache.get('/orders', 1, 'r3d-v1', 201)?.capturedAt).toBe(100)
  })
})
