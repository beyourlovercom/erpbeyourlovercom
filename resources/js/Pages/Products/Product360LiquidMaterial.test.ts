import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import Product360 from './Show.vue'

const { saveSpy } = vi.hoisted(() => ({ saveSpy: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), get: vi.fn(), visit: vi.fn() },
  useForm: (initial: Record<string, unknown>) => {
    let payload = (data: Record<string, unknown>) => data
    return reactive({
      ...initial, errors: {}, processing: false,
      clearErrors: vi.fn(),
      transform(fn: typeof payload) { payload = fn; return this },
      put(url: string, options: {onSuccess?: () => void}) {
        saveSpy(url, payload({...this}))
        options.onSuccess?.()
      },
    })
  },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { template: '<div><slot /></div>' },
}))
vi.mock('../../Composables/useAccountGridPreferences', () => ({
  useAccountGridPreferences: () => ({
    columns: [], visibleColumns: [], syncState: 'idle',
    toggle: vi.fn(), startDrag: vi.fn(), drop: vi.fn(), reset: vi.fn(),
  }),
}))

const initialProps = {
  product: {
    id: 1336, sku: 'MF414A', name: 'SKULL MAN',
    product_type: 'simple', source_type: 'woocommerce', status: 'active',
    has_battery: true, has_liquid: true,
    material_full_name: 'MF414A小恶魔分级别紫色成品',
  },
  externalReferences: [], skuAliases: [],
  sales: {
    units_30d: 0, order_count_30d: 0, units_90d: 0, order_count_90d: 0,
    lifetime_units: 0, lifetime_order_count: 0, revenue_by_currency: [], recent_orders: [],
  },
  kolShipping: {
    units_30d: 0, shipment_count_30d: 0, units_90d: 0, shipment_count_90d: 0,
    lifetime_units: 0, lifetime_shipment_count: 0,
  },
  inventory: [], shipments: [], collaborations: [], audit: [],
  canOperate: true,
}
describe('Product360 material and liquid master fields', () => {
  it('renders material name above battery and liquid controls and saves independent liquid choice', async () => {
    saveSpy.mockClear()
    const wrapper = mount(Product360, { props: initialProps })
    await wrapper.get('[data-product360-tab="产品参数"]').trigger('click')
    expect(wrapper.text()).toContain('物料全称：MF414A小恶魔分级别紫色成品')
    expect(wrapper.text()).toContain('是否带液体：带液体')
    await wrapper.get('button:enabled').trigger('focus')
    const edit = wrapper.findAll('button').find(node => node.text() === '编辑参数')
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    const section = wrapper.get('.product360-inline-fields')
    const html = section.html()
    expect(html.indexOf('物料全称')).toBeLessThan(html.indexOf('是否带电'))
    expect(html.indexOf('是否带电')).toBeLessThan(html.indexOf('是否带液体'))
    await wrapper.get('textarea[placeholder="待填写物料全称"]').setValue('MF414A New 中文 English')
    const liquid = wrapper.findAll('fieldset').find(node => node.text().includes('是否带液体'))
    const nonLiquidChoice = liquid!.findAll('input[type=checkbox]')[1]
    await nonLiquidChoice.setValue(true)
    expect((liquid!.findAll('input[type=checkbox]')[0].element as HTMLInputElement).checked).toBe(false)
    const save = wrapper.findAll('button').find(node => node.text() === '保存参数')
    await save!.trigger('click')
    expect(saveSpy).toHaveBeenCalledWith('/products/1336/inline-parameters',
      expect.objectContaining({ has_liquid: false, has_battery: true, material_full_name: 'MF414A New 中文 English' }))
    await flushPromises()
  })
  it('renders read-only values for product viewers', async () => {
    const wrapper = mount(Product360, { props: { ...initialProps, canOperate: false } })
    await wrapper.get('[data-product360-tab="产品参数"]').trigger('click')
    expect(wrapper.text()).toContain('物料全称：MF414A小恶魔分级别紫色成品')
    expect(wrapper.text()).toContain('是否带电：带电')
    expect(wrapper.text()).toContain('是否带液体：带液体')
    expect(wrapper.find('button').exists()).toBe(true)
    expect(wrapper.findAll('button').some(node => node.text() === '编辑参数')).toBe(false)
  })
})
