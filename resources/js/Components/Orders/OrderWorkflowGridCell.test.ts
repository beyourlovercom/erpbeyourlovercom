import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import OrderWorkflowGridCell from './OrderWorkflowGridCell.vue'

const options = [
  { code: 'pending_allocation', en: 'Pending Allocation', zh: '待分仓', color: 'neutral', mutable: true },
  { code: 'sending', en: 'Sending', zh: '待发', color: 'neutral', mutable: true },
  { code: 'missing_info', en: 'Missing Info', zh: '信息缺失', color: 'amber', mutable: true },
]
const row = {
  id: 107, order_kind: 'customer', workflow_revision: 3, can_edit_workflow_status: true,
  workflow_status_projection: { code: 'pending_allocation', en: 'Pending Allocation', zh: '待分仓', color: 'neutral', source: 'canonical_projection' },
}

it('renders the same editable dropdown inside Global Grid and sends the authoritative PATCH', async () => {
  const patch = vi.spyOn(axios, 'patch').mockResolvedValueOnce({
    data: { order: { workflow_revision: 4 }, workflow_status: { code: 'missing_info', auxiliary: [] } },
  })
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined)
  const wrapper = mount(OrderWorkflowGridCell, { props: { params: {
    data: row, workflowStatusOptions: options, canEditWorkflowStatus: true,
  } } })
  expect(wrapper.find('select[aria-label^="订单工作状态"]').exists()).toBe(true)
  await wrapper.find('select').setValue('missing_info')
  await vi.waitFor(() => expect(patch).toHaveBeenCalledWith('/orders/107/workflow-status', {
    workflow_status: 'missing_info', expected_workflow_revision: 3,
  }))
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('missing_info')
  expect(reload).toHaveBeenCalled()
  patch.mockRestore()
  reload.mockRestore()
})

it('renders read-only for unauthorized staff and never sends a mutation', () => {
  const wrapper = mount(OrderWorkflowGridCell, { props: { params: {
    data: { ...row, can_edit_workflow_status: false }, workflowStatusOptions: options,
  } } })
  expect(wrapper.find('select').exists()).toBe(false)
  expect(wrapper.text()).toContain('待分仓')
})

it('allows derived fulfillment status selection but prevents direct reallocation from its dropdown', () => {
  const wrapper = mount(OrderWorkflowGridCell, { props: { params: {
    data: { ...row, order_kind: 'fulfillment_derived' }, workflowStatusOptions: options,
  } } })
  expect(wrapper.find('select').exists()).toBe(true)
  expect(wrapper.find('option[value="pending_allocation"]').attributes('disabled')).toBeDefined()
  expect(wrapper.find('option[value="missing_info"]').attributes('disabled')).toBeUndefined()
})

it('does not allow host grid row selection to consume status interactions', async () => {
  const wrapper = mount(OrderWorkflowGridCell, { props: { params: {
    data: row, workflowStatusOptions: options,
  } } })
  const click = vi.fn()
  wrapper.element.parentElement?.addEventListener('click', click)
  await wrapper.find('select').trigger('click')
  expect(click).not.toHaveBeenCalled()
})
