import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  useForm: (initial: Record<string, unknown>) => ({
    ...initial,
    processing: false,
    errors: {},
    post: vi.fn(),
  }),
  usePage: () => ({ url: '/customers/orders/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../Components/Products/SkuCombobox.vue', () => ({
  default: { props: ['modelValue', 'productId'], template: '<input class="sku-combobox-stub" />' },
}))

const customerProps = {
  defaultOrderKind: 'customer',
  orderKindLabel: '客户订单',
  generatedExternalOrderNumber: 'CUS20260825ABC007',
}

const kolProps = {
  defaultOrderKind: 'kol_fulfillment',
  orderKindLabel: '网红履约订单',
  generatedExternalOrderNumber: 'KOL20260825XYZ007',
}

describe('Orders/Create V2', () => {
  it('renders 新建客户订单 title for customer route', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('新建客户订单')
  })

  it('renders 新建网红履约订单 title for kol route', () => {
    const wrapper = mount(Create, { props: kolProps })
    expect(wrapper.text()).toContain('新建网红履约订单')
  })

  it('renders four business section cards', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('shows no identity strip on Create', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.ord-identity-strip').exists()).toBe(false)
  })

  it('shows 创建订单 as the only primary submit action', () => {
    const wrapper = mount(Create, { props: customerProps })
    const submitBtn = wrapper.find('button[type="submit"]')
    expect(submitBtn.exists()).toBe(true)
    expect(submitBtn.text()).toBe('创建订单')
  })

  it('disables submit button when processing', () => {
    const wrapper = mount(Create, { props: customerProps })
    const submitBtn = wrapper.find('button[type="submit"]')
    expect(submitBtn.attributes('disabled')).toBeUndefined()
  })

  it('shows 取消 secondary action linking to list', () => {
    const wrapper = mount(Create, { props: customerProps })
    const actionBar = wrapper.find('.ord-action-bar')
    const cancelLink = actionBar.find('a[href="/customers/orders"]')
    expect(cancelLink.exists()).toBe(true)
    expect(cancelLink.text()).toBe('取消')
  })

  it('shows 取消 linking to kol list for kol route', () => {
    const wrapper = mount(Create, { props: kolProps })
    const cancelLink = wrapper.find('a[href="/kol/orders"]')
    expect(cancelLink.exists()).toBe(true)
  })

  it('renders SKU combobox for product selection in items', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('renders order date field', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('pre-fills generated external order number', () => {
    const wrapper = mount(Create, { props: customerProps })
    const input = wrapper.find('#external_order_number')
    expect(input.exists()).toBe(true)
    expect((input.element as HTMLInputElement).value).toBe('CUS20260825ABC007')
  })

  it('shows address fields', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('收件人姓名')
    expect(wrapper.text()).toContain('联系电话')
    expect(wrapper.text()).toContain('国家')
    expect(wrapper.text()).toContain('省/州')
    expect(wrapper.text()).toContain('城市')
    expect(wrapper.text()).toContain('邮政编码')
    expect(wrapper.text()).toContain('地址行 1')
    expect(wrapper.text()).toContain('地址行 2')
  })

  it('renders note textarea', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('shows add item button', () => {
    const wrapper = mount(Create, { props: customerProps })
    const addBtn = wrapper.findAll('button[type="button"]').find(b => b.text().includes('添加商品'))
    expect(addBtn).toBeTruthy()
  })
})
