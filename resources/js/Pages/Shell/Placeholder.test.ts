import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Placeholder from './Placeholder.vue'
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<head><slot /></head>' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: '/logistics', props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: [{ key: 'logistics', label: '物流', path: '/logistics', route: 'shell.logistics', placeholder: true, children: [] }] } }) }))
describe('shell placeholder', () => { it('states implementation is deferred and has no fabricated business data', () => { const wrapper = mount(Placeholder, { props: { module: { key: 'logistics', label: '物流' } } }); expect(wrapper.text()).toContain('物流模块尚未开始实现'); expect(wrapper.text()).toContain('后续授权工作包'); expect(wrapper.text()).not.toMatch(/订单号|待发货数量|成本|利润/) }) })
