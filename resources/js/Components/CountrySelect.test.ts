import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CountrySelect from './CountrySelect.vue'

describe('CountrySelect', () => {
  it('searches by partial name and explicitly selects an ISO code', async () => {
    const wrapper = mount(CountrySelect, { props: { modelValue: '' } })
    const input = wrapper.get('input')
    await input.trigger('focus')
    await input.setValue('nor')
    const option = wrapper.findAll('[role="option"]').find(o => o.text().includes('Norway (NO)'))
    expect(option).toBeTruthy()
    await option!.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['NO'])
    expect((input.element as HTMLInputElement).value).toBe('Norway (NO)')
  })
  it('auto-selects exact names and rejects unresolved values', async () => {
    const wrapper = mount(CountrySelect, { props: { modelValue: '' } })
    const input = wrapper.get('input')
    await input.setValue('挪威')
    await input.trigger('blur')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['NO'])
    await input.setValue('EU')
    await input.trigger('blur')
    expect((input.element as HTMLInputElement).checkValidity()).toBe(false)
    expect(wrapper.text()).toContain('请选择准确的国家')
  })
})
