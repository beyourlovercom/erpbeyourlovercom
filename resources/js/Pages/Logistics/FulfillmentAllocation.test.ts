import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import FulfillmentAllocation from './FulfillmentAllocation.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { template: '<main><slot /></main>' },
}))

const warehouses = [
  { id: 5, slug: 'factory', code: 'FACTORY', name: '中国工厂', country_code: 'CN', can_auto_allocate: false },
  { id: 1, slug: 'us-la', code: 'US-LA', name: '美西MorePlus', country_code: 'US', can_auto_allocate: true },
  { id: 2, slug: 'us-ny', code: 'US-NY', name: '美东纽约', country_code: 'US', can_auto_allocate: true },
  { id: 7, slug: 'us-sj', code: 'US-SJ', name: '美西世迈', country_code: 'US', can_auto_allocate: true },
  { id: 3, slug: 'de', code: 'DE', name: '德国天运', country_code: 'DE', can_auto_allocate: true },
  { id: 4, slug: 'ca', code: 'CA', name: '加拿大仓', country_code: 'CA', can_auto_allocate: true },
]

const itemA = {
  id: 101, product_id: 11, product_url: '/products/11', sku: 'MF414A', product_summary: 'Product A', quantity: 1,
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 5, quantity_reserved: 0, quantity_available: 5 }],
}
const itemB = {
  id: 102, product_id: 12, product_url: '/products/12', sku: 'SV205', product_summary: 'Product B', quantity: 1,
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 }],
}

const splitPlan = {
  plan_key: 'split:abc', plan_type: 'split' as const, is_split: true, shipment_count: 2, label: '拆分发货', effective_cost: 83,
  cost: { status: 'ready', amount: 78, currency: 'CNY', source: 'test' }, execution_status: 'preview_only_multi_shipment_schema_required',
  segments: [
    { source_type: 'warehouse' as const, source_code: 'US-LA', warehouse_id: 1, warehouse_name: '美西MorePlus', items: [{ product_id: 11, quantity: 1, sku: 'MF414A', product_summary: 'Product A' }] },
    { source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', items: [{ product_id: 12, quantity: 1, sku: 'SV205', product_summary: 'Product B' }] },
  ],
}
const factoryPlan = {
  plan_key: 'factory:def', plan_type: 'factory' as const, is_split: false, shipment_count: 1, label: '整单中国工厂发货', effective_cost: 106,
  cost: { status: 'ready', amount: 106, currency: 'CNY', source: 'test' }, execution_status: 'manual_review_only',
  segments: [{ source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', items: [{ product_id: 11, quantity: 1, sku: 'MF414A' }, { product_id: 12, quantity: 1, sku: 'SV205' }] }],
}

const systemOrder = {
  id: 10, erp_order_id: '007-0911-40414', order_url: '/customers/orders/10', recipient_name: 'John Smith', country: 'US', item_count: 2, items: [itemA, itemB],
  allocation_decision: {
    status: 'system_recommendation' as const, status_label: '系统推荐', reason_code: 'lowest_complete_cost_plan', manual_reasons: [], recommended_plan_key: splitPlan.plan_key,
    recommended_plan: splitPlan, baseline_plan: factoryPlan, estimated_cost: 83, currency: 'CNY', estimated_saving: 23, plans: [splitPlan, factoryPlan], can_use_recommendation: true,
    policy: { system_is_advisory: true, human_confirmation_required: true, missing_data_falls_back_to_manual: true, same_order_item_quantity_split_allowed: false, max_sources: 2, split_pattern: 'one_overseas_plus_factory' },
  },
  fulfillment_allocation: null,
}
const manualOrder = {
  ...systemOrder, id: 11, erp_order_id: '007-0911-40415',
  allocation_decision: {
    ...systemOrder.allocation_decision,
    status: 'manual_review' as const, status_label: '需要人工判断', manual_reasons: ['产品包装成本数据不足', '拆单附加成本尚未配置'], recommended_plan_key: null, recommended_plan: null, baseline_plan: null,
    estimated_cost: null, currency: null, estimated_saving: null, can_use_recommendation: false,
  },
}

const processedOrder = {
  ...manualOrder,
  fulfillment_allocation: {
    status: 'submitted',
    decision_source: 'manual' as const,
    selected_plan_key: 'manual:split',
    decision_note: '人工拆分 UAT',
    submitted_at: '2026-09-11T16:00:00+08:00',
    plan_snapshot: {
      ...splitPlan,
      derived_orders: [
        { order_id: 201, erp_order_id: '007-0911-40415A', derived: true, suffix: 'A', warehouse_id: 5, warehouse_code: 'FACTORY', warehouse_name: '中国工厂', items: [{ sku: 'MF414A', product_summary: 'Product A', quantity: 1 }] },
        { order_id: 202, erp_order_id: '007-0911-40415B', derived: true, suffix: 'B', warehouse_id: 1, warehouse_code: 'US-LA', warehouse_name: '美西MorePlus', items: [{ sku: 'SV205', product_summary: 'Product B', quantity: 1 }] },
      ],
    },
  },
}

const props = {
  orders: { data: [systemOrder, manualOrder], current_page: 1, last_page: 1, per_page: 50, total: 2 },
  warehouses,
  pendingTotal: 2,
  canOperate: true,
}

function mountPage(customProps: any = props) {
  return mount(FulfillmentAllocation, { props: customProps, attachTo: document.body })
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('Vita Fulfillment Allocation V1', () => {
  it('renders canonical table and opens 查看方案 as a modal with recommendation evidence', async () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('运营分仓')
    expect(wrapper.text()).toContain('007-0911-40414')
    expect(wrapper.text()).toContain('拆分发货')
    expect(wrapper.text()).toContain('¥83.00')
    expect(wrapper.text()).toContain('¥23.00')

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('履约方案详情')
    expect(document.body.textContent).toContain('系统推荐：拆分发货')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('采用推荐并提交分仓')
  })

  it('switches recommendation popup into manual SKU allocation without exposing 007', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const adjust = Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === '调整方案') as HTMLButtonElement
    adjust.click()
    await flushPromises()

    expect(document.body.textContent).toContain('SKU 履约分配')
    expect(document.body.textContent).toContain('V1 不允许把同一个 SKU 的数量拆到两个仓')
    const optionTexts = Array.from(document.body.querySelectorAll('option')).map(option => option.textContent)
    expect(optionTexts).toContain('中国工厂')
    expect(optionTexts).toContain('美西MorePlus')
    expect(optionTexts.join(' ')).not.toContain('007')
  })

  it('opens data-insufficient order directly in human judgment mode with explicit reasons', async () => {
    const wrapper = mountPage()
    const buttons = wrapper.findAll('button').filter(button => button.text() === '查看方案')
    await buttons[1].trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('需要人工判断')
    expect(document.body.textContent).toContain('产品包装成本数据不足')
    expect(document.body.textContent).toContain('拆单附加成本尚未配置')
    expect(document.body.textContent).toContain('确认人工分配并提交')
  })


  it('replays submitted split as A/B derived logistics orders with fixed warehouses', async () => {
    const processedProps = {
      ...props,
      orders: { ...props.orders, data: [processedOrder], total: 1 },
      pendingTotal: 1,
    }
    const wrapper = mountPage(processedProps)
    await wrapper.findAll('button').find(button => button.text().startsWith('已处理'))!.trigger('click')
    await wrapper.findAll('button').find(button => button.text() === '查看记录')!.trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('分仓已完成')
    expect(document.body.textContent).toContain('衍生物流订单')
    expect(document.body.textContent).toContain('007-0911-40415A')
    expect(document.body.textContent).toContain('007-0911-40415B')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).not.toContain('确认人工分配并提交')
  })

  it('submits a system recommendation by JSON without Inertia navigation', async () => {
    const csrf = document.createElement('meta')
    csrf.name = 'csrf-token'
    csrf.content = 'test-token'
    document.head.appendChild(csrf)
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ decision_source: 'system_recommendation', submitted_at: '2026-09-11T09:00:00+08:00' }) })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const submit = Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === '采用推荐并提交分仓') as HTMLButtonElement
    submit.click()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0][0]).toBe('/logistics/orders/10/fulfillment-allocation/recommended')
    expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'POST', credentials: 'same-origin' })
    expect(document.body.textContent).not.toContain('履约方案详情')
    expect(wrapper.text()).toContain('已处理 1')
    await wrapper.findAll('button').find(button => button.text().startsWith('已处理'))!.trigger('click')
    expect(wrapper.text()).toContain('已提交')
    csrf.remove()
  })
})
