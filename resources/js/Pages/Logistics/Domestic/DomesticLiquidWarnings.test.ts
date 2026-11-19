import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DomesticGridOrderLiquidCell from './DomesticGridOrderLiquidCell.vue'
import DomesticGridPendingSkuCell from './DomesticGridPendingSkuCell.vue'

describe('国内待发货含液体提醒', () => {
  const mixedOrder = {
    order_number: '007-1119-MIXED',
    order_url: '/orders/99',
    has_liquid: true,
    liquid_skus: ['BY489A', 'BV339A'],
  }
  it('always displays the amber order identity warning in pending view', () => {
    const wrapper = mount(DomesticGridOrderLiquidCell, {
      props: { params: { data: mixedOrder, showLiquid: true } },
    })
    expect(wrapper.get('a').attributes('href')).toBe('/orders/99')
    expect(wrapper.text()).toContain('007-1119-MIXED')
    expect(wrapper.text()).toContain('⚠ 含液体')
    expect(wrapper.get('.domestic-liquid-warning').attributes('title'))
      .toContain('BY489A、BV339A')
  })
  it('does not flag unmarked orders or other views', () => {
    const regular = mount(DomesticGridOrderLiquidCell, {
      props: { params: { data: { ...mixedOrder, has_liquid: false }, showLiquid: true } },
    })
    expect(regular.find('.domestic-liquid-warning').exists()).toBe(false)
    const otherView = mount(DomesticGridOrderLiquidCell, {
      props: { params: { data: mixedOrder, showLiquid: false } },
    })
    expect(otherView.find('.domestic-liquid-warning').exists()).toBe(false)
  })
  it('marks the SKU cell without changing existing barcode actions', () => {
    const showBarcode = vi.fn()
    const params = {
      data: { id: 99, sku: 'BY489A / OTHER', has_liquid: true, liquid_skus: ['BY489A'], country: 'US' },
      showBarcode, preview: () => null, error: () => '', loading: () => false,
    }
    const wrapper = mount(DomesticGridPendingSkuCell, { props: { params } })
    expect(wrapper.text()).toContain('含液体')
    expect(wrapper.get('.liquid-mark').attributes('title')).toContain('BY489A')
    expect(wrapper.find('button').exists()).toBe(false)
    expect(showBarcode).not.toHaveBeenCalled()
    const regular = mount(DomesticGridPendingSkuCell, {
      props: { params: { ...params, data: { ...params.data, has_liquid: false } } },
    })
    expect(regular.find('.liquid-mark').exists()).toBe(false)
  })
})
