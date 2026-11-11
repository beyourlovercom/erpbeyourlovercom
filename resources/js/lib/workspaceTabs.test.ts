import { describe, expect, it } from 'vitest'
import {
  closeWorkspaceTab, restoreWorkspaceTabs, safeWorkspaceHref, storageKeyForUser,
  visitWorkspaceTab, workspaceKey, workspaceTitle, WORKSPACE_TAB_LIMIT,
} from './workspaceTabs'

describe('ERP V4 bounded workspace tabs', () => {
  it('only accepts relative same-origin ERP paths and keeps user data isolated', () => {
    expect(safeWorkspaceHref('/orders/42?view=review')).toBe('/orders/42?view=review')
    expect(safeWorkspaceHref('//evil.example/')).toBeNull()
    expect(safeWorkspaceHref('https://evil.example/')).toBeNull()
    expect(safeWorkspaceHref('/logout')).toBeNull()
    expect(safeWorkspaceHref('/login')).toBeNull()
    expect(safeWorkspaceHref('/api/orders')).toBeNull()
    expect(safeWorkspaceHref('/orders\\evil')).toBeNull()
    expect(storageKeyForUser(42)).not.toBe(storageKeyForUser(43))
  })

  it('treats domestic view variants as distinct tabs without treating filters as a new tab', () => {
    expect(workspaceKey('/logistics/china?view=review')).toBe('/logistics/china?view=review')
    expect(workspaceKey('/logistics/china?view=stockout')).toBe('/logistics/china?view=stockout')
    const original = visitWorkspaceTab([], '/logistics/china?view=review&search=one', '国内发货', 100)
    const next = visitWorkspaceTab(original.tabs, '/logistics/china?view=review&search=two', '国内发货', 200)
    expect(next.tabs).toHaveLength(1)
    expect(next.tabs[0].href).toContain('search=two')
    expect(next.tabs[0].title).toContain('待审核')
    expect(workspaceTitle('国内发货', '/logistics/china?view=stockout')).toContain('缺货')
  })

  it('limits history to 30 lightweight entries, evicting only least-used background bookmark', () => {
    let tabs = [] as ReturnType<typeof restoreWorkspaceTabs>
    for (let i = 1; i <= WORKSPACE_TAB_LIMIT; i++) {
      tabs = visitWorkspaceTab(tabs, '/orders/' + i, '订单 ' + i, i).tabs
    }
    expect(tabs).toHaveLength(30)
    tabs = visitWorkspaceTab(tabs, '/orders/1', '订单 1', 100).tabs
    const next = visitWorkspaceTab(tabs, '/orders/31', '订单 31', 101)
    expect(next.tabs).toHaveLength(30)
    expect(next.evicted?.href).toBe('/orders/2')
    expect(next.tabs.some(tab => tab.href === '/orders/1')).toBe(true)
    expect(next.tabs.some(tab => tab.href === '/orders/31')).toBe(true)
  })

  it('restores valid tabs from session storage and refuses malformed, duplicate or foreign entries', () => {
    const raw = JSON.stringify([
      { key: '/dashboard', href: '/dashboard', title: '控制台', lastUsed: 1 },
      { key: 'evil', href: '//attacker.example', title: '可疑', lastUsed: 2 },
      { key: 'duplicate', href: '/dashboard', title: '重复', lastUsed: 3 },
      { key: 'logout', href: '/logout', title: '登出', lastUsed: 4 },
    ])
    expect(restoreWorkspaceTabs(raw)).toEqual([{ key: '/dashboard', href: '/dashboard', title: '控制台', lastUsed: 1 }])
    expect(restoreWorkspaceTabs('{bad json')).toEqual([])
  })

  it('closing active tab switches to latest recent tab, never to a forbidden origin', () => {
    let tabs = visitWorkspaceTab([], '/dashboard', '总览', 10).tabs
    tabs = visitWorkspaceTab(tabs, '/orders', '订单', 20).tabs
    const result = closeWorkspaceTab(tabs, '/orders', '/orders')
    expect(result.nextHref).toBe('/dashboard')
    expect(result.tabs).toHaveLength(1)
    expect(closeWorkspaceTab(result.tabs, '/dashboard', '/dashboard').nextHref).toBe('/dashboard')
    expect(closeWorkspaceTab(tabs, '/dashboard', '/orders').nextHref).toBeNull()
  })
})
