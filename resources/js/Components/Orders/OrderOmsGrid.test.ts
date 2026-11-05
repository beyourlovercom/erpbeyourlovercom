import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OrderOmsGrid from './OrderOmsGrid.vue'

vi.mock('../../Components/DataGrid/OmsGrid.vue', () => ({
  default: {
    name: 'OmsGrid',
    props: ['rows', 'columns', 'serverPagination', 'serverSort', 'serverSearch', 'serverColumnFilters', 'serverFilterColumns', 'namespace', 'requiredColumnIds'],
    emits: ['page-change', 'page-size-change', 'sort-change', 'search-change', 'column-search-change'],
    template: '<div data-testid="oms-grid"><slot name="actions" /></div>',
  },
}))

const props = {
  title: '全部订单',
  orders: {
    data: [{ id: 7870, erp_order_id: '007-0910-40414', external_order_number: 'WEB-40414',
      order_kind: 'customer', source_label: 'WooCommerce', order_date: '2026-11-04',
      order_status_label: '待发货', workflow_status_projection: {
        code: 'sending', en: 'Sending', zh: '待发货', color: 'amber', source: 'canonical_projection',
      }, address: { recipient_name: 'Tester', country: 'US' }, owner_label: 'Vita' }],
    current_page: 2, last_page: 12, per_page: 50, total: 580,
  },
  filters: { sort: 'date', direction: 'desc', per_page: '50' },
  canEditWorkflowStatus: true,
  workflowStatusOptions: [{ code: 'sending', en: 'Sending', zh: '待发货', color: 'amber', mutable: true }],
}
describe('Order OMS server adapter', () => {
  it('maps real order fields and renders existing status/edit cell component', () => {
    const wrapper = mount(OrderOmsGrid, { props })
    const grid = wrapper.findComponent({ name: 'OmsGrid' })
    const rows = grid.props('rows') as any[]
    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({ id: 7870, erp_order_id: '007-0910-40414',
      recipient: 'Tester', country: 'US', status_label: '待发货', owner_label: 'Vita' })
    const columns = grid.props('columns') as any[]
    expect(columns.find(c => c.colId === 'status').cellRendererParams).toMatchObject({
      mode: 'status', canEdit: true,
    })
    expect(columns.find(c => c.colId === 'actions').cellRendererParams.mode).toBe('action')
    expect(grid.props('serverPagination')).toMatchObject({ current_page: 2, total: 580 })
    expect(grid.props('serverSort')).toEqual({ colId: 'date', direction: 'desc' })
    expect(grid.props('requiredColumnIds')).toEqual(['order_number', 'actions'])
    expect(grid.props('serverSearch')).toBe('')
    expect(grid.props('serverFilterColumns')).toContain('recipient')
  })
  it('forwards only pagination, page-size and sort intents to the parent router', () => {
    const wrapper = mount(OrderOmsGrid, { props })
    const grid = wrapper.findComponent({ name: 'OmsGrid' })
    grid.vm.$emit('page-change', 3)
    grid.vm.$emit('page-size-change', 'all')
    grid.vm.$emit('sort-change', { colId: 'order_number', direction: 'asc' })
    grid.vm.$emit('search-change', '40223')
    grid.vm.$emit('column-search-change', { colId: 'recipient', value: 'Tester' })
    expect(wrapper.emitted('page-change')?.[0]).toEqual([3])
    expect(wrapper.emitted('page-size-change')?.[0]).toEqual(['all'])
    expect(wrapper.emitted('sort-change')?.[0]).toEqual([{ colId: 'order_number', direction: 'asc' }])
    expect(wrapper.emitted('search-change')?.[0]).toEqual(['40223'])
    expect(wrapper.emitted('column-search-change')?.[0]).toEqual([{ colId: 'recipient', value: 'Tester' }])
  })
})
