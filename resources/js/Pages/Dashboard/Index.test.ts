import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<head><slot /></head>' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: '/dashboard', props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: [{ key: 'dashboard', label: '仪表盘', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }] } }) }))
describe('dashboard shell', () => {
  it('renders real metric cards through the authenticated shell', () => { const wrapper = mount(Dashboard, { props: { metrics: { pending_orders: 2, pending_shipment: 1, shipped_pending_sync: 3, completed_today: 4 } } }); expect(wrapper.text()).toContain('BYL ERP 应用工作台'); expect(wrapper.text()).toContain('Owner'); expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true); expect(wrapper.text()).toContain('待发货'); expect(wrapper.text()).toContain('3') })

  it('shows the localized 仪表盘 display name in the breadcrumb and title', () => {
    const wrapper = mount(Dashboard, { props: { metrics: { pending_orders: 0, pending_shipment: 0, shipped_pending_sync: 0, completed_today: 0 } } })
    expect(wrapper.text()).toContain('仪表盘')
    expect(wrapper.text()).not.toContain('Dashboard')
  })
})
