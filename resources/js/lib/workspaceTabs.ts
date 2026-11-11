/**
 * ERP V4 R3: bounded *navigation* tabs. This deliberately does not keep
 * AG Grid pages mounted; only the current Inertia page owns heavy components.
 * One browser tab stores its own workspace state via sessionStorage.
 */
export type WorkspaceTab = { key: string; href: string; title: string; lastUsed: number; pinned?: boolean }
export const WORKSPACE_TAB_LIMIT = 30
export const WORKSPACE_PIN_LIMIT = 3

const domesticViews: Record<string, string> = {
  review: '待审核', stockout: '缺货', pending: '待发货',
  'today-4px': '今日速达', 'today-weship': '今日纬狮',
  exceptions: '异常订单', today: '今日订单', '4px': '速达订单',
  weship: '纬狮订单',
}
const forbidden = /[\\\u0000-\u001f]/

// Never restore external links, logout actions, malformed URLs or auth endpoints.
export const safeWorkspaceHref = (raw: string): string | null => {
  if (typeof raw !== 'string' || !raw.startsWith('/') || raw.startsWith('//') || forbidden.test(raw)) return null
  try {
    const url = new URL(raw, 'https://erp-workspace.invalid')
    if (url.origin !== 'https://erp-workspace.invalid') return null
    if (/^\/(?:login|logout|password|sanctum|api)(?:\/|$)/i.test(url.pathname)) return null
    return url.pathname + url.search + url.hash
  } catch { return null }
}

export const workspaceKey = (href: string): string | null => {
  const safe = safeWorkspaceHref(href)
  if (!safe) return null
  const url = new URL(safe, 'https://erp-workspace.invalid')
  const view = url.pathname === '/logistics/china' ? url.searchParams.get('view') : null
  return url.pathname + (view && domesticViews[view] ? '?view=' + encodeURIComponent(view) : '')
}

export const workspaceTitle = (title: string, href: string): string => {
  const safe = safeWorkspaceHref(href)
  const view = safe ? new URL(safe, 'https://erp-workspace.invalid').searchParams.get('view') : null
  const suffix = safe?.startsWith('/logistics/china') && view ? domesticViews[view] : null
  const base = (title || '工作页面').trim().slice(0, 52)
  return suffix ? base + ' · ' + suffix : base
}

export const storageKeyForUser = (userId: number): string => 'byl:workspace:v4:user:' + userId

export function restoreWorkspaceTabs(raw: string | null): WorkspaceTab[] {
  if (!raw) return []
  try {
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    const seen = new Set<string>()
    const tabs: WorkspaceTab[] = []
    let pinnedCount = 0
    for (const item of parsed.slice(-WORKSPACE_TAB_LIMIT * 3)) {
      if (!item || typeof item !== 'object') continue
      const tab = item as Record<string, unknown>
      const href = typeof tab.href === 'string' ? safeWorkspaceHref(tab.href) : null
      const key = href && workspaceKey(href)
      if (!href || !key || seen.has(key)) continue
      const title = typeof tab.title === 'string' ? tab.title.trim().slice(0, 80) : ''
      const lastUsed = typeof tab.lastUsed === 'number' && Number.isFinite(tab.lastUsed) ? tab.lastUsed : 0
      const pinned = tab.pinned === true && pinnedCount < WORKSPACE_PIN_LIMIT
      if (pinned) pinnedCount++
      tabs.push({ key, href, title: title || '工作页面', lastUsed, ...(pinned ? { pinned: true } : {}) })
      seen.add(key)
    }
    return tabs.slice(-WORKSPACE_TAB_LIMIT)
  } catch { return [] }
}

export function visitWorkspaceTab(
  existing: WorkspaceTab[], href: string, title: string, at = Date.now(),
): { tabs: WorkspaceTab[]; evicted: WorkspaceTab | null } {
  const safe = safeWorkspaceHref(href)
  const key = safe && workspaceKey(safe)
  if (!safe || !key) return { tabs: existing, evicted: null }
  const index = existing.findIndex(item => item.key === key)
  if (index >= 0) {
    const next = existing.slice()
    next[index] = { ...next[index], key, href: safe, title: workspaceTitle(title, safe), lastUsed: at }
    return { tabs: next, evicted: null }
  }
  const next: WorkspaceTab[] = [...existing, { key, href: safe, title: workspaceTitle(title, safe), lastUsed: at }]
  if (next.length <= WORKSPACE_TAB_LIMIT) return { tabs: next, evicted: null }
  // Evict only a *background navigation bookmark*. No hidden page is mounted,
  // and no background form content is destroyed by this data-structure operation.
  const evictable = existing.filter(tab => !tab.pinned)
  if (!evictable.length) return { tabs: existing, evicted: null }
  const victim = evictable.reduce((oldest, tab) => tab.lastUsed < oldest.lastUsed ? tab : oldest, evictable[0])
  return { tabs: next.filter(tab => tab.key !== victim.key), evicted: victim }
}

export function closeWorkspaceTab(
  existing: WorkspaceTab[], closingKey: string, activeKey: string | null,
): { tabs: WorkspaceTab[]; nextHref: string | null } {
  // Pinned tabs are locked against accidental close; unpin first.
  if (existing.some(tab => tab.key === closingKey && tab.pinned)) return { tabs: existing, nextHref: null }
  const tabs = existing.filter(tab => tab.key !== closingKey)
  if (closingKey !== activeKey) return { tabs, nextHref: null }
  const next = tabs.reduce<WorkspaceTab | null>(
    (mostRecent, tab) => !mostRecent || tab.lastUsed > mostRecent.lastUsed ? tab : mostRecent, null,
  )
  return { tabs, nextHref: next?.href ?? '/dashboard' }
}

/** Explicit user pinning, at most three. Pinning never forces a page to stay in RAM. */
export function toggleWorkspacePin(
  tabs: WorkspaceTab[], key: string,
): { tabs: WorkspaceTab[]; blocked: boolean } {
  const selected = tabs.find(tab => tab.key === key)
  if (!selected) return { tabs, blocked: true }
  if (!selected.pinned && tabs.filter(tab => tab.pinned).length >= WORKSPACE_PIN_LIMIT) {
    return { tabs, blocked: true }
  }
  return {
    tabs: tabs.map(tab => tab.key === key ? { ...tab, pinned: !tab.pinned } : tab),
    blocked: false,
  }
}
