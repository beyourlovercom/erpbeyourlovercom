import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OrderWorkflowStatusPill from './OrderWorkflowStatusPill.vue'

describe('OrderWorkflowStatusPill', () => {
  it('uses compact approved 3.3–3.5 captions without changing workflow codes', () => {
    const options = [
      { code: 'freight_product_cost_follow_up', sequence: '3.3', en: 'Cost', zh: '需跟进成本', color: 'amber', mutable: true },
      { code: 'out_of_stock', sequence: '3.4', en: 'Out of stock', zh: '缺货', color: 'red', mutable: true },
      { code: 'renew', sequence: '3.5', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
    ]
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: { orderId: 7, canEdit: true, revision: 0,
        projection: { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', source: 'staff_workflow', mutable: true },
        options },
    })
    const captions = wrapper.findAll('option').map(node => node.text())
    expect(captions.slice(0,3)).toEqual(['3.3 · 需跟进成本','3.4 · 缺货','3.5 · 问题订单'])
    expect(captions).not.toContain('Resume · 解除当前状态 / 恢复实际进度') // KOL has no core resolve command
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
    expect(wrapper.findAll('option').map(option => option.text())).toContain('信息缺失')
    const axios = await import('axios')
    const request = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({ response: { status: 409, data: { message: '订单进度已变化，请刷新后重试。' } } })
    const select = wrapper.find('select')
    await select.setValue('missing_info')
    await vi.waitFor(() => expect(request).toHaveBeenCalled())
    expect(request.mock.calls[0][1]).toEqual({ workflow_status: 'missing_info', expected_workflow_revision: 0 })
    expect((select.element as HTMLSelectElement).value).toBe('sending')
    expect(wrapper.text()).toContain('其他操作更新')
    request.mockRestore()
  })
})

it('renders selected cost follow-up as the one primary status, not an extra chip', () => {
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 17, canEdit: true, revision: 2,
      projection: {
        code: 'freight_product_cost_follow_up', en: 'Cost', zh: '需跟进成本', color: 'amber',
        source: 'canonical_projection', mutable: false, auxiliary: [],
      },
      options: [
        { code: 'freight_product_cost_follow_up', en: 'Cost', zh: '需跟进成本', color: 'amber', mutable: true },
        { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
      ],
    },
  })
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('freight_product_cost_follow_up')
  expect(wrapper.findAll('.auxiliary-tag')).toHaveLength(0)
  expect(wrapper.text()).toContain('需跟进成本')
})

it('replaces the main visible selection instead of appending old tags', async () => {
  const axios = await import('axios')
  const { router } = await import('@inertiajs/vue3')
  const patch = vi.spyOn(axios.default, 'patch').mockResolvedValueOnce({
    data: { order: { workflow_revision: 1 }, workflow_status: { code: 'renew', auxiliary: [] } },
  })
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined)
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 27, canEdit: true, revision: 0,
      projection: { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', source: 'canonical_projection' },
      options: [
        { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
        { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
      ],
    },
  })
  await wrapper.find('select').setValue('renew')
  await vi.waitFor(() => expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('renew'))
  expect(wrapper.findAll('.auxiliary-tag')).toHaveLength(0)
  expect(patch).toHaveBeenCalledWith('/orders/27/workflow-status', {
    workflow_status: 'renew', expected_workflow_revision: 0,
  }, expect.any(Object))
  expect(reload).toHaveBeenCalled()
  patch.mockRestore()
  reload.mockRestore()
})

it('allows a privileged archived-order status correction but keeps viewer projections read-only', () => {
  for (const code of ['archived', 'progress_conflict', 'allocated', 'partially_shipped', 'completed']) {
    const wrapper = mount(OrderWorkflowStatusPill, {
      props: {
        orderId: 28, canEdit: true,
        projection: { code, en: code, zh: code, color: 'red', source: 'canonical_projection' },
        options: [{ code, en: code, zh: code, color: 'red', mutable: false }],
      },
    })
    expect(wrapper.find('select').exists()).toBe(true)
    wrapper.unmount()
  }
  const viewer = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 28, canEdit: false,
      projection: { code: 'archived', en: 'Archived', zh: '已归档', color: 'neutral', source: 'canonical_projection' },
      options: [{ code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true }],
    },
  })
  expect(viewer.find('select').exists()).toBe(false)
})

it('reactivates an archived order only after confirmation and navigates to its real queue', async () => {
  const axios = await import('axios')
  const { router } = await import('@inertiajs/vue3')
  const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
  const patch = vi.spyOn(axios.default, 'patch').mockResolvedValueOnce({ data: {
    order: { workflow_revision: 3 }, workflow_status: { code: 'sending', auxiliary: [] },
    target_url: '/logistics/china?view=pending&global_grid=1',
  } })
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined)
  const wrapper = mount(OrderWorkflowStatusPill, { props: {
    orderId: 91, canEdit: true, revision: 2,
    projection: { code: 'archived', en: 'Archived', zh: '已归档', color: 'neutral', source: 'canonical_projection' },
    options: [
      { code: 'archived', en: 'Archived', zh: '已归档', color: 'neutral', mutable: false },
      { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
    ],
  } })
  expect(wrapper.find('option[value="archived"]').attributes('disabled')).toBeDefined()
  await wrapper.find('select').setValue('sending')
  expect(patch).not.toHaveBeenCalled()
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('archived')
  await wrapper.find('select').setValue('sending')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/91/workflow-status', {
    workflow_status: 'sending', expected_workflow_revision: 2,
  }, expect.any(Object)))
  await vi.waitFor(() => expect(visit).toHaveBeenCalledWith('/logistics/china?view=pending&global_grid=1',
    { replace: true, preserveState: false, preserveScroll: false }))
  expect(confirm).toHaveBeenCalledTimes(2)
  confirm.mockRestore();patch.mockRestore();visit.mockRestore()
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
        { code: 'resolve_exception', en: 'Resolve Exception', zh: '解除异常 / 恢复实际进度', color: 'amber', mutable: true },
      ],
    },
  })
  expect(wrapper.findAll('option').map(o => o.text())).toContain('解除异常 / 恢复实际进度')
  await wrapper.find('select').setValue('resolve_exception')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/71/workflow-status', {
    workflow_status: 'resolve_exception', expected_workflow_revision: 4,
  }, expect.any(Object)))
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
  }, expect.any(Object)))
  expect(confirm).toHaveBeenCalled()
  expect(prompt).toHaveBeenCalled()
  patch.mockRestore()
  confirm.mockRestore()
  prompt.mockRestore()
})

it('offers cancellation from an operator-selected status, not only canonical projections', () => {
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 73, canEdit: true, revision: 4,
      projection: { code: 'exception', en: 'Exception', zh: '异常', color: 'red', source: 'staff_workflow' },
      options: [
        { code: 'exception', en: 'Exception', zh: '异常', color: 'red', mutable: true },
        { code: 'cancelled', en: 'Cancelled', zh: '已取消', color: 'red', mutable: true },
      ],
    },
  })
  expect(wrapper.find('select').exists()).toBe(true)
  expect(wrapper.find('option[value="cancelled"]').exists()).toBe(true)
  expect(wrapper.find('option[value="cancelled"]').attributes('disabled')).toBeUndefined()
})

it('shows missing info as main status and offers a revision-checked restore option', async () => {
  const axios = await import('axios')
  const patch = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({
    response: { status: 409, data: { message: 'stale' } },
  })
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 79, canEdit: true, revision: 1,
      projection: { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', source: 'canonical_projection', auxiliary: [] },
      options: [
        { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true },
        { code: 'renew', en: 'Renew', zh: '问题订单', color: 'amber', mutable: true },
        { code: 'resolve_manual_hold', en: 'Resume', zh: '解除当前状态 / 恢复实际进度', color: 'amber', mutable: true },
      ],
    },
  })
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('missing_info')
  expect(wrapper.findAll('.auxiliary-tag')).toHaveLength(0)
  expect(wrapper.findAll('option').map(x => x.attributes('value'))).toContain('resolve_manual_hold')
  await wrapper.find('select').setValue('resolve_manual_hold')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/79/workflow-status', {
    workflow_status: 'resolve_manual_hold', expected_workflow_revision: 1,
  }, expect.any(Object)))
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('missing_info')
  patch.mockRestore()
})

it('allows selecting 0.0 Pending Allocation to restore an unallocated held order', async () => {
  const axios = await import('axios')
  const { router } = await import('@inertiajs/vue3')
  const patch = vi.spyOn(axios.default, 'patch').mockResolvedValueOnce({
    data: { order: { workflow_revision: 8 }, workflow_status: { code: 'pending_allocation', auxiliary: [] } },
  })
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined)
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 111, canEdit: true, revision: 7,
      projection: { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', source: 'canonical_projection' },
      options: [
        { code: 'pending_allocation', en: 'Pending Allocation', zh: '待分仓', color: 'neutral', mutable: true },
        { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true },
      ],
    },
  })
  expect(wrapper.find('option[value="pending_allocation"]').attributes('disabled')).toBeUndefined()
  await wrapper.find('select').setValue('pending_allocation')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/111/workflow-status', {
    workflow_status: 'pending_allocation', expected_workflow_revision: 7,
  }, expect.any(Object)))
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('pending_allocation')
  expect(reload).toHaveBeenCalled()
  patch.mockRestore()
  reload.mockRestore()
})

it('shows exact business blocker instead of falsely claiming a revision conflict for Sending', async () => {
  const axios = await import('axios')
  const patch = vi.spyOn(axios.default, 'patch').mockRejectedValueOnce({
    response: { status: 409, data: { message: '订单尚未分仓，不能直接标记待发货。' } },
  })
  const wrapper = mount(OrderWorkflowStatusPill, {
    props: {
      orderId: 112, canEdit: true, revision: 9,
      projection: { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', source: 'canonical_projection' },
      options: [
        { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true },
        { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
      ],
    },
  })
  await wrapper.find('select').setValue('sending')
  await vi.waitFor(() => expect(wrapper.text()).toContain('订单尚未分仓，不能直接标记待发货。'))
  expect(wrapper.text()).not.toContain('状态已变化')
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('missing_info')
  patch.mockRestore()
})

it('changes the real status workbench instead of leaving a stale row in the old tab', async () => {
  const axios = await import('axios')
  const { router } = await import('@inertiajs/vue3')
  const patch = vi.spyOn(axios.default, 'patch').mockResolvedValueOnce({data:{
    order:{workflow_revision:5},
    workflow_status:{code:'out_of_stock',auxiliary:[]},
    target_url:'/logistics/oversea/us-ny?view=stockout&global_grid=1',
  }})
  const visit = vi.spyOn(router,'visit').mockImplementation(()=>undefined)
  const reload = vi.spyOn(router,'reload').mockImplementation(()=>undefined)
  const wrapper = mount(OrderWorkflowStatusPill,{props:{
    orderId:35,canEdit:true,revision:4,
    projection:{code:'sending',en:'Sending',zh:'待发',color:'neutral',source:'canonical_projection'},
    options:[{code:'sending',en:'Sending',zh:'待发',color:'neutral',mutable:true},
      {code:'out_of_stock',en:'Out of stock',zh:'缺货',color:'red',mutable:true}],
  }})
  await wrapper.find('select').setValue('out_of_stock')
  await vi.waitFor(()=>expect(visit).toHaveBeenCalledWith(
    '/logistics/oversea/us-ny?view=stockout&global_grid=1',
    {replace:true,preserveState:false,preserveScroll:false},
  ))
  expect(reload).not.toHaveBeenCalled()
  patch.mockRestore();visit.mockRestore();reload.mockRestore()
})

it('shows only actionable statuses and the current projection, without any ellipsis', () => {
  const wrapper = mount(OrderWorkflowStatusPill, { props: {
    orderId: 116, canEdit: true, revision: 2,
    projection: { code: 'sending', sequence: '1.1', en: 'Sending', zh: '待发', color: 'neutral', source: 'staff_workflow' },
    options: [
      { code: 'sending', sequence: '1.1', en: 'Sending', zh: '准备发货', color: 'neutral', mutable: true },
      { code: 'out_of_stock', sequence: '1.2', en: 'Out of stock', zh: '暂时缺货', color: 'red', mutable: true },
      { code: 'arrived', sequence: '2.3', en: 'Arrived', zh: '已收货', color: 'green', mutable: false, disabled_reason: '需真实签收记录' },
    ],
  } })
  expect(wrapper.findAll('option').map(option => option.text())).toEqual(['1.1 · 准备发货', '1.2 · 暂时缺货'])
  expect(wrapper.find('.status-more').exists()).toBe(false)
  expect(wrapper.find('[aria-label="查看全部状态"]').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('需真实签收记录')
  wrapper.unmount()

  const systemCurrent = mount(OrderWorkflowStatusPill, { props: {
    orderId: 117, canEdit: true,
    projection: { code: 'arrived', sequence: '2.3', en: 'Arrived', zh: '已收货', color: 'green', source: 'canonical_projection' },
    options: [
      { code: 'sending', sequence: '1.1', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
      { code: 'arrived', sequence: '2.3', en: 'Arrived', zh: '已收货', color: 'green', mutable: false },
    ],
  } })
  expect(systemCurrent.findAll('option').map(option => option.text())).toEqual(['1.1 · 待发', '2.3 · 已收货'])
  expect(systemCurrent.find('option[value="arrived"]').attributes('disabled')).toBeDefined()
  expect(systemCurrent.find('.status-more').exists()).toBe(false)
})
