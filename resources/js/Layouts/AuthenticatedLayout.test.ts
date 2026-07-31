import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from './AuthenticatedLayout.vue'
let currentUrl = '/sync'
const items = [{ key: 'dashboard', label: 'Dashboard', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }, { key: 'sync', label: '同步', path: '/sync', route: 'shell.sync', placeholder: true, children: [{ key: 'pending', label: '待处理', path: '/sync/pending', route: 'sync.pending', placeholder: true, children: [] }] }]
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: currentUrl, props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: items } }) }))
describe('authenticated layout', () => {
  beforeEach(() => { currentUrl = '/sync'; vi.clearAllMocks() })
  it('renders navigation, top bar, identity, slot and active routes', () => { currentUrl = '/sync/pending'; const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['BYL ERP', '同步'] }, slots: { default: '<p>页面内容</p>' } }); expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true); expect(wrapper.text()).toContain('Owner'); expect(wrapper.text()).toContain('页面内容'); expect(wrapper.find('a[href="/sync"]').attributes('aria-current')).toBe('page'); expect(wrapper.find('a[href="/sync/pending"]').attributes('aria-current')).toBe('page') })
  it('posts logout and toggles desktop and narrow-screen navigation accessibly', async () => { const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } }); expect(wrapper.find('[aria-label="收起模块导航"]').attributes('aria-expanded')).toBe('true'); await wrapper.find('[aria-label="收起模块导航"]').trigger('click'); expect(wrapper.find('[aria-label="展开模块导航"]').attributes('aria-expanded')).toBe('false'); expect(wrapper.classes()).toContain('sidebar-collapsed'); expect(wrapper.find('[aria-label="打开导航"]').attributes('aria-expanded')).toBe('false'); await wrapper.find('[aria-label="打开导航"]').trigger('click'); expect(wrapper.find('[aria-label="关闭导航"]').attributes('aria-expanded')).toBe('true'); expect(wrapper.classes()).toContain('mobile-nav-open'); await wrapper.find('.shell-mobile-backdrop').trigger('click'); expect(wrapper.classes()).not.toContain('mobile-nav-open'); await wrapper.find('.shell-logout').trigger('click'); expect(router.post).toHaveBeenCalledWith('/logout') })
  it('does not fabricate unauthorized navigation', () => { const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } }); expect(wrapper.text()).toContain('同步'); expect(wrapper.text()).not.toContain('设置') })
  it('removes child navigation from interaction while collapsed and restores it', async () => {
    currentUrl = '/sync/pending'
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="模块导航"]').attributes('hidden')).toBeUndefined()
    await wrapper.find('[aria-label="收起模块导航"]').trigger('click')
    expect(wrapper.find('[aria-label="模块导航"]').attributes('hidden')).toBeDefined()
    await wrapper.find('[aria-label="展开模块导航"]').trigger('click')
    expect(wrapper.find('[aria-label="模块导航"]').attributes('hidden')).toBeUndefined()
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(true)
  })
  it('makes closed narrow-screen navigation inert and restores it when opened', async () => {
    const originalMatchMedia = window.matchMedia
    window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() })
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    await wrapper.vm.$nextTick()
    const navigation = wrapper.find('[aria-label="ERP主导航"]')
    expect(navigation.attributes('inert')).toBeDefined()
    expect(navigation.attributes('aria-hidden')).toBe('true')
    expect(wrapper.find('[aria-label="打开导航"]').attributes('aria-expanded')).toBe('false')
    await wrapper.find('[aria-label="打开导航"]').trigger('click')
    expect(wrapper.find('[aria-label="ERP主导航"]').attributes('inert')).toBeUndefined()
    expect(wrapper.find('[aria-label="ERP主导航"]').attributes('aria-hidden')).toBe('false')
    expect(wrapper.find('[aria-label="关闭导航"]').attributes('aria-expanded')).toBe('true')
    wrapper.unmount()
    window.matchMedia = originalMatchMedia
  })
})
