import { router } from '@inertiajs/vue3'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import FulfillmentAllocation from './FulfillmentAllocation.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { reload: vi.fn(), get: vi.fn(), patch:vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { template: '<main><slot /></main>' },
}))
vi.mock('../../Components/DataGrid/GlobalGrid.vue', () => ({
  __esModule: true,
  default: {
    name:'MockGlobalGrid',
    props: ['rows','columns','enableSelection'],
    emits: ['selection-change'],
    template: '<div data-testid="mock-global-grid"><button type="button" data-testid="mock-select-rows" @click="$emit(\'selection-change\', rows.slice(0,2))">选择两单</button><slot name="footer-left" /></div>',
  },
}))

const warehouses = [
  { id: 5, slug: 'factory', code: 'FACTORY', name: '中国工厂', country_code: 'CN', can_auto_allocate: false },
  { id: 1, slug: 'us-la', code: 'US-LA', name: '美西MorePlus', country_code: 'US', can_auto_allocate: true },
  { id: 2, slug: 'us-ny', code: 'US-NY', name: '美东纽约', country_code: 'US', can_auto_allocate: true },
  { id: 7, slug: 'us-3sm', code: 'US-SM', name: '美国3世迈仓', country_code: 'US', can_auto_allocate: true },
  { id: 3, slug: 'de', code: 'DE', name: '德国天运', country_code: 'DE', can_auto_allocate: true },
  { id: 4, slug: 'ca', code: 'CA', name: '加拿大仓', country_code: 'CA', can_auto_allocate: true },
]

const carrierOptions = [
  { value: 'USPS', label: 'USPS / 美国邮政' },
  { value: '4PX', label: '4PX / 速达' },
  { value: 'DHL', label: 'DHL' },
  { value: 'Canada Post', label: 'Canada Post / 加拿大邮政' },
]
const carrierRoutes = {
  FACTORY: { carrier: '4PX', carrier_label: '4PX / 速达', service: 'SD经济挂号-带电', automatic: true },
  'US-LA': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  'US-NY': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  'US-SM': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  DE: { carrier: null, carrier_label: null, service: null, automatic: false },
  CA: { carrier: null, carrier_label: null, service: null, automatic: false },
}

const itemA = {
  id: 101, product_id: 11, product_url: '/products/11', sku: 'MF414A', source_sku: 'MF414A', resolved_fulfillment_sku: null, product_summary: 'Product A', quantity: 1, is_gift: false, gift_options_by_warehouse: {},
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 5, quantity_reserved: 0, quantity_available: 5 }],
}
const itemB = {
  id: 102, product_id: 12, product_url: '/products/12', sku: 'SV205', source_sku: 'SV205', resolved_fulfillment_sku: null, product_summary: 'Product B', quantity: 1, is_gift: false, gift_options_by_warehouse: {},
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 }],
}

const giftItem = {
  id: 103, product_id: 13, product_url: '/products/13', sku: 'gift', source_sku: 'gift', resolved_fulfillment_sku: null, product_summary: 'Website Gift', quantity: 1, is_gift: true,
  availability: [],
  gift_options_by_warehouse: {
    'US-SM': [{ mapping_id: 501, warehouse_id: 7, warehouse_code: 'US-SM', warehouse_name: '美国3世迈仓', fulfillment_product_id: 31, fulfillment_sku: 'GIFT-SM-01', fulfillment_name: '世迈礼品', quantity_available: 8, can_fulfill: true, priority: 1 }],
    'US-LA': [{ mapping_id: 502, warehouse_id: 1, warehouse_code: 'US-LA', warehouse_name: '美西MorePlus', fulfillment_product_id: 32, fulfillment_sku: 'GIFT-MP-02', fulfillment_name: 'MorePlus 礼品', quantity_available: 0, can_fulfill: false, priority: 1 }],
  },
}

const splitPlan = {
  plan_key: 'split:abc', plan_type: 'split' as const, is_split: true, shipment_count: 2, label: '拆分发货', effective_cost: 83,
  cost: { status: 'ready', amount: 78, currency: 'CNY', source: 'test' }, execution_status: 'preview_only_multi_shipment_schema_required',
  segments: [
    { source_type: 'warehouse' as const, source_code: 'US-LA', warehouse_id: 1, warehouse_name: '美西MorePlus', carrier: 'USPS', service: null, carrier_selection_source: 'automatic', items: [{ product_id: 11, quantity: 1, sku: 'MF414A', product_summary: 'Product A' }] },
    { source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ product_id: 12, quantity: 1, sku: 'SV205', product_summary: 'Product B' }] },
  ],
}
const factoryPlan = {
  plan_key: 'factory:def', plan_type: 'factory' as const, is_split: false, shipment_count: 1, label: '整单中国工厂发货', effective_cost: 106,
  cost: { status: 'ready', amount: 106, currency: 'CNY', source: 'test' }, execution_status: 'manual_review_only',
  segments: [{ source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ product_id: 11, quantity: 1, sku: 'MF414A' }, { product_id: 12, quantity: 1, sku: 'SV205' }] }],
}

const systemOrder = {
  id: 10, erp_order_id: '007-0911-40414', order_url: '/customers/orders/10', recipient_name: 'John Smith', phone: '+1 555 1000', country: 'US', province_state: 'CA', city: 'Los Angeles', address_line_1: '123 West Test Ave', address_line_2: 'Apt 2', postal_code: '90001',
  us_fulfillment_zone: { status: 'matched', zone: 'west' as const, zone_label: '美西区', postal_code: '90001', zip3: 900, state: 'CA', warehouse_priority: ['US-SM', 'US-LA'] },
  carrier_routes: carrierRoutes,
  item_count: 2, items: [itemA, itemB],
  allocation_decision: {
    status: 'system_recommendation' as const, status_label: '系统推荐', reason_code: 'lowest_complete_cost_plan', manual_reasons: [], recommended_plan_key: splitPlan.plan_key,
    recommended_plan: splitPlan, baseline_plan: factoryPlan, estimated_cost: 83, currency: 'CNY', estimated_saving: 23, plans: [splitPlan, factoryPlan], can_use_recommendation: true,
    policy: { system_is_advisory: true, human_confirmation_required: true, missing_data_falls_back_to_manual: true, same_order_item_quantity_split_allowed: false, max_sources: 6, split_pattern: 'multi_warehouse_or_factory' },
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

const giftOrder = {
  ...manualOrder, id: 12, erp_order_id: '007-0911-40416', item_count: 2, items: [itemA, giftItem],
  allocation_decision: {
    ...manualOrder.allocation_decision,
    manual_reasons: ['订单包含 gift 礼品占位 SKU，需要 Vita 确认实际履约 SKU'],
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
        { order_id: 201, erp_order_id: '007-0911-40415A', derived: true, suffix: 'A', warehouse_id: 5, warehouse_code: 'FACTORY', warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ sku: 'MF414A', product_summary: 'Product A', quantity: 1 }] },
        { order_id: 202, erp_order_id: '007-0911-40415B', derived: true, suffix: 'B', warehouse_id: 1, warehouse_code: 'US-LA', warehouse_name: '美西MorePlus', carrier: 'USPS', service: null, carrier_selection_source: 'automatic', items: [{ sku: 'SV205', product_summary: 'Product B', quantity: 1 }] },
      ],
    },
  },
}

const props = {
  orders: { data: [systemOrder, manualOrder], current_page: 1, last_page: 1, per_page: 50, total: 2 },
  warehouses,
  carrierOptions,
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

describe('Rejected overseas legacy allocation compatibility', () => {
  it('treats a rejected but historically submitted order as actionable, not processed', async () => {
    const returned = {
      ...processedOrder,
      id: 81,
      erp_order_id: '007-REJECTED-SUBMITTED',
      allocation_rejection: { source:'海外仓', reason:'仓库要求重新分仓', by:'Dede', at:null },
    }
    const wrapper = mountPage({
      ...props,
      orders: { ...props.orders, data:[returned], total:1 },
      pendingTotal:1,
      rejectionCount:1,
      rejectionFilter:true,
      rejectionNoticeVersion:'legacy-submitted-rejection',
      globalGridPilotEnabled:false,
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="allocation-rejected-tab"]').text()).toContain('1')
    expect(wrapper.findAll('.allocation-table tbody tr')).toHaveLength(1)
    expect(wrapper.find('.allocation-table tbody tr').text()).toContain('仓库要求重新分仓')
    expect(wrapper.find('.allocation-table .sticky-action button').text()).toBe('查看方案')
    await wrapper.find('.allocation-table .sticky-action button').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('⚠ 已驳回：仓库要求重新分仓')
    expect(document.body.textContent).toContain('确认人工分配并提交')
    expect(document.body.textContent).not.toContain('分仓已完成')
    wrapper.unmount()
  })
})

describe('Vita Fulfillment Allocation V1.1', () => {
  it('reuses Global Grid multi-selection and batch footer, with a second explicit confirmation', async () => {
    const fetchSpy = vi.fn().mockResolvedValue({
      ok:true, json:async()=>({results:[{order_id:10,ok:true,message:'已完成分仓'}]}),
    })
    vi.stubGlobal('fetch',fetchSpy)
    const wrapper=mountPage({...props, globalGridPilotEnabled:true})
    await flushPromises()
    expect(wrapper.find('[data-testid="mock-global-grid"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="grid-batch-footer"]').exists()).toBe(false)

    await wrapper.get('[data-testid="mock-select-rows"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="grid-batch-footer"]').text()).toContain('已选 2 条')
    await wrapper.get('select[aria-label="批量操作"]').setValue('recommend')
    await wrapper.get('button.apply-batch').trigger('click')
    await flushPromises()
    const dialog=document.body.querySelector('[data-testid="allocation-batch-confirm"]')
    expect(dialog?.textContent).toContain('可提交 1 条')
    expect(dialog?.textContent).toContain('需逐单处理 1 条')
    expect(fetchSpy).not.toHaveBeenCalled()

    const confirm=document.body.querySelector<HTMLButtonElement>('[data-testid="allocation-batch-confirm-apply"]')
    expect(confirm?.disabled).toBe(false)
    confirm?.click()
    await flushPromises()
    expect(fetchSpy).toHaveBeenCalledTimes(1)
    const [url,options]=fetchSpy.mock.calls[0]
    expect(url).toBe('/logistics/wait-stock/batch-allocation')
    const body=JSON.parse(options.body as string)
    expect(body).toMatchObject({action:'recommend',order_ids:[10],confirmed:true})
    expect(body.plan_keys['10']).toBe('split:abc')
    wrapper.unmount()
  })

  it('keeps bulk controls disabled for view-only users and processed tab', async () => {
    const wrapper=mountPage({...props,globalGridPilotEnabled:true,canOperate:false})
    await flushPromises()
    expect(wrapper.get('[data-testid="mock-global-grid"]').attributes('enableselection')).toBeUndefined()
    expect(wrapper.find('[data-testid="grid-batch-footer"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reuses domestic audited batch archive on processed/gift views with permission and confirmation', async () => {
    const fetchSpy=vi.fn().mockResolvedValue({
      ok:true,json:async()=>({results:[{order_id:10,ok:true,message:'已归档'},{order_id:11,ok:false,message:'没有权限归档该订单'}]}),
    })
    vi.stubGlobal('fetch',fetchSpy)
    const wrapper=mountPage({...props,globalGridPilotEnabled:true,canOperate:false,canArchive:true})
    await flushPromises()
    expect(wrapper.get('[data-testid="mock-select-rows"]').element).toBeTruthy()
    await wrapper.get('[data-testid="mock-select-rows"]').trigger('click')
    await flushPromises()
    const bulk=wrapper.get('[data-testid="grid-batch-footer"]')
    const values=bulk.findAll('select option').map(option=>option.attributes('value'))
    expect(values).toContain('archive')
    expect(values).not.toContain('recommend')
    await bulk.get('select[aria-label="批量操作"]').setValue('archive')
    await bulk.get('button.apply-batch').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="allocation-batch-confirm"]')?.textContent).toContain('批量归档确认')
    expect(document.body.textContent).toContain('不会删除订单')
    expect(fetchSpy).not.toHaveBeenCalled()
    document.body.querySelector<HTMLButtonElement>('[data-testid="allocation-batch-confirm-apply"]')?.click()
    await flushPromises()
    expect(fetchSpy).toHaveBeenCalledTimes(1)
    const [url,request]=fetchSpy.mock.calls[0]
    expect(url).toBe('/orders/archive/batch')
    expect(JSON.parse(request.body as string)).toEqual({order_ids:[10,11]})
    wrapper.unmount()
  })

  it('does not offer bulk archive to accounts without orders.archive', async () => {
    const wrapper=mountPage({...props,globalGridPilotEnabled:true,canOperate:true,canArchive:false})
    await flushPromises()
    await wrapper.get('[data-testid="mock-select-rows"]').trigger('click')
    await flushPromises()
    const values=wrapper.get('[data-testid="grid-batch-footer"]').findAll('select option')
      .map(option=>option.attributes('value'))
    expect(values).not.toContain('archive')
    wrapper.unmount()
  })

  it('renders canonical table and opens 查看方案 as a modal with recommendation evidence', async () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('待分仓')
    expect(wrapper.text()).toContain('V1.2.1')
    // V1.2.1 daily-operation compactness: all repeated hints live only in 查看方案.
    expect(wrapper.find('.allocation-info').exists()).toBe(false)
    expect(wrapper.find('.page-foot').exists()).toBe(false)
    expect(wrapper.find('.page-heading p').exists()).toBe(false)
    expect(wrapper.find('.principle').exists()).toBe(false)
    expect(wrapper.find('.header-actions').text()).not.toContain('System Assisted')
    expect(wrapper.find('.header-actions').text()).not.toContain('Human Confirmed')
    expect(wrapper.find('.reason-link').exists()).toBe(false)
    expect(wrapper.find('.advice-cell small').exists()).toBe(false)
    expect(wrapper.find('.allocation-table thead').text()).not.toContain('不代表有货')
    expect(wrapper.findAll('.allocation-table tbody tr')[0].findAll('.sticky-action button')).toHaveLength(1)
    expect(wrapper.text()).toContain('123 West Test Ave, Apt 2, Los Angeles, CA, 90001, US')
    expect(wrapper.text()).toContain('美西区')
    expect(wrapper.text()).toContain('美国3世迈仓 → 美西MorePlus')
    expect(wrapper.text()).toContain('007-0911-40414')
    expect(wrapper.text()).toContain('拆分发货')

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('履约方案详情')
    expect(document.body.textContent).toContain('系统候选方案：拆分发货')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('采用推荐并提交分仓')
    expect(document.body.textContent).toContain('估算成本：¥83.00')
    expect(document.body.textContent).toContain('仓库地理参考')
    expect(document.body.textContent).toContain('90001')
    expect(document.body.textContent).toContain('美西区')
  })

  it('shows the read-only offline world map for a non-US order without changing allocation actions', async () => {
    const order = { ...manualOrder, country:'DE', city:'Berlin', province_state:'BE', postal_code:'10115', us_fulfillment_zone:null }
    const wrapper = mountPage({ ...props, orders:{ ...props.orders, data:[order], total:1 } })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const map = document.body.querySelector('[data-testid="offline-warehouse-map"]')
    expect(map).toBeTruthy()
    expect(map?.textContent).toContain('全球')
    expect(map?.textContent).toContain('收件区域')
    expect(map?.textContent).toContain('最近仓')
    expect(map?.textContent).toContain('美东纽约')
    expect(map?.textContent).toContain('德国天运')
    expect(map?.textContent).toContain('直线距离估算')
    expect(document.body.textContent).toContain('确认人工分配并提交')
    const region = Array.from(map!.querySelectorAll('button')).find(b => b.textContent==='收件区域')!
    region.click()
    await flushPromises()
    expect(region.getAttribute('aria-pressed')).toBe('true')
    expect(map?.querySelector('svg')?.getAttribute('viewBox')).not.toBe('0 0 1000 500')
  })

  it('keeps warehouse and carrier controls at the top and previews A/B grouping', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('SKU 履约分配')
    expect(document.body.textContent).toContain('先确认发货仓，再确认物流商')
    expect(document.body.textContent).toContain('当前将生成 2 个履约订单')
    expect(document.body.textContent).toContain('007-0911-40414A')
    expect(document.body.textContent).toContain('007-0911-40414B')
    expect(document.body.textContent).toContain('USPS / 美国邮政')
    expect(document.body.textContent).toContain('4PX / 速达')
    const optionTexts = Array.from(document.body.querySelectorAll('option')).map(option => option.textContent)
    expect(optionTexts).toContain('中国工厂')
    expect(optionTexts).toContain('美西MorePlus')
    expect(optionTexts.join(' ')).not.toContain('007')
  })

  it('previews A/B/C grouping for three independent warehouse sources', async () => {
    const itemC = {
      ...itemB,
      id: 104,
      product_id: 14,
      sku: 'ABC-C',
      source_sku: 'ABC-C',
      product_summary: 'Product C',
    }
    const abcOrder = {
      ...manualOrder,
      id: 14,
      erp_order_id: '007-0911-ABC01',
      item_count: 3,
      items: [itemA, itemB, itemC],
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [abcOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()

    const rows = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')) as HTMLTableRowElement[]
    expect(rows).toHaveLength(3)
    for (const [index, source] of ['US-LA', 'US-NY', 'US-SM'].entries()) {
      const select = rows[index].querySelectorAll('select')[0] as HTMLSelectElement
      select.value = source
      select.dispatchEvent(new Event('change', { bubbles: true }))
      await flushPromises()
    }

    expect(document.body.textContent).toContain('当前将生成 3 个履约订单')
    expect(document.body.textContent).toContain('007-0911-ABC01A')
    expect(document.body.textContent).toContain('007-0911-ABC01B')
    expect(document.body.textContent).toContain('007-0911-ABC01C')
    expect(document.body.textContent).toContain('最多 6 个履约来源')
    expect(document.body.textContent).not.toContain('一个海外仓 + 中国工厂')
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


  it('shows gift resolution in the SKU row and filters actual gift SKUs by selected warehouse', async () => {
    const giftProps = {
      ...props,
      orders: { ...props.orders, data: [giftOrder], total: 1 },
      pendingTotal: 1,
    }
    const wrapper = mountPage(giftProps)
    expect(wrapper.text()).toContain('gift × 1')
    expect(wrapper.text()).toContain('待解析')
    expect(wrapper.text()).toContain('礼品待确认 1')

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('实际履约 SKU')
    expect(document.body.querySelector('.stock-list [title="礼品 GIFT-SM-01：有货 8"]')?.textContent).toBe('世迈仓8')

    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    expect(giftRow).toBeTruthy()
    const selects = giftRow.querySelectorAll('select')
    expect(selects.length).toBe(3)
    const warehouseSelect = selects[0] as HTMLSelectElement
    warehouseSelect.value = 'US-SM'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).toContain('GIFT-SM-01')
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).not.toContain('GIFT-MP-02')
  })


  it('shows the exact empty state when selected warehouse has no clearance gift pool', async () => {
    const noPoolGiftItem = { ...giftItem, gift_options_by_warehouse: {} }
    const noPoolOrder = { ...giftOrder, id: 13, items: [itemA, noPoolGiftItem] }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [noPoolOrder], total: 1 }, pendingTotal: 1 })

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouseSelect = giftRow.querySelectorAll('select')[0] as HTMLSelectElement
    warehouseSelect.value = 'US-SM'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(document.body.textContent).toContain('该仓未配置有效的清仓 Gift SKU')
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(giftSelect.options.length).toBe(1)
  })

  it('shows the exact out-of-stock state and preserves audited manual gift choice', async () => {
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [giftOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouseSelect = giftRow.querySelectorAll('select')[0] as HTMLSelectElement
    warehouseSelect.value = 'US-LA'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(document.body.textContent).toContain('Gift 当前可出库库存不足')
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).toContain('GIFT-MP-02')
    expect(Array.from(giftSelect.options).find(option => option.textContent?.includes('GIFT-MP-02'))?.textContent).toContain('人工可选')
  })


  it('keeps a verified MorePlus Gift selectable when its sibling SKU inventory sync failed', async () => {
    const healthy = { ...giftItem.gift_options_by_warehouse['US-LA'][0],
      quantity_available: 7, can_fulfill: true, stock_gate_reason: null }
    const failed = { ...healthy, mapping_id: 503, fulfillment_sku: 'UNSYNCED-GIFT',
      quantity_available: 0, can_fulfill: false, stock_gate_reason: 'provider_sku_not_synced' }
    const independentGift = { ...giftItem, gift_options_by_warehouse: {
      ...giftItem.gift_options_by_warehouse, 'US-LA': [healthy, failed],
    } }
    const independentOrder = { ...giftOrder, id: 442, items: [itemA, independentGift] }
    const wrapper = mountPage({ ...props,
      orders: { ...props.orders, data: [independentOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const row = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr'))
      .find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouse = row.querySelectorAll('select')[0] as HTMLSelectElement
    warehouse.value = 'US-LA'
    warehouse.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    const gift = row.querySelectorAll('select')[2] as HTMLSelectElement
    const choices = Array.from(gift.options)
    expect(choices.find(option => option.textContent?.includes('GIFT-MP-02'))?.disabled).toBe(false)
    expect(choices.find(option => option.textContent?.includes('UNSYNCED-GIFT'))?.textContent).toContain('人工可选')
    expect(row.querySelector('.stock-list [title="礼品 GIFT-MP-02：有货 7"]')?.textContent).toBe('美西MorePlus7')
    wrapper.unmount()
  })

  it('auto-detects blocked US-SM OMS snapshot and labels the manual exception', async () => {
    const blockedGift = {
      ...giftItem, gift_options_by_warehouse: {
        ...giftItem.gift_options_by_warehouse,
        'US-SM': [{ ...giftItem.gift_options_by_warehouse['US-SM'][0],
          quantity_available: 0, erp_quantity_available: 10,
          can_fulfill: false, stock_gate_reason: 'oms_snapshot_blocked' }],
      },
    }
    const blockedOrder = { ...giftOrder, id: 440, items: [itemA, blockedGift] }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [blockedOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr'))
      .find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouse = giftRow.querySelectorAll('select')[0] as HTMLSelectElement
    warehouse.value = 'US-SM'
    warehouse.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    expect(document.body.textContent).toContain('OMS 库存待核验：快照被阻断')
    expect(giftRow.querySelector('.stock-list [title*="GIFT-SM-01"]')).toBeNull()
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    const blocked = Array.from(giftSelect.options).find(option => option.textContent?.includes('GIFT-SM-01'))
    expect(blocked?.textContent).toContain('人工可选')
    wrapper.unmount()
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
    expect(document.body.textContent).toContain('衍生订单 A')
    expect(document.body.textContent).toContain('007-0911-40415A')
    expect(document.body.textContent).toContain('007-0911-40415B')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).not.toContain('确认人工分配并提交')
  })

  it('preflights manual allocation directly while preserving server-side validation', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ can_submit: true, issues: [] }) })
      .mockResolvedValueOnce({
        ok: true, json: async () => ({ decision_source: 'manual', submitted_at: '2026-09-11T09:00:00+08:00' }),
      })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPage()
    const viewButtons = wrapper.findAll('button').filter(button => button.text() === '查看方案')
    await viewButtons[1].trigger('click')
    await flushPromises()
    const rows = Array.from(document.body.querySelectorAll('.manual-table-wrap tbody tr'))
    expect(rows).toHaveLength(2)
    for (const row of rows) {
      const input = row.querySelector('select') as HTMLSelectElement
      input.value = 'US-LA'
      input.dispatchEvent(new Event('change', { bubbles: true }))
    }
    await flushPromises()
    const submit = Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === '确认人工分配并提交') as HTMLButtonElement
    submit.click()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[0][0]).toContain('/fulfillment-allocation/preflight')
    const payload = JSON.parse(fetchMock.mock.calls[1][1].body)
    expect(payload.stock_exception_acknowledged).toBe(false)
    expect(payload.assignments).toHaveLength(2)
    wrapper.unmount()
  })

  it('preflight reports the exact failed SKU beside submit and never sends allocation POST', async () => {
    const fetchMock=vi.fn().mockResolvedValue({
      ok:true,
      json:async()=>({can_submit:false,issues:[{sku:'MF414A',warehouse:'US-LA',reason:'MorePlus 商品 MF414A 没有已验证的实际 SKU 映射'}]}),
    })
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage()
    const buttons=wrapper.findAll('button').filter(button=>button.text()==='查看方案')
    await buttons[1].trigger('click')
    await flushPromises()
    const selects=Array.from(document.body.querySelectorAll('.manual-table-wrap tbody tr td:nth-child(5) select')) as HTMLSelectElement[]
    for(const select of selects){
      select.value='US-LA';select.dispatchEvent(new Event('change',{bubbles:true}))
    }
    await flushPromises()
    const submit=Array.from(document.body.querySelectorAll('button')).find(b=>b.textContent==='确认人工分配并提交') as HTMLButtonElement
    submit.click()
    await flushPromises()
    const confirm=document.body.querySelector('.stock-exception-confirm') as HTMLButtonElement|null
    if(confirm){confirm.click();await flushPromises()}
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toContain('/fulfillment-allocation/preflight')
    expect(document.body.querySelector('.modal-actions .submit-error')?.textContent).toContain('MF414A')
    wrapper.unmount()
  })

  it('reads back a committed allocation after HTTP 500 and never retries the POST', async () => {
    const fetchMock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({can_submit:true,issues:[]})})
      .mockResolvedValueOnce({
        ok:false,status:500,headers:{get:()=> 'test-error-500'},
        json:async()=>({message:'Server Error'}),
      })
      .mockImplementationOnce(async()=>({
        ok:true,json:async()=>({
          status:'submitted',decision_source:'manual',submitted_at:'2026-11-14T09:17:24Z',
          assignments:JSON.parse(fetchMock.mock.calls[1][1].body).assignments,
        }),
      }))
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage()
    const viewButtons=wrapper.findAll('button').filter(button=>button.text()==='查看方案')
    await viewButtons[1].trigger('click')
    await flushPromises()
    const rows=Array.from(document.body.querySelectorAll('.manual-table-wrap tbody tr'))
    for(const row of rows){
      const select=row.querySelector('select') as HTMLSelectElement
      select.value='US-LA';select.dispatchEvent(new Event('change',{bubbles:true}))
    }
    await flushPromises()
    const submit=Array.from(document.body.querySelectorAll('button')).find(b=>b.textContent==='确认人工分配并提交') as HTMLButtonElement
    submit.click()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[0][0]).toContain('/fulfillment-allocation/preflight')
    expect(fetchMock.mock.calls[1][0]).toContain('/fulfillment-allocation/manual')
    expect(fetchMock.mock.calls[2][0]).toContain('/fulfillment-allocation/status')
    expect(document.body.querySelector('.modal-backdrop')).toBeNull()
    wrapper.unmount()
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
    expect(wrapper.text()).toContain('已分仓')
    csrf.remove()
  })

  it('shows 27 units of US-SM as a cross-region manual alternative, not green US-NY', async () => {
    const item = {
      ...itemA, id: 205, product_id: 205, sku: 'TEST-205', quantity: 2,
      availability: [
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
        { warehouse_id: 7, warehouse_slug: 'us-3sm', warehouse_name: '美国3世迈仓', inventory_state: 'manual', can_auto_allocate: false, quantity_on_hand: 27, quantity_reserved: 0, quantity_available: 27 },
      ],
    }
    const order = {
      ...manualOrder, id: 20, country: 'US', province_state: 'VA', postal_code: '24141',
      us_fulfillment_zone: { status: 'matched', zone: 'east', zone_label: '美东区', postal_code: '24141', zip3: 241, state: 'VA', warehouse_priority: ['US-NY'] },
      items: [item], item_count: 2,
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [order], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(b => b.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const advice = document.body.querySelector('.primary-allocation .warehouse-advice') as HTMLElement
    expect(advice.classList.contains('manual')).toBe(true)
    expect(advice.textContent).toContain('跨区人工备选 · 美国3世迈仓')
    expect(advice.textContent).toContain('可用 27 件')
    expect(advice.textContent).toContain('Full Snapshot')
    expect(advice.textContent).toContain('不是系统自动推荐')
    expect(document.body.querySelector('.primary-allocation .warehouse-advice.recommended')).toBeNull()
    expect(document.body.textContent).toContain('区域优先仓（不代表有货）')
    expect((document.body.querySelector('.primary-allocation tbody select') as HTMLSelectElement).value).toBe('')
  })

  it('shows no recommendation when all US warehouses are out of stock', async () => {
    const item = {
      ...itemA, id: 206, product_id: 206, sku: 'TEST-206', quantity: 2,
      availability: [
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
        { warehouse_id: 7, warehouse_slug: 'us-3sm', warehouse_name: '美国3世迈仓', inventory_state: 'manual', can_auto_allocate: false, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
      ],
    }
    const order = {
      ...manualOrder, id: 21, country: 'US', province_state: 'VA', postal_code: '24141',
      us_fulfillment_zone: { status: 'matched', zone: 'east', zone_label: '美东区', postal_code: '24141', zip3: 241, state: 'VA', warehouse_priority: ['US-NY'] },
      items: [item], item_count: 2,
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [order], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(b => b.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const advice = document.body.querySelector('.primary-allocation .warehouse-advice') as HTMLElement
    expect(advice.classList.contains('none')).toBe(true)
    expect(advice.textContent).toContain('无有效推荐')
    expect(advice.textContent).not.toContain('美东纽约')
    expect(document.body.querySelector('.primary-allocation .warehouse-advice.recommended')).toBeNull()
  })

})
describe('V1.2 evidence-aware allocation list', () => {
  it('shows verified warehouse candidates even if cost comparison is incomplete', async () => {
    const candidate = {
      ...manualOrder, id: 99, erp_order_id: '007-test-candidate', items: [itemA],
      allocation_decision: {
        ...manualOrder.allocation_decision,
        plans: [{
          plan_key: 'single:la', plan_type: 'single' as const, is_split: false, shipment_count: 1,
          label: '整单美西MorePlus发货', execution_status: 'preview',
          cost: {status:'missing_evidence',amount:null,currency:null},
          segments: [{
            source_type:'warehouse' as const,source_code:'US-LA',warehouse_id:1,
            warehouse_name:'美西MorePlus',items:[{product_id:11,quantity:1,sku:'MF414A'}],
          }],
        }],
      },
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [candidate], total: 1 } })
    expect(wrapper.text()).toContain('V1.2.1')
    expect(wrapper.text()).toContain('库存可履约：美西MorePlus')
    expect(wrapper.find('.allocation-table .advice-cell').text()).toBe('库存可履约：美西MorePlus')
    expect(wrapper.text()).not.toContain('产品包装成本数据不足')
    expect(wrapper.find('.allocation-table .status-chip').text()).not.toBe('数据不足')
    expect(wrapper.text()).not.toContain('可确认分仓')
    const tab = wrapper.findAll('.tabs button').find(button => button.text().includes('仓库建议'))
    await tab!.trigger('click')
    expect(wrapper.findAll('.allocation-table tbody tr')).toHaveLength(1)
    expect(wrapper.find('.reason-link').exists()).toBe(false)
    await wrapper.find('.allocation-table button.primary').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('产品包装成本数据不足')
    expect(document.body.textContent).toContain('确认人工分配并提交')
  })

  it('keeps unverified inventory out of green status and provides specific action', async () => {
    const pendingItem = {
      ...itemA, availability: [{
        warehouse_id:1, warehouse_slug:'us-la', warehouse_name:'美西MorePlus',
        inventory_state:'pending', can_auto_allocate:false,
        quantity_on_hand:5, quantity_reserved:0, quantity_available:5,
      }],
    }
    const pendingOrder = {
      ...manualOrder, id: 100, erp_order_id: '007-test-pending', items: [pendingItem],
      allocation_decision: { ...manualOrder.allocation_decision, plans: [] },
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [pendingOrder], total: 1 } })
    expect(wrapper.text()).toContain('库存待核实：美西MorePlus')
    expect(wrapper.text()).not.toContain('可确认分仓')
    const tab = wrapper.findAll('.tabs button').find(button => button.text().includes('库存待核实'))
    await tab!.trigger('click')
    expect(wrapper.findAll('.allocation-table tbody tr')).toHaveLength(1)
  })
})

it('keeps allocation advice but adds an editable canonical status column to the Global Grid', async () => {
  const order = {
    ...systemOrder, order_kind:'customer',
    workflow_revision: 7, can_edit_workflow_status: true,
    workflow_status_projection:{ code:'pending_allocation', en:'Pending Allocation', zh:'待分仓', color:'neutral', source:'canonical_projection' },
  }
  const wrapper = mount(FulfillmentAllocation, { props: {
    ...props, rejectionCount:0, rejectionNoticeVersion:'grid-test', rejectionFilter:false,
    orders:{...props.orders, data:[order], total:1},
    globalGridPilotEnabled:true, canEditWorkflowStatus:true,
    workflowStatusOptions:[
      { code:'pending_allocation', en:'Pending Allocation', zh:'待分仓', color:'neutral', mutable:true },
      { code:'missing_info', en:'Missing Info', zh:'信息缺失', color:'amber', mutable:true },
    ],
  } })
  await flushPromises()
  const grid = wrapper.find('[data-testid="mock-global-grid"]')
  expect(grid.exists()).toBe(true)
  const component = wrapper.findComponent({ name:'MockGlobalGrid' })
  const columns = component.props('columns') as Array<{colId:string;cellRenderer?:{name?:string}}>
  const status = columns.find(column=>column.colId==='workflow_status')
  expect(status?.cellRenderer).toBeTruthy()
  expect(columns.some(column=>column.colId==='status')).toBe(true)
  const entry = (component.props('rows') as Array<Record<string,unknown>>)[0]
  expect(entry.workflow_revision).toBe(7)
  expect(entry.workflow_status).toBe('待分仓')
  expect(entry.can_edit_workflow_status).toBe(true)
})

describe('Actual fulfillment product selection', () => {
  it('shows master names, searches replacement SKUs, refreshes inventory and submits the selected product', async () => {
    const target = {id:99,sku:'TW05R',name:'TARA - RED',material_full_name:'红色产品'}
    const fetchMock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({products:[target]})})
      .mockResolvedValueOnce({ok:true,json:async()=>({availability:[{...itemA.availability[0],quantity_available:15}]})})
      .mockResolvedValueOnce({ok:true,json:async()=>({can_submit:true})})
      .mockResolvedValueOnce({ok:true,json:async()=>({status:'submitted',decision_source:'manual'})})
    vi.stubGlobal('fetch',fetchMock)
    const original={...itemA,product_name:'Original English',material_full_name:'原商品中文'}
    const order={...manualOrder,contact_user_name:'Vita',items:[original],item_count:1}
    const wrapper=mountPage({...props,orders:{...props.orders,data:[order]}})
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    const row=document.body.querySelector('.manual-table-wrap tbody tr')!
    expect(row.querySelector('td')?.textContent).toContain('Original English')
    expect(row.querySelector('td')?.textContent).toContain('原商品中文')
    const search=row.querySelector('.replacement-picker input') as HTMLInputElement
    search.dispatchEvent(new Event('focus'))
    await flushPromises()
    search.value='tw05r';search.dispatchEvent(new Event('input',{bubbles:true}))
    await flushPromises()
    const select=row.querySelector('.replacement-options')!
    expect(select.textContent).toContain('红色产品')
    expect(document.body.textContent).toContain('订单联络人Vita')
    ;(Array.from(select.querySelectorAll('button')).find(b=>b.textContent?.includes('TW05R'))!).click()
    await flushPromises()
    expect(row.querySelector('.stock-list [title="ERP参考 15 · OMS待核验"]')?.textContent).toBe('美西MorePlus15')
    expect(row.textContent).toContain('实际发货：TW05R')
    const warehouse=row.querySelector('td:nth-child(5) select') as HTMLSelectElement
    warehouse.value='US-LA';warehouse.dispatchEvent(new Event('change',{bubbles:true}))
    await flushPromises()
    const submit=Array.from(document.body.querySelectorAll('button')).find(b=>b.textContent==='确认人工分配并提交')!
    submit.click()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(4)
    for(const call of fetchMock.mock.calls.slice(2)){
      expect(JSON.parse(call[1].body).assignments[0]).toMatchObject({order_item_id:original.id,replacement_product_id:99,source_code:'US-LA'})
    }
    wrapper.unmount()
  })
})


describe('parent reallocation confirmation', () => {
  it('requires a second confirmation and sends the exact reset route and token', async () => {
    const order={...manualOrder,reallocation_token:'a'.repeat(64),workflow_revision:2}
    const fetchMock=vi.fn().mockResolvedValue({ok:true,json:async()=>({status:'reset',archived_count:2})})
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage({...props,orders:{...props.orders,data:[order],total:1}})
    await wrapper.findAll('button').find(button=>button.text()==='查看方案')!.trigger('click')
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-open"]')!.click()
    await flushPromises()
    expect(document.body.querySelector('[data-testid="reallocation-confirm"]')?.textContent).toContain('警告：此操作将归档所有相关子订单！')
    expect(fetchMock).not.toHaveBeenCalled()
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-confirm"] button')!.click()
    await flushPromises()
    expect(fetchMock).not.toHaveBeenCalled()
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-open"]')!.click()
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-confirm-submit"]')!.click()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toBe('/logistics/orders/11/fulfillment-allocation/reset')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({confirmed:true,token:'a'.repeat(64)})
    wrapper.unmount()
  })
  it('preserves the plan and displays a dispatch conflict without a retry', async () => {
    const order={...manualOrder,reallocation_token:'b'.repeat(64),workflow_revision:2}
    const fetchMock=vi.fn().mockResolvedValue({ok:false,status:409,headers:{get:()=>null},json:async()=>({message:'存在仓库接单风险，不能直接归档。'})})
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage({...props,orders:{...props.orders,data:[order],total:1}})
    await wrapper.findAll('button').find(button=>button.text()==='查看方案')!.trigger('click')
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-open"]')!.click()
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-testid="reallocation-confirm-submit"]')!.click()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(document.body.querySelector('.modal-actions .submit-error')?.textContent).toContain('存在仓库接单风险')
    expect(document.body.querySelector('[data-testid="reallocation-open"]')).not.toBeNull()
    wrapper.unmount()
  })
  it('does not expose reset to a read-only operator', async () => {
    const order={...manualOrder,reallocation_token:'c'.repeat(64),workflow_revision:2}
    const wrapper=mountPage({...props,canOperate:false,orders:{...props.orders,data:[order],total:1}})
    await wrapper.findAll('button').find(button=>button.text()==='查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="reallocation-open"]')).toBeNull()
    wrapper.unmount()
  })
})

describe('ERP1 compact allocation dialog', () => {
  it('shows only positive warehouse references with aligned quantities, keeping zero/unknown hidden', async () => {
    const availability = [
      {...itemA.availability[0], quantity_available:10},
      {...itemA.availability[0], warehouse_id:2, quantity_available:20},
      {...itemA.availability[0], warehouse_id:7, quantity_available:30},
      {...itemA.availability[0], warehouse_id:4, quantity_available:2},
      {...itemA.availability[0], warehouse_id:3, quantity_available:5},
      {...itemA.availability[0], warehouse_id:5, quantity_available:0},
    ]
    const wrapper=mountPage({...props,orders:{...props.orders,data:[{...systemOrder,items:[{...itemA,availability},itemB]}]}})
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    const rows=document.body.querySelectorAll('.primary-allocation tbody tr')
    expect(Array.from(rows[0].querySelectorAll('.stock-list span')).map(r=>r.textContent)).toEqual(['美西MorePlus10','美东20','世迈仓30','加拿大仓2','德国仓5'])
    expect(rows[0].querySelector('.stock-list')?.getAttribute('style')).toContain('repeat(3,auto)')
    expect(rows[1].textContent).toContain('暂无已知有货仓库')
    expect(rows[1].querySelectorAll('.stock-list span')).toHaveLength(0)
    expect(Array.from(rows[1].querySelectorAll('select option')).some(o=>o.textContent==='中国工厂')).toBe(true)
    wrapper.unmount()
  })
  it('switches previous and next visible orders without submitting them', async () => {
    const fetchMock=vi.fn()
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage()
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    const previous=()=>document.body.querySelector('[aria-label="上一单"]') as HTMLButtonElement
    const next=()=>document.body.querySelector('[aria-label="下一单"]') as HTMLButtonElement
    expect(previous().disabled).toBe(true)
    next().click(); await flushPromises()
    expect(document.body.querySelector('.info-card')?.textContent).toContain(manualOrder.erp_order_id)
    expect(next().disabled).toBe(true)
    previous().click(); await flushPromises()
    expect(document.body.querySelector('.info-card')?.textContent).toContain(systemOrder.erp_order_id)
    expect(fetchMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('opens the next eligible order only after successful submission and reload', async () => {
    const fetchMock=vi.fn().mockResolvedValue({ok:true,json:async()=>({submitted_at:'2026-11-27T00:00:00Z'})})
    vi.stubGlobal('fetch',fetchMock)
    const wrapper=mountPage()
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    const submit=Array.from(document.body.querySelectorAll('button')).find(b=>b.textContent==='采用推荐并提交分仓')!
    submit.click(); await flushPromises()
    const reload=vi.mocked(router.reload)
    const options=reload.mock.calls.at(-1)?.[0]
    expect(options?.onSuccess).toBeTruthy()
    options?.onSuccess?.({} as any)
    await flushPromises()
    expect(document.body.querySelector('.info-card')?.textContent).toContain(manualOrder.erp_order_id)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})

describe('ERP1 allocation reuses order item amendment', () => {
  const editable={...systemOrder,order_kind:'customer',source_type:'woocommerce',currency:'USD',can_edit_items:true,items_revision:'a'.repeat(64),
    items:[{...itemA,unit_price:'99.00'},{...itemB,unit_price:'0.00'}]}
  it('removes a zero-price line through the existing order PATCH with revision, never an allocation submit',async()=>{
    const wrapper=mountPage({...props,orders:{...props.orders,data:[editable]}})
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    const rows=document.body.querySelectorAll('.primary-allocation tbody tr')
    const remove=Array.from(rows[1].querySelectorAll('button')).find(b=>b.textContent==='删除产品')!
    remove.click();await flushPromises()
    const edit=document.body.querySelector('[data-testid="allocation-item-editor"]')!
    expect(edit.querySelectorAll('.allocation-edit-row')).toHaveLength(1)
    expect(vi.mocked(router.patch)).not.toHaveBeenCalled()
    Array.from(edit.querySelectorAll('button')).find(b=>b.textContent==='保存商品修改')!.click()
    await flushPromises()
    expect(router.patch).toHaveBeenLastCalledWith('/customers/orders/10',{
      items:[{id:101,product_id:11,product_summary:'Product A',quantity:1,unit_price:'99.00'}],
      expected_revision:'a'.repeat(64),amendment_reason:'',
    },expect.objectContaining({preserveState:true,onSuccess:expect.any(Function),onError:expect.any(Function)}))
    const options=vi.mocked(router.patch).mock.calls.at(-1)?.[2]
    options?.onError?.({items:'订单商品修改失败'})
    await flushPromises()
    expect(edit.textContent).toContain('订单商品修改失败')
    wrapper.unmount()
    vi.mocked(router.patch).mockClear()
  })
  it('opens an add-product editor with the existing SKU combobox and blocks incomplete or unauthorized saves',async()=>{
    const wrapper=mountPage({...props,orders:{...props.orders,data:[editable]}})
    await wrapper.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    Array.from(document.body.querySelectorAll('button')).find(b=>b.textContent?.includes('添加产品'))!.click()
    await flushPromises()
    const edit=document.body.querySelector('[data-testid="allocation-item-editor"]')!
    expect(edit.querySelectorAll('.allocation-edit-row')).toHaveLength(3)
    Array.from(edit.querySelectorAll('button')).find(b=>b.textContent==='保存商品修改')!.click()
    await flushPromises()
    expect(edit.textContent).toContain('请选择有效 SKU')
    expect(router.patch).not.toHaveBeenCalled()
    wrapper.unmount()
    const view=mountPage({...props,orders:{...props.orders,data:[{...editable,can_edit_items:false}]}})
    await view.findAll('button').find(b=>b.text()==='查看方案')!.trigger('click')
    await flushPromises()
    expect(Array.from(document.body.querySelectorAll('.primary-allocation button')).some(b=>b.textContent?.includes('添加产品')||b.textContent==='删除产品')).toBe(false)
    view.unmount()
  })
})
