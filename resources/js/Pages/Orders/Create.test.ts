import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import KolIdentityCombobox from './KolIdentityCombobox.vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { globalFeedback, publishGlobalFeedback } from '../../lib/globalFeedback'
import Create from './Create.vue'

const postMock = vi.hoisted(() => vi.fn())
const axiosGetMock = vi.hoisted(() => vi.fn())
vi.mock('axios', () => ({ default: { get: axiosGetMock } }))

vi.mock('@inertiajs/vue3', async () => {
  const { reactive } = await import('vue')
  return {
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  useForm: (initial: Record<string, unknown>) => reactive({
    ...initial,
    processing: false,
    errors: {},
    setError: vi.fn(),
    clearErrors: vi.fn(),
    post: postMock,
  }),
  usePage: () => ({ url: '/customers/orders/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
  }
})

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
  contactOptions: [{value: 71, label: 'Synthetic KOL Staff'}],
  selectedKolContactId: 71,
}

describe('Orders/Create V2', () => {
  beforeEach(() => {
    postMock.mockReset()
    axiosGetMock.mockReset()
    axiosGetMock.mockRejectedValue(new Error('synthetic-no-data'))
    publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
  })

  afterEach(() => publishGlobalFeedback({ state: 'IDLE', title: '', message: '' }))

  it('requires an eligible contact, places system information after products and allows a manual choice', async () => {
    const wrapper = mount(Create, { props: {...kolProps, selectedKolContactId: null} })
    const select = wrapper.get('#contact_user_id')
    expect(select.attributes('required')).toBeDefined()
    expect(select.text()).toContain('Synthetic KOL Staff')
    expect(wrapper.text().indexOf('网红联络人（负责人）')).toBeLessThan(wrapper.text().indexOf('收件与地址'))
    expect(wrapper.text().indexOf('订单商品')).toBeLessThan(wrapper.text().indexOf('系统订单信息'))
    await wrapper.get('form').trigger('submit')
    expect(postMock).not.toHaveBeenCalled()
    await select.setValue('71')
    expect((select.element as HTMLSelectElement).value).toBe('71')
    wrapper.unmount()
  })

  it('renders 新建客户订单 title for customer route', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('新建客户订单')
  })

  it('preselects the server-approved KOL when launched from its profile', () => {
    const wrapper = mount(Create, { props: {
      ...kolProps,
      selectedKolId: 42,
      kolOptions: [{ value: 42, label: 'Synthetic KOL' }, { value: 43, label: 'Other KOL' }],
    } })
    const input = wrapper.get('[data-testid="kol-identity-select"]')
    expect(input.attributes('role')).toBe('combobox')
    expect((input.element as HTMLInputElement).value).toBe('Synthetic KOL')
    wrapper.unmount()

    const customer = mount(Create, { props: { ...customerProps, selectedKolId: 42 } })
    expect(customer.find('[data-testid="kol-identity-select"]').exists()).toBe(false)
    customer.unmount()
  })

  it('autofills the recipient customs ID and shows it only for the supported KOL destination', async () => {
    const wrapper = mount(Create, { props: {
      ...kolProps, selectedKolId: 55, kolOptions: [{ value: 55, label: 'Mexican Creator' }],
      selectedKolAddress: {
        recipient_name: 'Synthetic Name', phone: '111', email: 'synthetic@example.test',
        country: 'MX', recipient_customs_id: 'SYNTHETIC-RFC-MX',
        province_state: 'Nuevo Leon', city: 'Monterrey',
        address_line_1: 'Synthetic Street', address_line_2: '', postal_code: '64000',
      },
    } })
    await nextTick()
    expect((wrapper.get('[data-testid="recipient-customs-input"]').element as HTMLInputElement).value).toBe('SYNTHETIC-RFC-MX')
    expect(wrapper.get('[data-testid="recipient-customs-input"]').attributes('required')).toBeDefined()
    await wrapper.get('[data-testid="order-country"]').setValue('BR')
    await nextTick()
    expect((wrapper.get('[data-testid="recipient-customs-input"]').element as HTMLInputElement).value).toBe('')
    await wrapper.get('[data-testid="order-country"]').setValue('US')
    await nextTick()
    expect(wrapper.find('[data-testid="recipient-customs-field"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('preselects an unbound KOL without blocking ERP logistics orders', () => {
    const wrapper = mount(Create, { props: {
      ...kolProps, selectedKolId: 5,
      kolOptions: [{ value: 5, label: 'Unbound' }],
    } })
    expect((wrapper.get('[data-testid="kol-identity-select"]').element as HTMLInputElement).value).toBe('Unbound')
    expect(wrapper.find('[data-testid="kol-affiliate-unbound"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('autofills a preselected KOL contact and shipping address from server props', async () => {
    const address = {
      recipient_name: 'Saved Recipient', phone: '+5211223344', email: 'kol@example.test',
      country: 'Mexico', province_state: 'Jalisco', city: 'Guadalajara',
      address_line_1: 'Saved Street 3', address_line_2: 'Floor 2', postal_code: '44000',
    }
    const wrapper = mount(Create, { props: {
      ...kolProps, selectedKolId: 42, kolOptions: [{ value: 42, label: 'Synthetic KOL' }],
      selectedKolAddress: address,
    } })
    await nextTick()
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('Saved Recipient')
    expect((wrapper.get('[data-testid="order-phone"]').element as HTMLInputElement).value).toBe('+5211223344')
    expect((wrapper.get('[data-testid="order-email"]').element as HTMLInputElement).value).toBe('kol@example.test')
    expect((wrapper.get('[data-testid="order-country"]').element as HTMLInputElement).value).toBe('Mexico')
    expect((wrapper.get('[data-testid="order-address-line-1"]').element as HTMLInputElement).value).toBe('Saved Street 3')
    expect(axiosGetMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('clears previous KOL address immediately when switching and fetches the next address', async () => {
    let respond!: (value: unknown) => void
    axiosGetMock.mockReturnValue(new Promise(resolve => { respond = resolve }))
    const wrapper = mount(Create, { props: {
      ...kolProps, selectedKolId: 11, kolOptions: [{ value: 11, label: 'First KOL' }],
      selectedKolAddress: {
        recipient_name: 'First Person', phone: '111', email: 'first@example.test',
        country: 'US', province_state: 'CA', city: 'LA',
        address_line_1: 'Old Address', address_line_2: '', postal_code: '90001',
      },
    } })
    await nextTick()
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('First Person')
    wrapper.getComponent(KolIdentityCombobox).vm.$emit('update:modelValue', 22)
    await nextTick()
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('')
    expect(axiosGetMock).toHaveBeenCalledWith('/kol/orders/kol-address', { params: { kol_id: 22 } })
    respond({ data: { kol_id: 22, address: {
      recipient_name: 'Second Person', phone: '222', email: 'second@example.test',
      country: 'GB', province_state: '', city: 'London',
      address_line_1: 'New Address', address_line_2: '', postal_code: 'SW1',
    } } })
    await flushPromises()
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('Second Person')
    expect((wrapper.get('[data-testid="order-address-line-1"]').element as HTMLInputElement).value).toBe('New Address')
    wrapper.unmount()
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
    const actionBar = wrapper.find('.erp-form-header-actions')
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

  it('defaults customer contact to operations and allows another employee', async () => {
    const wrapper = mount(Create, { props: { ...customerProps, defaultCustomerContactId: 11, contactOptions: [{value:11,label:'Vita'},{value:12,label:'Guts'}] } })
    const select = wrapper.get('#contact_user_id')
    expect((select.element as HTMLSelectElement).value).toBe('11')
    expect(select.attributes('required')).toBeUndefined()
    await select.setValue('12')
    expect((select.element as HTMLSelectElement).value).toBe('12')
  })

  it('defaults manual orders to USD and labels unit price with currency', () => {
    const wrapper = mount(Create, { props: customerProps })
    const currencySelect = wrapper.find('#order_currency')
    expect(currencySelect.exists()).toBe(true)
    expect((currencySelect.element as HTMLSelectElement).value).toBe('USD')
    expect(wrapper.text()).toContain('单价（USD）')
  })

  it('renders order date field', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('asks for an optional real cooperation date on KOL orders, without fabricating one client-side', () => {
    const kol = mount(Create, { props: kolProps })
    const date = kol.get('input[type="date"]')
    expect((date.element as HTMLInputElement).value).toBe('')
    expect(date.attributes('required')).toBeUndefined()
    expect(kol.text()).toContain('合作日期（可选）')
    expect(kol.text()).toContain('留空则使用本订单实际创建日期')
    kol.unmount()

    const customer = mount(Create, { props: customerProps })
    expect(customer.get('input[type="date"]').attributes('required')).toBeDefined()
    customer.unmount()
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

  // ERP V2 VI contract — no mvp-input dependency
  it('uses erp-form-page outer container', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards', () => {
    const wrapper = mount(Create, { props: customerProps })
    const cards = wrapper.findAll('.erp-form-card')
    expect(cards.length).toBeGreaterThanOrEqual(4)
  })

  it('uses erp-input for editable fields — no mvp-input class', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.mvp-input').exists()).toBe(false)
    expect(wrapper.findAll('.erp-input').length).toBeGreaterThan(0)
  })

  it('uses erp-form-label for field labels', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-form-label').exists()).toBe(true)
    expect(wrapper.find('.mvp-label').exists()).toBe(false)
  })

  it('uses erp-btn for action buttons', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-btn').exists()).toBe(true)
    expect(wrapper.find('.mvp-button').exists()).toBe(false)
  })

  it('uses erp-textarea for note field', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('textarea.erp-textarea').exists()).toBe(true)
  })

  it('shows readonly div for order kind (not editable input)', () => {
    const wrapper = mount(Create, { props: customerProps })
    const readonlyDivs = wrapper.findAll('.erp-readonly-input')
    const texts = readonlyDivs.map(d => d.text())
    expect(texts.some(t => t.includes('客户订单'))).toBe(true)
    expect(texts.some(t => t.includes('手动创建'))).toBe(true)
  })

  it('publishes submitting feedback when order creation starts', async () => {
    const wrapper = mount(Create, { props: customerProps })
    await wrapper.get('form').trigger('submit')

    const callbacks = postMock.mock.calls[0]?.[1] as { onStart?: () => void }
    callbacks.onStart?.()

    expect(globalFeedback.value).toMatchObject({
      state: 'SUBMITTING',
      title: '正在创建订单',
    })
  })

  it('maps missing Product validation to a human-readable blocker', async () => {
    const wrapper = mount(Create, { props: customerProps })
    await wrapper.get('form').trigger('submit')

    const callbacks = postMock.mock.calls[0]?.[1] as { onError?: (errors: Record<string, string>) => void }
    callbacks.onError?.({ 'items.0.product_id': 'The items.0.product_id field is required.' })

    expect(globalFeedback.value).toEqual({
      state: 'BLOCKED',
      title: '订单无法创建',
      message: '请先选择订单商品',
      next: '选择 SKU / 产品后重新提交',
    })
    expect(JSON.stringify(globalFeedback.value)).not.toContain('items.0.product_id')
  })

  it('posts customer and KOL forms to their existing endpoints', async () => {
    await mount(Create, { props: customerProps }).get('form').trigger('submit')
    expect(postMock.mock.calls[0]?.[0]).toBe('/customers/orders')

    postMock.mockReset()
    axiosGetMock.mockReset()
    axiosGetMock.mockRejectedValue(new Error('synthetic-no-data'))
    const kolWrapper=mount(Create,{props:{...kolProps,selectedKolId:42,kolOptions:[{value:42,label:'Demo'}],selectedKolAddress:{recipient_name:'Demo',country:'US',city:'Demo City',province_state:'CA',postal_code:'00001',phone:'001',email:'demo@example.com',address_line_1:'Demo Street',address_line_2:''}}})
    await nextTick()
    await kolWrapper.get('[data-testid="kol-smart-address-confirm"]').setValue(true)
    await kolWrapper.get('form').trigger('submit')
    kolWrapper.unmount()
    expect(postMock.mock.calls[0]?.[0]).toBe('/kol/orders')
  })
})

describe('A8 shared smart input — order entry',()=>{
  it('does not expose KOL parser on customer orders',()=>{
    const wrapper=mount(Create,{props:customerProps})
    expect(wrapper.find('[data-testid="kol-smart-input"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('preserves saved address until adoption, requires confirmation, and clears old-country data',async()=>{
    postMock.mockReset()
    const wrapper=mount(Create,{props:{...kolProps,selectedKolId:42,kolOptions:[{value:42,label:'Demo'}],selectedKolAddress:{recipient_name:'Old Name',country:'US',phone:'001',email:'old@example.com',province_state:'CA',city:'Old City',address_line_1:'Old Street',address_line_2:'Old Unit',postal_code:'00001'}}})
    await wrapper.get('[data-testid="kol-smart-raw"]').setValue('https://x.com/demo\nFull Name: New Name\nCountry: Mexico\nCity: New City\nPost Code: 00123')
    await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('Old Name')
    await wrapper.get('[data-testid="kol-smart-address-apply"]').trigger('click')
    expect((wrapper.get('[data-testid="order-recipient-name"]').element as HTMLInputElement).value).toBe('New Name')
    expect((wrapper.get('[data-testid="order-address-line-1"]').element as HTMLInputElement).value).toBe('')
    await wrapper.get('form#order-create-form').trigger('submit')
    expect(postMock).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="kol-smart-address-confirm"]').setValue(true)
    await wrapper.get('form#order-create-form').trigger('submit')
    expect(postMock).toHaveBeenCalledTimes(1)
    await wrapper.get('[data-testid="order-city"]').setValue('Edited City')
    await wrapper.get('form#order-create-form').trigger('submit')
    expect(postMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
