import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Statuses from './Statuses.vue'

const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (value:any) => reactive({ ...value, processing:false, recentlySuccessful:false, errors:{}, put }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const statuses = [
  { code:'pending_allocation', sequence:'0.0', name_zh:'待分仓', name_en:'Pending Allocation', mutable:true, feature:'人工状态', pages:'订单总表' },
  { code:'sending', sequence:'1.1', name_zh:'待发', name_en:'Sending', mutable:true, feature:'待发状态', pages:'订单列表、详情' },
  { code:'missing_info', sequence:'1.1.2', name_zh:'信息缺失', name_en:'Missing Info', mutable:true, feature:'待补资料', pages:'订单详情' },
  { code:'out_of_stock', sequence:'3.4', name_zh:'缺货', name_en:'Out of stock', mutable:true, feature:'缺货状态', pages:'物流工作台' },
]
const defaults = statuses.map(({code, sequence, name_zh, name_en})=>({code, sequence, name_zh, name_en}))

describe('Settings status editor', () => {
  beforeEach(() => put.mockClear())

  it('keeps function and pages hidden until the ellipsis is used', async () => {
    const wrapper = mount(Statuses, { props:{ statuses, defaults, version:'a'.repeat(64) } })
    expect(wrapper.findAll('.status-settings-fields')).toHaveLength(4)
    expect(wrapper.find('.status-settings-details').exists()).toBe(false)
    const ellipsis = wrapper.find('[aria-label="展开功能与适用页面"]')
    await ellipsis.trigger('click')
    expect(wrapper.findAll('.status-settings-details')).toHaveLength(4)
    expect(wrapper.text()).toContain('物流工作台')
    expect(wrapper.text()).toContain('英文名称')
    await wrapper.find('[aria-label="收起功能与适用页面"]').trigger('click')
    expect(wrapper.find('.status-settings-details').exists()).toBe(false)
  })

  it('reorders by numeric sequence after editing and persists the stable code', async () => {
    const wrapper = mount(Statuses, { props:{ statuses, defaults, version:'b'.repeat(64) } })
    const stockout = wrapper.find('input[id="sequence-out_of_stock"]')
    await stockout.setValue('1.1.1')
    await stockout.trigger('blur')
    const names = wrapper.findAll('.status-settings-row input[id^="name-"]').map(x=>(x.element as HTMLInputElement).value)
    expect(names).toEqual(['待分仓','待发','缺货','信息缺失'])
    await wrapper.findAll('button').find(button=>button.text().includes('保存状态设置'))!.trigger('click')
    expect(put).toHaveBeenCalledWith('/settings/statuses', expect.objectContaining({preserveScroll:true}))
    const editable = wrapper.find('input[id="sequence-out_of_stock"]').element as HTMLInputElement
    expect(editable.value).toBe('1.1.1')
  })

  it('blocks duplicate sequences before save', async () => {
    const wrapper = mount(Statuses, { props:{ statuses, defaults, version:'c'.repeat(64) } })
    await wrapper.find('input[id="sequence-out_of_stock"]').setValue('1.1.2')
    expect(wrapper.text()).toContain('数字序号不能重复')
    expect(wrapper.findAll('button').find(button=>button.text().includes('保存状态设置'))!.attributes('disabled')).toBeDefined()
    expect(put).not.toHaveBeenCalled()
  })
})
