import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  usePage: () => ({ url: '/products/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Products/Create', () => {
  it('renders new product form with required fields', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('新增产品')
    expect(wrapper.find('input[placeholder="输入 SKU"]').exists()).toBe(true)
    expect(wrapper.find('input[placeholder="输入产品名称"]').exists()).toBe(true)
  })

  it('shows 保存产品 submit button', () => {
    const wrapper = mount(Create)
    expect(wrapper.find('button[type="submit"]').text()).toBe('保存产品')
  })

  it('shows cancel link back to product list', () => {
    const wrapper = mount(Create)
    expect(wrapper.find('a[href="/products/list"]').exists()).toBe(true)
  })

  it('shows accounting/logistics section headings', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('物流尺寸')
    expect(wrapper.text()).toContain('海关信息')
    expect(wrapper.text()).toContain('标准成本（参考值）')
  })

  it('shows unit, shipping weight, and cost fields', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('单位')
    expect(wrapper.text()).toContain('运输重量')
    expect(wrapper.text()).toContain('标准生产成本')
    expect(wrapper.text()).toContain('标准包材成本')
    expect(wrapper.text()).toContain('标准采购成本')
    expect(wrapper.text()).toContain('成本币种')
    expect(wrapper.text()).toContain('原产地')
    expect(wrapper.text()).toContain('HS 编码')
  })
})
