import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<head><slot /></head>' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: '/dashboard', props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: [{ key: 'dashboard', label: 'Dashboard', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }] } }) }))

const metrics = { pending_orders: 2, pending_shipment: 1, shipped_pending_sync: 3, completed_today: 4 }

describe('dashboard shell', () => {
    it('renders four operational metrics with their exact destinations', () => {
        const wrapper = mount(Dashboard, { props: { metrics } })

        expect(wrapper.find('#dashboard-title').text()).toBe('运营总览')
        expect(wrapper.findAll('[data-metric-card]')).toHaveLength(4)
        expect(wrapper.find('[data-testid="metric-pending-orders"]').text()).toContain('2')
        expect(wrapper.find('[data-testid="metric-pending-shipment"]').text()).toContain('1')
        expect(wrapper.find('[data-testid="metric-shipped-pending-sync"]').text()).toContain('3')
        expect(wrapper.find('[data-testid="metric-completed-today"]').text()).toContain('4')
        expect(wrapper.find('[data-testid="metric-pending-orders"]').attributes('href')).toBe('/customers/orders?order_status=pending_shipment')
        expect(wrapper.find('[data-testid="metric-pending-shipment"]').attributes('href')).toBe('/logistics/pending')
        expect(wrapper.find('[data-testid="metric-shipped-pending-sync"]').attributes('href')).toBe('/sync?status=pending')
        expect(wrapper.find('[data-testid="metric-completed-today"]').attributes('href')).toBe('/sync?status=completed')
    })

    it('displays zero metrics without hiding the operational structure', () => {
        const wrapper = mount(Dashboard, { props: { metrics: { pending_orders: 0, pending_shipment: 0, shipped_pending_sync: 0, completed_today: 0 } } })

        expect(wrapper.findAll('[data-testid="metric-value"]').map((value) => value.text())).toEqual(['0', '0', '0', '0'])
        expect(wrapper.find('[data-testid="dashboard-priority-heading"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="dashboard-completed-heading"]').exists()).toBe(true)
    })

    it('keeps the authenticated shell mounted around the dashboard', () => {
        const wrapper = mount(Dashboard, { props: { metrics } })

        expect(wrapper.text()).toContain('Owner')
        expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true)
        expect(wrapper.find('#main-content').exists()).toBe(true)
    })
})
