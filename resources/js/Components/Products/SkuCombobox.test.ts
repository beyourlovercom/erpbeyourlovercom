import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import SkuCombobox from './SkuCombobox.vue'

const mockFetch = vi.fn()
global.fetch = mockFetch

describe('SkuCombobox', () => {
  beforeEach(() => {
    mockFetch.mockReset()
    vi.useRealTimers()
  })

  it('renders text input', () => {
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('emits update:modelValue on input', async () => {
    vi.useFakeTimers()
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    mockFetch.mockResolvedValue({ json: async () => [] })
    await wrapper.find('input').setValue('AB')
    await wrapper.find('input').trigger('input')
    expect(wrapper.emitted('update:modelValue')).toBeTruthy()
    expect(wrapper.emitted('update:productId')).toBeTruthy()
    expect(wrapper.emitted('update:productId')![0]).toEqual([null])
    vi.clearAllTimers()
    vi.useRealTimers()
    wrapper.unmount()
  })

  it('shows dropdown with SKU — name format when results returned', async () => {
    vi.useFakeTimers()
    mockFetch.mockResolvedValue({ json: async () => [{ id: 1, sku: 'SKU-A', name: 'Product A' }] })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    await wrapper.find('input').setValue('SKU')
    await wrapper.find('input').trigger('input')
    vi.runAllTimers()
    vi.useRealTimers()
    await flushPromises()
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('SKU-A')
    expect(wrapper.text()).toContain('Product A')
    wrapper.unmount()
  })

  it('selects item and emits product_id', async () => {
    vi.useFakeTimers()
    mockFetch.mockResolvedValue({ json: async () => [{ id: 42, sku: 'SEL-001', name: 'Selected' }] })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    await wrapper.find('input').setValue('SEL')
    await wrapper.find('input').trigger('input')
    vi.runAllTimers()
    vi.useRealTimers()
    await flushPromises()
    await wrapper.vm.$nextTick()
    const item = wrapper.find('.sku-combobox-dropdown li')
    await item.trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['SEL-001'])
    expect(wrapper.emitted('update:productId')?.at(-1)).toEqual([42])
    wrapper.unmount()
  })

  it('does not show dropdown for empty query', async () => {
    vi.useFakeTimers()
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    await wrapper.find('input').setValue('')
    await wrapper.find('input').trigger('input')
    expect(wrapper.find('.sku-combobox-dropdown').exists()).toBe(false)
    vi.clearAllTimers()
    vi.useRealTimers()
    wrapper.unmount()
  })
})
