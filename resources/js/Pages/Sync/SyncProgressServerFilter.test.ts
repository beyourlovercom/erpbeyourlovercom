import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SyncProgressServerFilter from './SyncProgressServerFilter.vue'

describe('Sync Progress floating filter', () => {
  it('offers only the four verified Sync progress states plus all', async () => {
    const send = vi.fn()
    const wrapper = mount(SyncProgressServerFilter, {
      props: { params: { filterValue: '', onServerFilter: send } },
    })
    const select = wrapper.get('select[aria-label="筛选列 同步进度"]')
    expect(select.findAll('option').map((option) => option.attributes('value')))
      .toEqual(['', 'unsynced', 'failed', 'synced', 'notified'])
    await select.setValue('failed')
    expect(send).toHaveBeenCalledOnce()
    expect(send).toHaveBeenCalledWith('failed')
    await select.setValue('notified')
    expect(send).toHaveBeenLastCalledWith('notified')
  })

  it('does not emit unchanged state and follows server-selected value', async () => {
    const send = vi.fn()
    const wrapper = mount(SyncProgressServerFilter, {
      props: { params: { filterValue: 'synced', onServerFilter: send } },
    })
    const select = wrapper.get('select')
    expect((select.element as HTMLSelectElement).value).toBe('synced')
    await select.setValue('synced')
    expect(send).not.toHaveBeenCalled()
    await wrapper.setProps({ params: { filterValue: 'unsynced', onServerFilter: send } })
    expect((select.element as HTMLSelectElement).value).toBe('unsynced')
  })
})
