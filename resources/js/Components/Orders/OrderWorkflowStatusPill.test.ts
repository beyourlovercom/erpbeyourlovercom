import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OrderWorkflowStatusPill from './OrderWorkflowStatusPill.vue'

describe('OrderWorkflowStatusPill', () => {
  it('keeps a read-only projection non-editable and explains its source', () => {
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: {
        orderId: 7,
        canEdit: false,
        projection: { code: 'sent', en: 'Sent', zh: '已发等单号', color: 'blue', source: 'canonical_projection', mutable: true },
      },
    })

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[title]').attributes('title')).toContain('只读投影')
    expect(wrapper.text()).toContain('已发等单号')
  })

  it('uses revision only for the first change from a null database status and rolls back the DOM on rejection', async () => {
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: {
        orderId: 8, canEdit: true, revision: 0,
        projection: { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', source: 'canonical_projection', mutable: true },
        options: [{ code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true }, { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true }],
      },
    })
    expect(wrapper.findAll('option').map(option => option.text())).toContain('1.5 Missing Info · 信息缺失')
    const axios = await import('axios')
    const request = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({ response: { status: 409, data: { message: 'stale' } } })
    const select = wrapper.find('select')
    await select.setValue('missing_info')
    await vi.waitFor(() => expect(request).toHaveBeenCalled())
    expect(request.mock.calls[0][1]).toEqual({ workflow_status: 'missing_info', expected_workflow_revision: 0 })
    expect((select.element as HTMLSelectElement).value).toBe('sending')
    expect(wrapper.text()).toContain('状态已变化')
    request.mockRestore()
  })
})
