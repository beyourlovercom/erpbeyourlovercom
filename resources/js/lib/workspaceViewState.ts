import { safeWorkspaceHref, workspaceKey } from './workspaceTabs'

/**
 * D5 restores *view state*, not confidential form content.
 *
 * Filters, sorting and page number are already encoded by existing ERP
 * server-side grid navigation URLs; keep those URLs unchanged in each tab.
 * This stores a small, transient visual scroll snapshot per user/route.
 * No external persistence and no additional AG Grid component instances.
 */
export const WORKSPACE_VIEW_LIMIT = 30
export const WORKSPACE_VIEW_MAX_AGE_MS = 30 * 60 * 1000

const SCROLL_SELECTORS = [
  '.shell-content',
  '.oms-grid .ag-body-horizontal-scroll-viewport',
  '.oms-grid .ag-center-cols-viewport',
  '.oms-grid .ag-body-viewport',
  '.table-wrap.orders',
] as const

type Position = { left: number; top: number }
export type WorkspaceViewSnapshot = {
  key: string
  href: string
  userId: number
  capturedAt: number
  windowX: number
  windowY: number
  scrolls: Record<string, Position>
}

const bounded = (value: number): number =>
  Number.isFinite(value) ? Math.max(0, Math.min(2_000_000, Math.round(value))) : 0

export class WorkspaceViewState {
  private snapshots = new Map<string, WorkspaceViewSnapshot>()

  get size(): number { return this.snapshots.size }

  capture(href: string, userId: number, doc: Document, win: Window, at = Date.now()): boolean {
    const safe = safeWorkspaceHref(href)
    const key = safe && workspaceKey(safe)
    if (!safe || !key || !Number.isSafeInteger(userId) || userId < 1) return false

    const scrolls: Record<string, Position> = {}
    for (const selector of SCROLL_SELECTORS) {
      const element = doc.querySelector<HTMLElement>(selector)
      if (!element) continue
      scrolls[selector] = { left: bounded(element.scrollLeft), top: bounded(element.scrollTop) }
    }
    const value: WorkspaceViewSnapshot = {
      key, href: safe, userId, capturedAt: at,
      windowX: bounded(win.scrollX), windowY: bounded(win.scrollY),
      scrolls,
    }
    this.snapshots.delete(key)
    this.snapshots.set(key, value)
    while (this.snapshots.size > WORKSPACE_VIEW_LIMIT) {
      const first = this.snapshots.keys().next().value
      if (first) this.snapshots.delete(first)
      else break
    }
    return true
  }

  snapshot(href: string, userId: number, now = Date.now()): WorkspaceViewSnapshot | null {
    const key = workspaceKey(href)
    const value = key ? this.snapshots.get(key) : undefined
    if (!key || !value) return null
    if (value.userId !== userId || value.href !== href ||
        now - value.capturedAt > WORKSPACE_VIEW_MAX_AGE_MS) {
      this.snapshots.delete(key)
      return null
    }
    return value
  }

  /**
   * Called only after Inertia finished navigation and a page mounted.
   * Reapply both axes to grid scrollports without changing AG Grid filters,
   * selections, permissions or backend order state.
   */
  restore(href: string, userId: number, doc: Document, win: Window, now = Date.now()): boolean {
    const value = this.snapshot(href, userId, now)
    if (!value) return false
    for (const [selector, position] of Object.entries(value.scrolls)) {
      const element = doc.querySelector<HTMLElement>(selector)
      if (!element) continue
      element.scrollLeft = position.left
      element.scrollTop = position.top
    }
    win.scrollTo(value.windowX, value.windowY)
    return true
  }

  evict(key: string): void { this.snapshots.delete(key) }
  clear(): void { this.snapshots.clear() }
}

export const workspaceViewState = new WorkspaceViewState()
