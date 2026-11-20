import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import KolIdentityCombobox from './KolIdentityCombobox.vue'

const fetchMock = vi.fn()
const linked = { value: 1501, label: 'Mary Creator', account: 'mary_social' }
const unbound = { value: 1502, label: 'Mary No Affiliate', account: 'mary_unbound' }
const response = (items: unknown[]) => ({ ok: true, json: async () => items })

describe('KolIdentityCombobox', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('renders a searchable combobox and preserves an explicitly preselected KOL', () => {
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: 1501, initialOption: linked, required: true } })
    const input = wrapper.get('input')
    expect(input.attributes('role')).toBe('combobox')
    expect(input.attributes('required')).toBeDefined()
    expect((input.element as HTMLInputElement).value).toBe('Mary Creator')
    expect(fetchMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('debounces remote search, clears stale KOL id while typing, and selects the canonical id', async () => {
    fetchMock.mockResolvedValue(response([linked, unbound]))
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: null } })
    const input = wrapper.get('input')

    await input.setValue('Mary')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
    expect(fetchMock).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/kol/orders/kol-search?q=Mary', expect.objectContaining({
      credentials: 'same-origin',
    }))
    expect(wrapper.findAll('[role="option"]')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('AffiliateWP 未绑定')
    expect(wrapper.text()).toContain('@mary_social')
    await wrapper.findAll('[role="option"]')[0].trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe(1501)
    expect((input.element as HTMLInputElement).value).toBe('Mary Creator')
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('allows a KOL without AffiliateWP to be chosen', async () => {
    fetchMock.mockResolvedValue(response([unbound]))
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: null } })
    await wrapper.get('input').trigger('focus')
    await flushPromises()
    const option = wrapper.get('[role="option"]')
    expect(option.attributes('aria-disabled')).toBeUndefined()
    await option.trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe(unbound.value)
    wrapper.unmount()
  })

  it('allows keyboard selection of the first KOL regardless of AffiliateWP status', async () => {
    fetchMock.mockResolvedValue(response([unbound, linked]))
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: null } })
    await wrapper.get('input').trigger('focus')
    await flushPromises()
    expect(wrapper.findAll('[role="option"]')[0].attributes('aria-selected')).toBe('true')
    await wrapper.get('input').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([1502])
    wrapper.unmount()
  })

  it('does not replace a newer search with a stale HTTP response', async () => {
    let firstResolve: ((value: unknown) => void) | undefined
    fetchMock.mockImplementationOnce(() => new Promise(resolve => { firstResolve = resolve }))
    fetchMock.mockResolvedValueOnce(response([linked]))
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: null } })
    await wrapper.get('input').trigger('focus')
    await wrapper.get('input').setValue('Mary')
    // The earlier empty-query response must be ignored even before debounce completes.
    firstResolve?.(response([unbound]))
    await flushPromises()
    expect(wrapper.text()).not.toContain('Mary No Affiliate')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    expect(wrapper.text()).toContain('Mary Creator')
    expect(wrapper.text()).not.toContain('Mary No Affiliate')
    wrapper.unmount()
  })

  it('shows authorization errors without exposing unrelated records', async () => {
    fetchMock.mockResolvedValue({ ok: false, status: 403 })
    const wrapper = mount(KolIdentityCombobox, { props: { modelValue: null } })
    await wrapper.get('input').trigger('focus')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('没有网红搜索权限')
    wrapper.unmount()
  })
})
