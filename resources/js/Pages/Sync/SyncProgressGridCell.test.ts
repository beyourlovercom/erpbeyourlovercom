import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SyncProgressGridCell from './SyncProgressGridCell.vue'

describe('Sync progress AG Grid cell', () => {
  beforeEach(() => vi.stubGlobal('alert', vi.fn()))
  it('keeps the approved four statuses distinct', () => {
    const samples=[
      ['unsynced','未同步'], ['failed','同步失败'],
      ['synced','已同步'], ['notified','已通知'],
    ] as const
    samples.forEach(([code,label]) => {
      const wrapper=mount(SyncProgressGridCell, { props: { params: { data: {
        progress_code:code,progress_label:label,progress_reason:null,
      } } } })
      expect(wrapper.get('button').text()).toBe(label)
      expect(wrapper.get('button').classes()).toContain('sync-grid-progress-'+code)
      expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    })
  })
  it('explains conflicts without submitting or mutating an order', async () => {
    const wrapper=mount(SyncProgressGridCell, { props: { params: { data: {
      progress_code:'failed', progress_label:'同步失败', progress_reason:'官网物流商与 ERP 不一致',
    } } } })
    await wrapper.get('button').trigger('click')
    expect(window.alert).toHaveBeenCalledWith('官网物流商与 ERP 不一致')
  })
})
