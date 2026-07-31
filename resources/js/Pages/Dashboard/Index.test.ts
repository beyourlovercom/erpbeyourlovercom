import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<head><slot /></head>' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: '/dashboard', props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: [{ key: 'dashboard', label: 'Dashboard', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }] } }) }))
describe('dashboard shell', () => { it('renders through the authenticated shell without fabricated metrics', () => { const wrapper = mount(Dashboard); expect(wrapper.text()).toContain('BYL ERP 应用工作台'); expect(wrapper.text()).toContain('Owner'); expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true); expect(wrapper.text()).not.toMatch(/待发货|订单数|成本|利润/) }) })
