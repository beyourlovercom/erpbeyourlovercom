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
    expect(wrapper.find('button[type="submit"]').text()).toBe('预览 CSV')
  })

  it('shows link back to product list', () => {
    const wrapper = mount(Import)
    expect(wrapper.find('a[href="/products/list"]').exists()).toBe(true)
  })

  it('links to the server-backed fixed-name CSV template', () => {
    const wrapper = mount(Import)
    const link = wrapper.find('a[href="/products/import/template"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('下载 CSV 模板')
    expect(link.attributes('download')).toBeUndefined()
  })

  it('does not show result section when no result prop', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).not.toContain('导入结果')
  })

  it('shows import result counts in Chinese when result provided', () => {
    const result = { totalRows: 6, candidates: 4, newCandidates: 3, updates: 1, unchanged: 0, active: 3, inactive: 1, skippedNoSkuVariations: 1, noSkuNonVariationReconciliation: 1, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0, canConfirm: true, errors: [] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('导入预览')
    expect(wrapper.text()).toContain('源文件行数：6 条')
    expect(wrapper.text()).toContain('候选产品：4 条')
    expect(wrapper.text()).toContain('新增：3 条')
    expect(wrapper.text()).toContain('更新：1 条')
    expect(wrapper.text()).toContain('跳过无 SKU 变体：1 条')
    expect(wrapper.text()).toContain('无效：0 条')
  })

  it('shows error messages when import has errors', () => {
    const result = { invalid: 1, canConfirm: false, errors: ['Row 2: invalid or missing ID'] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('Row 2: invalid or missing ID')
  })
})
