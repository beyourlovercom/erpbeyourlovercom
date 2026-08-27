import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Import from './Import.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  usePage: () => ({ url: '/products/import', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Products/Import', () => {
  it('renders CSV upload form', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).toContain('导入 WooCommerce CSV')
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').text()).toBe('开始导入')
  })

  it('shows link back to product list', () => {
    const wrapper = mount(Import)
    expect(wrapper.find('a[href="/products/list"]').exists()).toBe(true)
  })

  it('does not show result section when no result prop', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).not.toContain('导入结果')
  })

  it('shows import result counts in Chinese when result provided', () => {
    const result = { inserted: 3, updated: 1, skipped: 2, invalid: 0, errors: [] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('导入结果')
    expect(wrapper.text()).toContain('新增：3 条')
    expect(wrapper.text()).toContain('更新：1 条')
    expect(wrapper.text()).toContain('跳过：2 条')
    expect(wrapper.text()).toContain('无效：0 条')
  })

  it('shows error messages when import has errors', () => {
    const result = { inserted: 0, updated: 0, skipped: 0, invalid: 1, errors: ['Row 2: invalid or missing ID'] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('Row 2: invalid or missing ID')
  })
})
