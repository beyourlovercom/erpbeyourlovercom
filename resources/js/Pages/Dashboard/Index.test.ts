import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }))

describe('dashboard shell', () => {
    it('renders safe identity and logout action', () => {
        const wrapper = mount(Dashboard, { props: { user: { id: 1, name: 'Owner', email: 'owner@example.test' }, permissions: { viewDashboard: true } } })
        expect(wrapper.text()).toContain('Owner')
        expect(wrapper.text()).toContain('工作空间')
        expect(wrapper.text()).toContain('退出登录')
    })
})
