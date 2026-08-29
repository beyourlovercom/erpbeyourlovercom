import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(() => Promise.resolve({ data: {} })) }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('axios', () => ({ default: { put } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

beforeEach(() => {
  get.mockReset()
  put.mockClear()
  const store = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => store.set(key, value),
    removeItem: (key: string) => store.delete(key),
  })
})

const baseStats = {
  total_customers: 5,
  new_this_month: 1,
  orders_this_month: 2,
  revenue_this_month: [{ currency: 'USD', revenue: 100 }],
  rmb_estimate: {
    available: true as const,
    amount_cny: 710,
    source: 'ECB',
    reference_date: '2026-08-28',
    stale: false,
    conversions: [{ original_currency: 'USD', original_amount: 100, rate_to_cny: 7.1, cny_amount: 710 }],
  },
  subscriber_count_available: false,
  open_support_tickets_available: false,
}

const baseProps = {
  customers: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null, links: [] },
  filters: {},
  sources: {},
  stats: baseStats,
  canOperate: false,
  canImport: false,
  canExport: false,
  workspace: 'library' as const,
  gridPreferenceNamespace: 'grid.customers.library.v1',
  gridPreference: null,
}

const groupProps = {
  ...baseProps,
  workspace: 'groups' as const,
  gridPreferenceNamespace: 'grid.customers.groups.v1',
}

describe('Customer Library KPI Strip — compact', () => {
  it('kpi-strip renders exactly six cards in non-compact mode', () => {
    const wrapper = mount(Index, { props: baseProps })
    const strip = wrapper.find('[data-testid="kpi-strip"]')
    expect(strip.exists()).toBe(true)
    expect(strip.findAll('article').length).toBe(6)
  })

  it('each KPI card has at most two visible text elements (label + value)', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    for (const card of cards) {
      // Only p elements are visible text rows per spec; there must be ≤2
      const textRows = card.findAll('p')
      expect(textRows.length).toBeLessThanOrEqual(2)
    }
  })

  it('does not render visible kpi-currency-tag elements', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.kpi-currency-tag').length).toBe(0)
  })

  it('does not render visible kpi-note elements', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.kpi-note').length).toBe(0)
  })

  it('monthly sales card shows CNY value when rmb_estimate is available', () => {
    const wrapper = mount(Index, { props: baseProps })
    const strip = wrapper.find('[data-testid="kpi-strip"]')
    // The 4th card (index 3) is 本月销售额
    const salesCard = strip.findAll('article')[3]
    expect(salesCard.text()).toContain('本月销售额')
    // Should show a CNY formatted number, not USD breakdown
    expect(salesCard.text()).not.toContain('USD')
    expect(salesCard.text()).not.toContain('$')
  })

  it('monthly sales card shows em-dash when rmb_estimate is unavailable', () => {
    const props = {
      ...baseProps,
      stats: {
        ...baseStats,
        rmb_estimate: { available: false as const, reason: 'fx_unavailable' },
      },
    }
    const wrapper = mount(Index, { props })
    const salesCard = wrapper.findAll('[data-testid="kpi-strip"] article')[3]
    expect(salesCard.text()).toContain('—')
  })

  it('subscriber and open ticket cards show em-dash only (no explanatory text visible)', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    const subscriberCard = cards[4]
    const ticketCard = cards[5]

    expect(subscriberCard.text()).toContain('订阅者数')
    expect(subscriberCard.text()).toContain('—')
    expect(subscriberCard.text()).not.toContain('M1.3')

    expect(ticketCard.text()).toContain('待处理工单')
    expect(ticketCard.text()).toContain('—')
    expect(ticketCard.text()).not.toContain('M1.4')
  })

  it('subscriber and ticket cards carry explanation in title attribute', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    expect(cards[4].attributes('title')).toContain('M1.3')
    expect(cards[5].attributes('title')).toContain('M1.4')
  })

  it('customer groups workspace exposes shortcuts and account-level column settings', async () => {
    const wrapper = mount(Index, { props: { ...groupProps, canOperate: true, filters: { group: 'repeat_customer' } } })
    const labels = wrapper.findAll('.customer-group-shortcut').map(link => link.text())
    expect(labels).toEqual(['全部客户', '无购买客户', '新客户', '复购客户', '单次多产品客户'])
    expect(wrapper.find('.customer-group-shortcut-active').text()).toBe('复购客户')
    expect(wrapper.find('#filter-group').exists()).toBe(false)
    expect(wrapper.find('a[href*="group=repeat_customer"]').attributes('href')).toContain('/customers/groups?')
    await wrapper.get('.column-settings-trigger').trigger('click')
    expect(wrapper.get('.column-settings-panel').text()).toContain('设置绑定当前账号并跨设备同步')
  })

  it('customer library keeps the group filter and does not expose column settings', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.customer-group-shortcut').length).toBe(0)
    expect(wrapper.find('#filter-group').exists()).toBe(true)
    expect(wrapper.find('.column-settings-trigger').exists()).toBe(false)
  })

  it('server preference wins over conflicting local cache', () => {
    localStorage.setItem('ui-pref-cache:grid.customers.groups.v1', JSON.stringify({ columns: [
      { key: 'email', visible: true }, { key: 'phone', visible: false },
    ] }))
    const wrapper = mount(Index, { props: { ...groupProps, gridPreference: { columns: [
      { key: 'phone', visible: true }, { key: 'email', visible: false },
    ] } } })
    const headers = wrapper.findAll('thead th').map(node => node.text())
    expect(headers[1]).toBe('电话')
    expect(headers).not.toContain('Email')
  })

  it('column visibility change writes account preference and local cache', async () => {
    const wrapper = mount(Index, { props: groupProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('input[aria-label="显示城市"]').trigger('change')
    await flushPromises()
    expect(wrapper.get('thead').text()).not.toContain('城市')
    expect(put).toHaveBeenCalledWith('/ui/preferences/grid.customers.groups.v1', expect.objectContaining({ value: expect.any(Object) }))
    expect(localStorage.getItem('ui-pref-cache:grid.customers.groups.v1')).toContain('city')
  })

  it('column drag order is cached and synced to the account', async () => {
    const wrapper = mount(Index, { props: groupProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('[data-column-key="phone"]').trigger('dragstart')
    await wrapper.get('[data-column-key="email"]').trigger('drop')
    await flushPromises()
    expect(wrapper.findAll('thead th').slice(0, 3).map(node => node.text())).toEqual(['客户编号 / 姓名', '电话', 'Email'])
    expect(put).toHaveBeenCalled()
  })

  it('legacy browser preference migrates to the account when server preference is empty', async () => {
    localStorage.setItem('customer_groups_grid_columns_v1', JSON.stringify([
      { key: 'phone', visible: true }, { key: 'email', visible: false },
    ]))
    mount(Index, { props: groupProps })
    await flushPromises()
    expect(put).toHaveBeenCalledWith('/ui/preferences/grid.customers.groups.v1', expect.any(Object))
  })

  it('kpi-strip is not rendered in compact mode', () => {
    // Simulate compact mode via localStorage
    localStorage.setItem('customer_list_compact_mode', '1')
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.find('[data-testid="kpi-strip"]').exists()).toBe(false)
    localStorage.removeItem('customer_list_compact_mode')
  })
})
