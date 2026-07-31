import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Login from './Login.vue'

const post = vi.fn()
let form = { email: '', password: '', processing: false, errors: {}, post }

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    usePage: () => ({ props: { errors: {} } }),
    useForm: () => form,
}))

describe('login page', () => {
    it('renders required fields, actions, approved account text, and robots policy', () => {
        const wrapper = mount(Login)
        expect(wrapper.find('input[name="email"]').exists()).toBe(true)
        expect(wrapper.find('input[name="password"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('登录系统')
        expect(wrapper.text()).toContain('申请开通账户')
        expect(wrapper.text()).toContain('BYL ERP 账户由管理员统一创建，请联系系统管理员开通。')
        expect(wrapper.find('a[href*="register"]').exists()).toBe(false)
        expect(wrapper.find('input[name="register"]').exists()).toBe(false)
        expect(wrapper.find('meta[name="robots"]').attributes('content')).toBe('noindex, nofollow')
    })

    it('posts credentials to login and reflects processing state', async () => {
        form = { email: 'user@example.com', password: 'secret', processing: false, errors: {}, post }
        const wrapper = mount(Login)
        await wrapper.find('form').trigger('submit')
        expect(post).toHaveBeenCalledWith('/login')
        form = { email: '', password: '', processing: true, errors: {}, post }
        const processingWrapper = mount(Login)
        const submit = processingWrapper.find('button[type="submit"]')
        expect(submit.attributes('disabled')).toBeDefined()
        expect(submit.text()).toContain('验证中')
    })

    it('shows server validation errors', () => {
        form = { email: '', password: '', processing: false, errors: { email: 'Invalid' }, post }
        expect(mount(Login).find('[role="alert"]').exists()).toBe(true)
    })
})
