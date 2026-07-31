import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Login from './Login.vue'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    usePage: () => ({ props: { errors: {} } }),
    useForm: () => ({ email: '', password: '', processing: false, errors: {}, post: vi.fn() }),
}))

describe('public login homepage', () => {
    it('renders approved identity and login controls without registration links', () => {
        const wrapper = mount(Login)
        expect(wrapper.text()).toContain('统一管理')
        expect(wrapper.text()).toContain('高效协同')
        expect(wrapper.text()).toContain('SYSTEM ONLINE')
        expect(wrapper.find('input[name="email"]').exists()).toBe(true)
        expect(wrapper.find('input[name="password"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('注册')
        expect(wrapper.text()).toContain('申请开通账户')
    })
})
