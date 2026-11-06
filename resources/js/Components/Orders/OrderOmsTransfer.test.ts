import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OrderOmsTransfer from './OrderOmsTransfer.vue'

const props = {
  exportUrl: '/orders/oms/export',
  canImport: true,
  canConfirmImport: false,
  canExport: true,
  total: 8,
  selectedOrderIds: [] as number[],
}
describe('OMS P3 preview-only production gate', () => {
  it('offers template and XLSX preflight but never offers a confirm-create control', async () => {
    const wrapper = mount(OrderOmsTransfer, {
      props,
      global: { stubs: { Teleport: true } },
    })
    await wrapper.get('button').trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.get('a.oms-template-link').attributes('href')).toBe('/orders/oms/import/template')
    expect(wrapper.find('input[type="file"]').attributes('accept')).toContain('.xlsx')
    expect(wrapper.get('button').text()).toContain('导入')
    expect(wrapper.find('button.oms-import-commit').exists()).toBe(false)
    expect(wrapper.text()).toContain('预检模式：创建订单未开放')
    expect(wrapper.text()).toContain('预检不会创建订单')
  })

  it('only reveals confirm-create control after a separately authorized write gate', async () => {
    const wrapper = mount(OrderOmsTransfer, {
      props: { ...props, canConfirmImport: true },
      global: { stubs: { Teleport: true } },
    })
    await wrapper.get('button').trigger('click')
    expect(wrapper.find('button.oms-import-commit').exists()).toBe(true)
    expect(wrapper.get('button.oms-import-commit').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('预检模式：创建订单未开放')
  })

  it('keeps the import modal completely hidden when P3-A preview is OFF', () => {
    const wrapper = mount(OrderOmsTransfer, {
      props: { ...props, canImport: false, canConfirmImport: false },
      global: { stubs: { Teleport: true } },
    })
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })
})
