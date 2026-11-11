import type { Page } from '@inertiajs/core'
import { ref } from 'vue'
import { safeWorkspaceHref, workspaceKey } from './workspaceTabs'

/**
 * The three warm entries are in-memory response snapshots, NOT three mounted
 * AG Grids. Re-entering a warm tab uses an Inertia client-side visit and
 * performs bounded revalidation before any ERP action can be taken.
 * No cache or user data is written to localStorage/sessionStorage.
 */
export const WORKSPACE_HOT_LIMIT = 3
export const WORKSPACE_HOT_MAX_BYTES = 1_500_000
export const WORKSPACE_HOT_MAX_AGE_MS = 60_000
export const WORKSPACE_HOT_FRESH_MS = 5_000

const ALLOWED_COMPONENTS = new Set([
  'Dashboard/Index',
  'Orders/Index',
  'Logistics/Domestic/Index',
  'Products/Index',
])

export type HotWorkspacePage = {
  key: string
  href: string
  component: string
  props: Page['props']
  version: string | null
  userId: number
  capturedAt: number
  lastUsed: number
  bytes: number
}

function snapshot(page: Page, userId: number, now: number): HotWorkspacePage | null {
  const href = safeWorkspaceHref(page.url)
  const key = href && workspaceKey(href)
  if (!href || !key || !ALLOWED_COMPONENTS.has(page.component)) return null
  const sourceUser = (page.props.auth as { user?: { id?: number } } | undefined)?.user?.id
  if (sourceUser !== userId) return null
  // Inertia page props are server JSON; serialize once to detach reactive
  // references and enforce an actual byte-count budget (UTF-16 overhead is
  // separately checked in browser memory UAT).
  let encoded: string
  try {
    encoded = JSON.stringify(page.props)
    const bytes = new TextEncoder().encode(encoded).byteLength
    if (bytes > WORKSPACE_HOT_MAX_BYTES) return null
    return {
      key, href, component: page.component, props: JSON.parse(encoded),
      version: page.version, userId, capturedAt: now, lastUsed: now,
      bytes,
    }
  } catch { return null }
}

export class WorkspaceHotCache {
  private pages = new Map<string, HotWorkspacePage>()
  private restoringKey: string | null = null

  markClientRestore(key: string): void { this.restoringKey = key }

  get size(): number { return this.pages.size }
  listKeys(): string[] { return [...this.pages.keys()] }

  capture(page: Page, userId: number, now = Date.now()): boolean {
    const item = snapshot(page, userId, now)
    if (!item) return false
    if (this.restoringKey === item.key) { this.restoringKey = null; return false }
    this.pages.delete(item.key)
    this.pages.set(item.key, item)
    while (this.pages.size > WORKSPACE_HOT_LIMIT) {
      const oldest = this.pages.keys().next().value
      if (!oldest) break
      this.pages.delete(oldest)
    }
    return true
  }

  get(href: string, userId: number, version: string | null, now = Date.now()): HotWorkspacePage | null {
    const key = workspaceKey(href)
    const value = key && this.pages.get(key)
    if (!key || !value) return null
    if (value.userId !== userId || value.version !== version ||
        value.href !== href || now - value.capturedAt > WORKSPACE_HOT_MAX_AGE_MS) {
      this.pages.delete(key)
      return null
    }
    value.lastUsed = now
    this.pages.delete(key)
    this.pages.set(key, value)
    return value
  }

  evict(key: string): void { this.pages.delete(key) }
  sleepOthers(currentKey: string | null): void {
    for (const key of this.pages.keys()) if (key !== currentKey) this.pages.delete(key)
  }
  clear(): void { this.pages.clear(); this.restoringKey = null }
}

// Module singleton survives AuthenticatedLayout remounts during Inertia visits.
export const workspaceHotCache = new WorkspaceHotCache()

/** Shared across remounted AuthenticatedLayout instances for the active SPA session. */
export const workspaceHotStatus = ref<'idle' | 'validating' | 'failed'>('idle')
