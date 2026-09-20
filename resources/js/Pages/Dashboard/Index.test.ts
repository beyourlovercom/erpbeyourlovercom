import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
  usePage: () => ({
    url: '/dashboard',
    props: {
      auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
      shellNavigation: [{ key: 'dashboard', label: '仪表盘', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }],
    },
  }),
}))

const allVisible = { operations: true, inventory: true, sync: true, finance: true, procurement: true }
const noneVisible = { operations: false, inventory: false, sync: false, finance: false, procurement: false }

describe('dashboard redesign shell', () => {
  it('renders the D2 boss/operations shell without the legacy duplicate KPI contract', () => {
    const wrapper = mount(Dashboard, { props: { visibility: allVisible } })

    expect(wrapper.text()).toContain('经营与运营总览')
    expect(wrapper.text()).toContain('当前运营')
    expect(wrapper.text()).toContain('库存风险')
    expect(wrapper.text()).toContain('同步健康')
    expect(wrapper.text()).toContain('经营财务')
    expect(wrapper.text()).not.toContain('待处理订单')
    expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true)
  })

  it('shows only sections allowed by the permission visibility contract', () => {
    const wrapper = mount(Dashboard, {
      props: { visibility: { operations: true, inventory: false, sync: true, finance: false, procurement: false } },
    })

    expect(wrapper.find('#operations').exists()).toBe(true)
    expect(wrapper.find('#sync').exists()).toBe(true)
    expect(wrapper.find('#inventory').exists()).toBe(false)
    expect(wrapper.find('#finance').exists()).toBe(false)
    expect(wrapper.find('#procurement').exists()).toBe(false)
    expect(wrapper.find('a[href="#operations"]').exists()).toBe(true)
    expect(wrapper.find('a[href="#inventory"]').exists()).toBe(false)
  })

  it('supports preset and custom period navigation without fabricating finance values', () => {
    const preset = mount(Dashboard, { props: { visibility: allVisible, period: '30d' } })
    expect(preset.find('a[href="/dashboard?period=30d"]').classes()).toContain('active')
    expect(preset.find('.dashboard-custom-period').exists()).toBe(false)

    const custom = mount(Dashboard, {
      props: { visibility: allVisible, period: 'custom', dateFrom: '2026-09-01', dateTo: '2026-09-20' },
    })
    expect(custom.find('.dashboard-custom-period').exists()).toBe(true)
    expect(custom.find('input[name="date_from"]').attributes('value')).toBe('2026-09-01')
    expect(custom.text()).toContain('预估 · 非最终')
  })

  it('keeps zero-activity procurement compact and expands it only when active', () => {
    const quiet = mount(Dashboard, {
      props: {
        visibility: allVisible,
        procurementProgress: { active: 0, counts: { pending: 0, purchasing: 0, ordered: 0, completed: 0, cancelled: 0 }, recent: [] },
      },
    })
    expect(quiet.text()).toContain('当前无活跃采购')
    expect(quiet.find('.procurement-recent').exists()).toBe(false)

    const active = mount(Dashboard, {
      props: {
        visibility: allVisible,
        procurementProgress: {
          active: 2,
          counts: { pending: 1, purchasing: 0, ordered: 1, completed: 3, cancelled: 1 },
          recent: [{ id: 7, procurement_number: 'PO-20260905-0007', status: 'ordered', source_order_erp_id: '007-0908-c7', carrier: '顺丰速运', tracking_number: 'SF-7', updated_at: null }],
        },
      },
    })
    expect(active.text()).toContain('活跃采购')
    expect(active.text()).toContain('PO-20260905-0007')
    expect(active.find('a[href="/purchase/orders/7"]').exists()).toBe(true)
  })

  it('renders a bounded empty state when dashboard permission has no visible module blocks', () => {
    const wrapper = mount(Dashboard, { props: { visibility: noneVisible } })
    expect(wrapper.text()).toContain('当前账号暂无可展示的业务总览模块')
  })
})
