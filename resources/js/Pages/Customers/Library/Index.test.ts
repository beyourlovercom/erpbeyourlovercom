import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

beforeEach(() => {
  get.mockReset()
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

  it('customer list exposes the four system segments as header shortcuts and removes the duplicate group select', () => {
    const wrapper = mount(Index, { props: { ...baseProps, canOperate: true, filters: { group: 'repeat_customer' } } })
    const actions = wrapper.find('.page-header-actions')
    const labels = actions.findAll('.customer-group-shortcut').map(link => link.text())
    expect(labels).toEqual(['全部客户', '无购买客户', '新客户', '复购客户', '单次多产品客户'])
    expect(actions.find('.customer-group-shortcut-active').text()).toBe('复购客户')
    expect(actions.find('a[href="/customers/library/create"]').text()).toBe('新建客户')
    expect(wrapper.find('#filter-group').exists()).toBe(false)
    expect(actions.find('a[href*="group=repeat_customer"]').attributes('href')).toContain('/customers/library?')
    expect(wrapper.find('.column-settings-trigger').exists()).toBe(true)
  })

  it('customer list grid exposes configurable real-data columns behind the top-right ellipsis', async () => {
    const wrapper = mount(Index, { props: baseProps })
    const trigger = wrapper.get('.column-settings-trigger')
    expect(trigger.text()).toBe('…')
    await trigger.trigger('click')
    const panel = wrapper.get('.column-settings-panel')
    for (const label of ['Email', '电话', '注册日期', '国家 / 地区', '城市', '州 / 省', '邮编', '来源', '订单数', '累计销售额', 'AOV', '最近下单', '负责人', '状态']) {
      expect(panel.text()).toContain(label)
    }
    expect(panel.text()).toContain('客户编号 / 姓名')
    expect(panel.text()).toContain('固定')
    expect(panel.text()).toContain('用户名、网站最近活跃')
  })

  it('customer list grid can hide a column and persists that choice', async () => {
    const wrapper = mount(Index, { props: baseProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    const cityToggle = wrapper.get('input[aria-label="显示城市"]')
    await cityToggle.trigger('change')
    expect(wrapper.get('thead').text()).not.toContain('城市')
    wrapper.unmount()
    const remounted = mount(Index, { props: baseProps })
    expect(remounted.get('thead').text()).not.toContain('城市')
  })

  it('customer list grid drag order persists and changes table column order', async () => {
    const wrapper = mount(Index, { props: baseProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    const emailRow = wrapper.get('[data-column-key="email"]')
    const phoneRow = wrapper.get('[data-column-key="phone"]')
    await phoneRow.trigger('dragstart')
    await emailRow.trigger('drop')
    const headers = wrapper.findAll('thead th').map(node => node.text())
    expect(headers.slice(0, 3)).toEqual(['客户编号 / 姓名', '电话', 'Email'])
    wrapper.unmount()
    const remounted = mount(Index, { props: baseProps })
    const restoredHeaders = remounted.findAll('thead th').map(node => node.text())
    expect(restoredHeaders.slice(0, 3)).toEqual(['客户编号 / 姓名', '电话', 'Email'])
  })


  it('kpi-strip is not rendered in compact mode', () => {
    // Simulate compact mode via localStorage
    localStorage.setItem('customer_list_compact_mode', '1')
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.find('[data-testid="kpi-strip"]').exists()).toBe(false)
    localStorage.removeItem('customer_list_compact_mode')
  })
})
