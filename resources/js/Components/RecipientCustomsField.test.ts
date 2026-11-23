import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RecipientCustomsField from './RecipientCustomsField.vue'

describe('RecipientCustomsField UI', () => {
  it('is hidden for normal destinations and shows a required single tax ID for Mexico', async () => {
    const wrapper = mount(RecipientCustomsField, { props: { country: 'US', modelValue: '' } })
    expect(wrapper.find('[data-testid="recipient-customs-field"]').exists()).toBe(false)
    await wrapper.setProps({ country: 'Mexico' })
    expect(wrapper.get('[data-testid="recipient-customs-input"]').attributes('required')).toBeDefined()
    expect(wrapper.text()).toContain('RFC')
    await wrapper.get('[data-testid="recipient-customs-input"]').setValue('SYNTHETIC-MX-RFC')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['SYNTHETIC-MX-RFC'])
    await wrapper.setProps({ country: 'BR', modelValue: 'SYNTHETIC-BR-CPF' })
    expect(wrapper.text()).toContain('CPF')
    wrapper.unmount()
  })
  it('shows Chile, Peru, Argentina and South Korea correct hints and required status', async () => {
    const wrapper = mount(RecipientCustomsField, { props: { country: 'Chili', modelValue: '' } })
    for (const [country, label] of [
      ['Chili', 'RUT'], ['Peru', 'DNI'], ['Argentina', 'CUIT'], ['Korea', 'PCCC'],
    ] as const) {
      await wrapper.setProps({ country })
      expect(wrapper.get('[data-testid="recipient-customs-input"]').attributes('required')).toBeDefined()
      expect(wrapper.text()).toContain(label)
    }
    wrapper.unmount()
  })
  it('shows Norway seller VOEC hint but no required recipient VOEC field', () => {
    const wrapper = mount(RecipientCustomsField, { props: { country: 'Norway', modelValue: '' } })
    expect(wrapper.get('[data-testid="recipient-customs-input"]').attributes('required')).toBeUndefined()
    expect(wrapper.get('[data-testid="recipient-customs-hint"]').text()).toContain('卖家')
    expect(wrapper.get('[data-testid="recipient-customs-hint"]').text()).toContain('VOEC')
  })
})
