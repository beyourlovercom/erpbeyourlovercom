import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OrderWorkflowStatusPill from './OrderWorkflowStatusPill.vue'

describe('OrderWorkflowStatusPill', () => {
  it('uses compact approved 3.3–3.5 captions without changing workflow codes', () => {
    const options = [
      { code: 'freight_product_cost_follow_up', en: 'Cost', zh: '需跟进成本', color: 'amber', mutable: true },
      { code: 'out_of_stock', en: 'Out of stock', zh: '缺货', color: 'red', mutable: true },
      { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
    ]
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: { orderId: 7, canEdit: true, revision: 0,
        projection: { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', source: 'staff_workflow', mutable: true },
        options },
    })
    const captions = wrapper.findAll('option').map(node => node.text())
    expect(captions).toEqual(['3.3 Cost · 需跟进成本','3.4 Out of stock · 缺货','3.5 Renew · 问题订单'])
  })

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

it('shows independent cost follow-up without changing shipping progress', () => {
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 17,
      canEdit: true,
      canManageAuxiliary: true,
      revision: 2,
      projection: {
        code: 'shipping', en: 'Shipping', zh: '运输中', color: 'blue',
        source: 'canonical_projection', mutable: false,
        auxiliary: [{ code: 'freight_product_cost_follow_up', en: 'Cost', zh: '需跟进成本', color: 'amber' }],
      },
      options: [
        { code: 'shipping', en: 'Shipping', zh: '运输中', color: 'blue', mutable: false },
        { code: 'freight_product_cost_follow_up', en: 'Cost', zh: '需跟进成本', color: 'amber', mutable: true },
      ],
    },
  })
  expect(wrapper.find('select').element.value).toBe('shipping')
  expect(wrapper.find('[aria-label="移除需跟进成本"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('需跟进成本')
  expect(wrapper.text()).toContain('2.1 Shipping · 运输中')
})


it('keeps main phase visible after adding an independent follow-up', async () => {
  const axios = await import('axios')
  const stub = vi.spyOn(axios.default, 'patch').mockResolvedValueOnce({
    data: { order: { workflow_revision: 1 }, workflow_status: {
      code: 'sending',
      auxiliary: [{ code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber' }],
    } },
  })
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 27, canEdit: true, canManageAuxiliary: true, revision: 0,
      projection: { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', source: 'canonical_projection' },
      options: [
        { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
        { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
      ],
    },
  })
  await wrapper.find('select').setValue('renew')
  await vi.waitFor(() => expect(wrapper.find('.auxiliary-tag').exists()).toBe(true))
  await vi.waitFor(() => expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('sending'))
  expect(wrapper.find('.auxiliary-tag').text()).toContain('问题订单')
  expect(stub).toHaveBeenCalledWith('/orders/27/workflow-status', {
    workflow_status: 'renew', expected_workflow_revision: 0,
  })
  stub.mockRestore()
})

it('does not offer manual progress editing for archived and reconciliation statuses', () => {
  for (const code of ['archived', 'progress_conflict', 'allocated', 'partially_shipped']) {
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: {
        orderId: 28, canEdit: true,
        projection: { code, en: code, zh: code, color: 'red', source: 'canonical_projection' },
        options: [{ code, en: code, zh: code, color: 'red', mutable: false }],
      },
    })
    expect(wrapper.find('select').exists()).toBe(false)
  }
})

it('exposes manual exception resolution as a real core command with the same revision', async () => {
  const axios = await import('axios')
  const patch = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({ response: { status: 409, data: { message: 'stale' } } })
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 71, canEdit: true, revision: 4,
      projection: { code: 'exception', en: 'Exception', zh: '异常', color: 'red', source: 'canonical_projection' },
      options: [
        { code: 'exception', en: 'Exception', zh: '异常', color: 'red', mutable: true },
      ],
    },
  })
  expect(wrapper.findAll('option').map(o => o.text())).toContain('Resolve Exception · 解除异常')
  await wrapper.find('select').setValue('resolve_exception')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/71/workflow-status', {
    workflow_status: 'resolve_exception', expected_workflow_revision: 4,
  }))
  patch.mockRestore()
})

it('requires explicit confirmation and a cancellation reason before sending real ERP cancellation', async () => {
  const axios = await import('axios')
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
  const prompt = vi.spyOn(window, 'prompt').mockReturnValue('客户要求取消此订单')
  const patch = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({ response: { status: 409, data: { message: 'stale' } } })
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 72, canEdit: true, revision: 5,
      projection: { code: 'pending_allocation', en: 'Pending Allocation', zh: '待分仓', color: 'neutral', source: 'canonical_projection' },
      options: [
        { code: 'pending_allocation', en: 'Pending Allocation', zh: '待分仓', color: 'neutral', mutable: false },
        { code: 'cancelled', en: 'Cancelled', zh: '已取消', color: 'red', mutable: true },
      ],
    },
  })
  await wrapper.find('select').setValue('cancelled')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/72/workflow-status', {
    workflow_status: 'cancelled', expected_workflow_revision: 5, reason: '客户要求取消此订单',
  }))
  expect(confirm).toHaveBeenCalled()
  expect(prompt).toHaveBeenCalled()
  patch.mockRestore()
  confirm.mockRestore()
  prompt.mockRestore()
})

it('treats missing info as exception subtype and clears it through the real auxiliary endpoint', async () => {
  const axios = await import('axios')
  const { router } = await import('@inertiajs/vue3')
  const remove = vi.spyOn(axios.default, 'delete').mockResolvedValueOnce({
    data: { order: { workflow_revision: 2 }, workflow_status: { code: 'pending_allocation', auxiliary: [] } },
  })
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined)
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 79, canEdit: true, canManageAuxiliary: true, revision: 1,
      projection: {
        code: 'exception', en: 'Exception', zh: '异常', color: 'red', source: 'canonical_projection',
        auxiliary: [{ code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber' }],
      },
      options: [
        { code: 'exception', en: 'Exception', zh: '异常', color: 'red', mutable: true },
        { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true },
      ],
    },
  })
  expect(wrapper.text()).toContain('信息缺失')
  expect(wrapper.findAll('option').some(x => x.attributes('value') === 'resolve_exception')).toBe(false)
  await wrapper.find('[aria-label="移除信息缺失"]').trigger('click')
  await vi.waitFor(() => expect(remove).toHaveBeenCalledWith('/orders/79/auxiliary-status/missing_info', {
    data: { expected_workflow_revision: 1 },
  }))
  expect(reload).toHaveBeenCalled()
  remove.mockRestore()
  reload.mockRestore()
})
