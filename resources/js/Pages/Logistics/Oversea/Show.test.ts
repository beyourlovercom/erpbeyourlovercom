import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const { routerPost, routerPatch, routerVisit, routerReload } = vi.hoisted(() => ({
  routerPost: vi.fn(), routerPatch: vi.fn(), routerVisit: vi.fn(), routerReload: vi.fn(),
}))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: routerPost, patch: routerPatch, visit: routerVisit, reload: routerReload },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('../../../Components/DataGrid/GlobalGrid.vue', () => ({ __esModule:true, default: {name:'MockGlobalGrid',props:['columns','rows','enableSelection','selectedOrderIds'],template:'<div class="test-global-grid"><slot name="footer-left" /></div>'} }))

const warehouseOptions = [
  { key: 'us-moreplus', id: 1, code: 'US-LA', label: '美西 MorePlus 仓', carrier: 'USPS', url: '/logistics/oversea/us-moreplus' },
  { key: 'us-3sm', id: 2, code: 'US-SM', label: '美国3世迈仓', carrier: 'USPS', url: '/logistics/oversea/us-3sm' },
]
const rows = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null }
const usSmPendingRows = { data: [{
  line: 1, order_id: 99, erp_order_id: '007-OVERSEA-USSM-99', order_url: '/orders/99', recipient_name: 'Recipient', country: 'US',
  warehouse_id: 2, warehouse_name: '美国3世迈仓', carrier: 'USPS', service: null, carrier_selection_source: 'automatic',
  tracking_number: null, progress: 'sending_pending', note: null, review_status: 'approved' as const, shipment_status: 'pending', items: [{ sku: 'REVIEW-SKU', quantity: 1 }],
}], current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 }
const statusRow = { ...usSmPendingRows.data[0], workflow_status_projection: { code:'shipped', en:'Shipped', zh:'已发货', color:'green', source:'canonical_projection', mutable:false } }
const viewCounts = { review: 0, pending: 0, shipped: 0, exceptions: 0, all: 0 }
const preview = {
  token: 'a'.repeat(64), apply_count: 1, noop_count: 0, blocked_count: 0,
  rows: [{
    row: 2, order_id: 7, order_number: 'C40761-007', tracking_number: '92144904245461009999', carrier: 'USPS',
    shipped_at: '2026-09-20T09:40:00+00:00', shipped_time_source: 'apply' as const,
    provider_order_reference: 'MP-1', provider_service: 'USPS Ground Advantage', provider_status: 'Pending',
    status: 'apply' as const, message: '',
  }],
}

const mountPage = (overrides: Record<string, unknown> = {}) => mount(Show, { props: {
  warehouseKey: 'us-moreplus', warehouse: warehouseOptions[0], warehouseOptions, view: 'pending', rows, viewCounts,
  canReview: true, canOperate: true, carrierOptions: [], trackingImportPreview: preview, xlwmsOmsWebEnabled:true,
  shipoutProductionMinOrderId:1, shipoutProductionOperatorAllowed:true, ...overrides,
} })

describe('oversea Global Grid with narrow persistent warehouse rail', () => {
  beforeEach(() => {routerVisit.mockClear()})
  it('uses the new grid without rendering the legacy table until a row is opened', async () => {
    const wrapper=mountPage({globalGridEnabled:true,rows:usSmPendingRows,gridSearch:'',gridSort:{colId:'line',direction:'desc'}})
    await flushPromises()
    expect(wrapper.find('[data-testid="oversea-compact-warehouse-rail"]').exists()).toBe(true)
    expect(wrapper.find('.warehouse-link[title="美国3世迈仓"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="oversea-global-grid-workbench"]').exists()).toBe(true)
    expect(wrapper.find('.test-global-grid').exists()).toBe(true)
    expect(wrapper.find('.oversea-native-workflow').exists()).toBe(false)
    expect(wrapper.find('[data-testid="oversea-global-grid-toggle"]').text()).toBe('返回原表')
  })
  it('switches warehouses from the narrow sidebar without losing the current status or grid mode', async () => {
    const wrapper=mountPage({globalGridEnabled:true,view:'pending',gridSearch:'',gridSort:{colId:'line',direction:'desc'}})
    await wrapper.find('.warehouse-link[title="美国3世迈仓"]').trigger('click')
    const url=routerVisit.mock.calls[0][0] as string
    expect(url).toContain('/logistics/oversea/us-3sm?')
    expect(url).toContain('view=pending')
    expect(url).toContain('global_grid=1')
    expect(url).toContain('page=1')
  })
})

describe('oversea domestic-style Global Grid review operation contract', () => {
  it('shows the order date and uses the existing domestic inline review cell actions', async () => {
    const wrapper=mountPage({globalGridEnabled:true,view:'review',rows:usSmPendingRows,canReview:true})
    await flushPromises()
    const grid=wrapper.findComponent({name:'MockGlobalGrid'})
    expect(grid.exists()).toBe(true)
    const columns=grid.props('columns') as {colId:string;headerName:string;cellRenderer?:unknown}[]
    expect(columns.find(col=>col.colId==='order_date')?.headerName).toBe('订单日期')
    expect(columns.find(col=>col.colId==='actions')?.headerName).toBe('操作')
    expect(columns.find(col=>col.colId==='actions')?.cellRenderer).toBeTruthy()
    expect(grid.props('enableSelection')).toBe(true)
  })
  it('provides a domestic-style batch footer for review selection', async () => {
    const reviewRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],review_status:'pending' as const}]}
    const wrapper=mountPage({globalGridEnabled:true,view:'review',rows:reviewRows,canReview:true})
    await flushPromises()
    const grid=wrapper.findComponent({name:'MockGlobalGrid'})
    grid.vm.$emit('selection-change',[{id:99}])
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="grid-batch-footer"]').exists()).toBe(true)
    expect(wrapper.find('select[aria-label="批量操作"]').text()).toContain('批量缺货')
    expect(wrapper.find('select[aria-label="批量操作"]').text()).toContain('批量驳回')
  })
})

describe('warehouse OMS portal inside the overseas page heading', () => {
  it('renders the 世迈 OMS link within the page heading, not the global header', () => {
    const wrapper=mountPage({ warehouseKey:'us-3sm', warehouse:warehouseOptions[1], view:'review' })
    const link=wrapper.find('.oversea-heading .warehouse-portal-link')
    expect(link.exists()).toBe(true)
    expect(link.text()).toContain('进入世迈仓库')
    expect(link.attributes('href')).toBe('https://oms.xlwms.com/login')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
  })

  it('renders the ShipOut production portal for MorePlus in the same heading', () => {
    const wrapper=mountPage({ warehouseKey:'us-moreplus', warehouse:warehouseOptions[0], view:'pending' })
    const link=wrapper.find('.oversea-heading .warehouse-portal-link')
    expect(link.text()).toContain('进入 ShipOut 正式仓库')
    expect(link.attributes('href')).toBe('https://oms.shipout.com/z/#/login')
  })

  it('hides the portal on the all-warehouse page or unsupported warehouse', () => {
    expect(mountPage({warehouseKey:'all',warehouse:null}).find('.warehouse-portal-link').exists()).toBe(false)
    expect(mountPage({warehouseKey:'us-ny',warehouse:{key:'us-ny',id:3,code:'US-NY',label:'美东纽约仓',carrier:'USPS',url:'/logistics/oversea/us-ny'}}).find('.warehouse-portal-link').exists()).toBe(false)
  })
})

describe('Oversea Dede file exchange workbench', () => {
  beforeEach(() => { routerPost.mockClear(); routerPatch.mockClear(); routerVisit.mockClear() })

  it('renders the unified read-only status column in the warehouse table', () => {
    const wrapper = mountPage({ rows: { ...usSmPendingRows, data:[statusRow] } })
    expect(wrapper.findAll('th').some((header) => header.text().includes('统一状态'))).toBe(true)
    expect(wrapper.find('.order-workflow-status').text()).toContain('已发货')
    expect(wrapper.find('.order-workflow-status .mvp-status').attributes('title')).toContain('只读投影')
  })

  it('enables inline progress editing only for rows granted orders edit scope, preserving logistics actions', () => {
    const editable = { ...usSmPendingRows.data[0], workflow_revision: 0, can_edit_workflow_status: true,
      workflow_status_projection: {code:'sending',en:'Sending',zh:'待发',color:'neutral',source:'canonical_projection',mutable:true} }
    const options = [
      {code:'sending',en:'Sending',zh:'待发',color:'neutral',mutable:true},
      {code:'creating',en:'Creating',zh:'创作沟通',color:'neutral',mutable:true},
    ]
    const page = mountPage({ rows: {...usSmPendingRows,data:[editable]}, workflowStatusOptions: options })
    expect(page.find('select[aria-label^="订单工作状态"]').exists()).toBe(true)
    expect(page.find('select[aria-label^="订单工作状态"] option').text()).toContain('1.1 Sending')
    expect(page.text()).toContain('下载仓库下单表')
    const restricted = mountPage({ rows: {...usSmPendingRows,data:[{...editable,can_edit_workflow_status:false}]}, workflowStatusOptions: options })
    expect(restricted.find('select[aria-label^="订单工作状态"]').exists()).toBe(false)
    expect(restricted.find('.order-workflow-status').text()).toContain('待发')
  })

  it('exposes the real XLSX round-trip flow and provider evidence', () => {
    const wrapper = mountPage()
    expect(wrapper.find('a[href="/logistics/oversea/us-moreplus/export"]').text()).toContain('下载仓库下单表')
    expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('.xlsx,.csv,.txt')
    expect(wrapper.text()).toContain('上传物流回传表')
    expect(wrapper.text()).toContain('上传当前仓库真实回传 XLSX')
    expect(wrapper.text()).toContain('USPS Ground Advantage')
    expect(wrapper.text()).toContain('Pending')
    expect(wrapper.text()).toContain('ERP确认时间')
    expect(wrapper.text()).toContain('确认导入并标记已发货')
    expect(wrapper.text()).toContain('不会阻止发货')
  })

  it('keeps 3SM file return disabled and exposes manual confirm after tracking entry', async () => {
    const wrapper = mountPage({ warehouseKey: 'us-3sm', warehouse: warehouseOptions[1], trackingImportPreview: null, rows: usSmPendingRows })
    expect(wrapper.text()).not.toContain('上传物流回传表')
    expect(wrapper.text()).toContain('审核通过后，下载订单文件')
    expect(wrapper.text()).toContain('确认发货')
    expect(wrapper.find('a[href="/logistics/oversea/us-3sm/export"]').text()).toContain('批量下载订单文件')
    expect(wrapper.find('a.xlwms-manual-download').attributes('href')).toBe('/logistics/oversea/us-3sm/orders/99/manual-export')

    const input = wrapper.find('input.tracking-input')
    await input.setValue('9400111899223856928599')
    const button = wrapper.findAll('button').find(button => button.text() === '确认发货')
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith(
      '/logistics/oversea/us-3sm/orders/99/manual-ship',
      { tracking_number: '9400111899223856928599' },
      { preserveScroll: true },
    )
  })

  it('allows manual ERP + AfterShip confirmation even when old OMS was canceled', async () => {
    const oldOms = { state:'draft_cancelled_confirmed', oms_order_no:'OBS-OLD',
      oms_status_code:'4', oms_status_checked_at:null, oms_tracking_no:null,
      failure_code:null, oms_status_failure_code:null }
    const page = mountPage({ warehouseKey:'us-3sm', warehouse:warehouseOptions[1],
      trackingImportPreview:null, rows:{ ...usSmPendingRows, data:[{ ...usSmPendingRows.data[0], xlwms:oldOms }] } })
    await page.find('input.tracking-input').setValue('1Z4X98060309743276')
    await page.find('button.manual-ship-button').trigger('click')
    expect(routerPost).toHaveBeenCalledWith(
      '/logistics/oversea/us-3sm/orders/99/manual-ship',
      { tracking_number:'1Z4X98060309743276' },
      { preserveScroll:true }
    )
    expect(page.text()).not.toContain('OMS 已有出库记录：必须先查到')
  })

  it('keeps older bulk OMS dispatch hidden while showing a single operator action', () => {
    const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:usSmPendingRows,xlwmsBatchEnabled:true})
    expect(wrapper.find('button.xlwms-batch-button').exists()).toBe(false)
    expect(wrapper.find('td.xlwms-cell a.xlwms-manual-download').text()).toBe('下载订单文件')
    expect(wrapper.text()).not.toContain('世迈 OMS · API 发货 / 状态')
  })

  it('does not expose warehouse sync to a non-US-SM page or unauthorized operator', () => {
    expect(mountPage({warehouseKey:'us-moreplus'}).find('button.xlwms-batch-button').exists()).toBe(false)
    expect(mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],canOperate:false}).find('button.xlwms-batch-button').exists()).toBe(false)
  })

  it('blocks 3SM manual confirm in the browser until tracking exists', async () => {
    const wrapper = mountPage({ warehouseKey: 'us-3sm', warehouse: warehouseOptions[1], trackingImportPreview: null, rows: usSmPendingRows })
    const button = wrapper.findAll('button').find(button => button.text() === '确认发货')
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).not.toHaveBeenCalledWith(
      '/logistics/oversea/us-3sm/orders/99/manual-ship',
      expect.anything(),
      expect.anything(),
    )
    expect(wrapper.text()).toContain('请先填写真实 Tracking')
  })



  it('uses manual order download regardless of OMS session connection', () => {
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      xlwmsOmsWebEnabled:false,rows:usSmPendingRows,trackingImportPreview:null})
    const cell=page.find('td.xlwms-cell')
    expect(cell.text()).toContain('人工导入 OMS')
    const link=cell.find('a.xlwms-manual-download')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/logistics/oversea/us-3sm/orders/99/manual-export')
    expect(link.text()).toBe('下载订单文件')
    expect(page.text()).toContain('OMS 人工导入草稿')
    expect(page.text()).not.toContain('一键下发草稿')
    expect(page.find('button.xlwms-batch-button').exists()).toBe(false)
  })

  it('keeps history collapsed and offers a new download even after earlier canceled OMS', () => {
    const row={...usSmPendingRows.data[0],xlwms:{
      state:'draft_cancelled_confirmed',oms_order_no:'OBS-CANCELLED',oms_status_code:'4',
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:null,oms_status_failure_code:null,
    }}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.find('a.xlwms-manual-download').text()).toBe('重新下载订单文件')
    expect(cell.find('details.xlwms-history').exists()).toBe(true)
    expect(cell.find('details.xlwms-history').attributes('open')).toBeUndefined()
    expect(cell.text()).toContain('导入前先查看当前草稿箱')
    expect(cell.text()).not.toContain('申请审核')
  })

  it('uses the same manual export when historical ERP shipment was shipped', () => {
    const row={...usSmPendingRows.data[0],shipment_status:'shipped',web_draft:{
      state:'oms_draft_created',oms_order_no:'OBS-LAST',attempt_ref:'BYL-WEB-OLD',failure_code:null,
    }}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.find('a.xlwms-manual-download').text()).toBe('重新下载订单文件')
    expect(cell.find('a.xlwms-manual-download').attributes('href')).toContain('/manual-export')
    expect(page.text()).not.toContain('世迈仓尚未连接')
  })

  it('does not use browser API and never exposes automatic dispatch control', () => {
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:usSmPendingRows,xlwmsOmsWebEnabled:true})
    const cell=page.find('td.xlwms-cell')
    expect(cell.find('button.xlwms-action.primary').exists()).toBe(false)
    expect(cell.find('a.xlwms-manual-download').exists()).toBe(true)
    expect(page.text()).not.toContain('正在检查草稿箱…')
    expect(page.text()).toContain('查看仓库草稿箱')
  })

  it('allows unified review actions from all overseas warehouses', async () => {
    const reviewRows = { data: [{
      line: 1, order_id: 77, erp_order_id: '007-OVERSEA-ALL-77', order_url: '/orders/77', recipient_name: 'Recipient', country: 'US',
      warehouse_id: 1, warehouse_name: '美西 MorePlus 仓', carrier: 'USPS', service: null, carrier_selection_source: 'automatic',
      tracking_number: null, progress: 'pending_review', note: null, review_status: 'pending' as const, shipment_status: 'pending', items: [{ sku: 'SKU-77', quantity: 1 }],
    }], current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 }
    const wrapper = mountPage({ warehouseKey: 'all', warehouse: null, view: 'review', rows: reviewRows, trackingImportPreview: null, canReview: true, canOperate: false })
    expect(wrapper.text()).toContain('通过')
    expect(wrapper.text()).toContain('驳回')
    expect(wrapper.find('select.cell-select.carrier').attributes('disabled')).toBeDefined()
    expect(wrapper.find('select.cell-select.service').attributes('disabled')).toBeDefined()
    expect(wrapper.find('input.tracking-input').attributes('disabled')).toBeDefined()
    const approve = wrapper.findAll('button').find(button => button.text() === '通过')
    await approve!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/oversea/orders/77/approve', { note: null }, { preserveScroll: true, preserveState: true })
  })

  it('uses canonical compact pagination and per-page / quick-jump controls', async () => {
    const wrapper = mountPage({
      rows: { data: [], current_page: 18, last_page: 36, per_page: 50, total: 1800, from: 851, to: 900 },
      trackingImportPreview: null,
    })
    expect(wrapper.text()).toContain('共 1800 条')
    expect(wrapper.text()).toContain('第 18 / 36 页')
    expect(wrapper.text()).toContain('851–900 / 1800')
    expect(wrapper.text()).toContain('首页')
    expect(wrapper.text()).toContain('末页')
    expect(wrapper.findAll('.page-ellipsis').length).toBeGreaterThan(0)
    expect(wrapper.findAll('.pagination button').length).toBeLessThan(15)
    const pageSize = wrapper.find('.page-size select')
    expect(pageSize.findAll('option').map(option => option.text())).toEqual(['20','50','100'])
    await pageSize.setValue('100')
    expect(routerVisit).toHaveBeenCalledWith('/logistics/oversea/us-moreplus?view=pending&page=1&per_page=100', { preserveScroll: true, preserveState: true })

    const jump = wrapper.find('input[aria-label="跳转页码"]')
    await jump.setValue('30')
    await jump.trigger('keyup.enter')
    expect(routerVisit).toHaveBeenCalledWith('/logistics/oversea/us-moreplus?view=pending&page=30&per_page=50', { preserveScroll: true, preserveState: true })
  })

  it('keeps the review decision column sticky and exposes canonical column settings', async () => {
    const wrapper = mountPage({ trackingImportPreview: null })
    expect(wrapper.find('th.sticky-decision').text()).toBe('审核')
    const trigger = wrapper.find('button[aria-label="列设置"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')
    expect(wrapper.text()).toContain('列设置')
    expect(wrapper.text()).toContain('订单号')
    expect(wrapper.text()).toContain('Stock 仓库')
  })

  it('applies the frozen preview token through the selected warehouse route', async () => {
    const wrapper = mountPage()
    const button = wrapper.findAll('button').find(button => button.text().includes('确认导入并标记已发货'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/oversea/us-moreplus/tracking-import/apply', { token: 'a'.repeat(64) }, { preserveScroll: true })
  })
  it('shows MorePlus ShipOut inline but does not enable any sandbox write by default', async () => {
    const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],
      warehouse_id:1,warehouse_name:'美西 MorePlus 仓',erp_order_id:'007-1029-c99999'}]}
    const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,
      shipoutSandboxReadEnabled:false,shipoutSandboxDispatchEnabled:false})
    expect(wrapper.text()).toContain('MorePlus ShipOut · API / 物流')
    expect(wrapper.text()).toContain('API 发货未开通')
    expect(wrapper.find('[data-shipout-order-id="99"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('当前请继续使用')
    expect(wrapper.findAll('button').some(b=>b.text()==='确认仅沙盒推送')).toBe(false)
    expect(wrapper.find('[data-shipout-order-id="99"] button').exists()).toBe(false)
  })

  it('MorePlus preflight renders server blocker reasons and never submits a write', async () => {
    const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],warehouse_id:1,
      warehouse_name:'美西 MorePlus 仓',erp_order_id:'007-1029-c99999'}]}
    const fetchMock=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      order_id:99,ship_date:'2026-10-30',order_no:'C99999-007',items:[{omsSku:'UAT',qty:1}],
      blockers:['SANDBOX_DISPATCH_DISABLED'],ready:false,payload_hash:null,already_attempted:false,
    })})
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,shipoutSandboxDispatchEnabled:true})
      await wrapper.find('[data-shipout-order-id="99"] button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('沙盒推送未启用'))
      expect(wrapper.text()).not.toContain('确认仅沙盒推送')
      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0][0]).toContain('/us-moreplus/orders/99/shipout/preflight')
      expect(fetchMock.mock.calls[0][1].method).toBe('GET')
    } finally { vi.unstubAllGlobals() }
  })

  it('MorePlus allows a single sandbox send only for a matching whitelisted order after preflight', async () => {
    const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],warehouse_id:1,
      warehouse_name:'美西 MorePlus 仓',erp_order_id:'007-1029-c99999'}]}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="SHIPOUT-TEST-CSRF">')
    const fetchMock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:99,ship_date:'2026-10-30',order_no:'C99999-007',
        items:[{omsSku:'UAT',qty:1}],blockers:[],ready:true,payload_hash:'a'.repeat(64),
        already_attempted:false,
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'acknowledged',result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',provider_order_id:'OMS-SANDBOX-1',
      })})
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,
        shipoutSandboxReadEnabled:true,shipoutSandboxDispatchEnabled:true,
        shipoutSandboxAuthorizedOrderNo:'C99999-007'})
      await wrapper.find('[data-shipout-order-id="99"] button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('确认仅沙盒推送'))
      await wrapper.find('button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('尚不能标记已发货'))
      expect(fetchMock).toHaveBeenCalledTimes(2)
      const [url,options]=fetchMock.mock.calls[1]
      expect(url).toContain('/us-moreplus/orders/99/shipout/send')
      expect(JSON.parse(options.body)).toEqual({
        ship_date:'2026-10-30',shipping_mode:'9',payload_hash:'a'.repeat(64),confirmation:'SUBMIT_US_LA_ORDER_99',
      })
      expect(wrapper.text()).not.toContain('确认仅沙盒推送')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('MorePlus renders provider tracking as read-only and does not write ERP or Woo', async () => {
    const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],warehouse_id:1,
      warehouse_name:'美西 MorePlus 仓',erp_order_id:'007-1029-c99999'}]}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="SHIPOUT-TEST-CSRF">')
    const request=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      state:'ready_for_erp_review',reason:'WAREHOUSE_SHIPPED_WITH_TRACKING',
      order_no:'C99999-007',tracking_number:'9400TESTTRACK',
      shipped_time_utc:'2026-10-29 10:00:00',carrier_id:1,read_only:true,
      erp_shipment_unchanged:true,woo_unchanged:true,
    })})
    vi.stubGlobal('fetch',request)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,
        shipoutSandboxReadEnabled:true})
      const button=wrapper.findAll('button').find(b=>b.text()==='查询物流状态')
      expect(button).toBeTruthy()
      await button!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('9400TESTTRACK'))
      expect(wrapper.text()).toContain('未同步 Woo')
      expect(request).toHaveBeenCalledTimes(1)
      expect(request.mock.calls[0][1].method).toBe('POST')
      expect(routerPost).not.toHaveBeenCalled()
      expect(routerVisit).not.toHaveBeenCalled()
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('MorePlus tracking apply requires a new server-confirmed shipped state and explicit Dev flag', async () => {
    const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],
      warehouse_id:1,warehouse_name:'美西 MorePlus 仓'}]}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="SHIPOUT-TEST-CSRF">')
    const signedReadback={
      state:'ready_for_erp_review',reason:'WAREHOUSE_SHIPPED_WITH_TRACKING',
      order_no:'C99999-007',tracking_number:'9400PROVIDERCONFIRMED',
      shipped_time_utc:'2026-10-29 10:00:00',carrier_id:1,
      read_only:true,erp_shipment_unchanged:true,woo_unchanged:true,
    }
    const api=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>signedReadback})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'erp_shipment_updated',tracking_number:'9400PROVIDERCONFIRMED',woo_sync:'pending_vita',
      })})
    vi.stubGlobal('fetch',api)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,
        shipoutSandboxReadEnabled:true,shipoutSandboxTrackingApplyEnabled:true})
      const refresh=wrapper.findAll('button').find(b=>b.text()==='查询物流状态')
      await refresh!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('9400PROVIDERCONFIRMED'))
      const apply=wrapper.findAll('button').find(b=>b.text()==='确认 Tracking 回写 ERP')
      expect(apply).toBeTruthy()
      await apply!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('Vita 仍需同步 WooCommerce'))
      expect(api).toHaveBeenCalledTimes(2)
      expect(api.mock.calls[1][0]).toContain('/us-moreplus/orders/99/shipout/apply')
      expect(JSON.parse(api.mock.calls[1][1].body)).toEqual({
        tracking_number:'9400PROVIDERCONFIRMED',confirmation:'APPLY_US_LA_TRACKING_99',
      })
      expect(routerPost).not.toHaveBeenCalled()
      expect(routerVisit).not.toHaveBeenCalled()
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })



  it('keeps overseas batch sync contextual and removes the duplicate header action', async () => {
    const shipped={...usSmPendingRows.data[0],source_type:'woocommerce',tracking_sync_status:'pending',
      shipment_status:'shipped',tracking_number:'TRACK-USSM-1',shipped_at:'2026-10-29T00:00:00Z'}
    const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      canTrackingSync:true,trackingImportPreview:null,rows:{...usSmPendingRows,data:[shipped]}})
    expect(wrapper.text()).toContain('同步官网物流')
    expect(wrapper.find('.oversea-file-actions button').text()).not.toContain('同步官网物流')
    expect(wrapper.find('button.xlwms-batch-button').exists()).toBe(false)
    await wrapper.find('input[aria-label^="选择同步"]').setValue(true)
    const batch=wrapper.findAll('button').find(b=>b.text()==='批量同步官网物流')
    expect(batch).toBeTruthy()
    await batch!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/tracking-outbound/selected',
      {order_ids:[99],manual_override:false},expect.objectContaining({preserveScroll:true}))
    expect(mountPage({canTrackingSync:false}).text()).not.toContain('同步官网物流')

    const conflict={...shipped,tracking_sync_status:'conflict',tracking_sync_reason:'官网已有相同 Tracking，但承运商不一致'}
    const conflictWrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      canTrackingSync:true,trackingImportPreview:null,rows:{...usSmPendingRows,data:[conflict]}})
    expect(conflictWrapper.text()).toContain('冲突待核对')
    expect(conflictWrapper.find('input[aria-label^="选择同步"]').exists()).toBe(false)
    expect(conflictWrapper.findAll('tbody button').filter(b=>b.text().includes('同步官网物流'))).toHaveLength(0)
  })

  it('MorePlus specified service group re-preflights mode 10 and binds send to it', async () => {
    const rows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],
      warehouse_id:1,warehouse_name:'美西 MorePlus 仓'}]}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="SHIPOUT-TEST-CSRF">')
    const mock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:99,ship_date:'2026-10-30',shipping_mode:'10',order_no:'C99999-007',
        items:[{omsSku:'UAT',qty:1}],blockers:[],ready:true,payload_hash:'b'.repeat(64),
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'acknowledged',result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',
      })})
    vi.stubGlobal('fetch',mock)
    try {
      const wrapper=mountPage({rows,trackingImportPreview:null,
        shipoutSandboxReadEnabled:true,shipoutSandboxDispatchEnabled:true,
        shipoutSandboxAuthorizedOrderNo:'C99999-007',
        shipoutSandboxSpecifiedChannel:{id:'1993981773496696833',label:'USPS GA'}})
      const select=wrapper.find('[data-shipout-order-id="99"] select')
      expect(select.exists()).toBe(true)
      await select.setValue('10')
      await wrapper.find('[data-shipout-order-id="99"] button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('确认仅沙盒推送'))
      expect(mock.mock.calls[0][0]).toContain('shipout/preflight?mode=10')
      await wrapper.find('button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(mock).toHaveBeenCalledTimes(2))
      expect(JSON.parse(mock.mock.calls[1][1].body).shipping_mode).toBe('10')
      expect(JSON.parse(mock.mock.calls[1][1].body).payload_hash).toBe('b'.repeat(64))
      expect(wrapper.text()).not.toContain('确认仅沙盒推送')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('shows warehouse actual carrier and time but suppresses ERP apply for synthetic mock tracking', async () => {
    const rows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],
      warehouse_id:1,warehouse_name:'美西 MorePlus 仓'}]}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="SHIPOUT-TEST-CSRF">')
    const mock=vi.fn().mockResolvedValueOnce({ok:true,json:async()=>({
      state:'ready_for_erp_review',reason:'WAREHOUSE_SHIPPED_WITH_TRACKING',
      order_no:'C99999-007',tracking_number:'mock_2_TEST',
      carrier_id:2,shipped_time_utc:'2026-10-29 09:40:37',
      read_only:true,erp_shipment_unchanged:true,woo_unchanged:true,
    })})
    vi.stubGlobal('fetch',mock)
    try {
      const wrapper=mountPage({rows,trackingImportPreview:null,
        shipoutSandboxReadEnabled:true,shipoutSandboxTrackingApplyEnabled:true})
      const b=wrapper.findAll('button').find(x=>x.text()==='查询物流状态')
      await b!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('mock_2_TEST'))
      expect(wrapper.text()).toContain('仓库实际物流商：UPS')
      expect(wrapper.text()).toContain('2026-10-29 09:40:37')
      expect(wrapper.text()).toContain('沙盒模拟单号，仅验收 API 状态')
      expect(wrapper.findAll('button').some(b=>b.text()==='确认 Tracking 回写 ERP')).toBe(false)
      expect(mock).toHaveBeenCalledTimes(1)
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })


  it('keeps previous OMS records folded and provides repeat XLSX download', () => {
    const row={...usSmPendingRows.data[0],shipment_status:'shipped',xlwms:{
      state:'batch_existing',oms_order_no:'OBS-OLD',oms_status_code:'5',
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:null,oms_status_failure_code:null,
    },web_draft:{state:'oms_draft_created',oms_order_no:'OBS-LAST',attempt_ref:'BYL-1',failure_code:null}}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.find('a.xlwms-manual-download').text()).toBe('重新下载订单文件')
    expect(cell.find('details.xlwms-history').exists()).toBe(true)
    expect(cell.find('details.xlwms-history').attributes('open')).toBeUndefined()
    expect(cell.find('input.xlwms-reship-reason').exists()).toBe(false)
  })

  it('requires overseas review approval before downloading', () => {
    const row={...usSmPendingRows.data[0],review_status:'pending' as const}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.text()).toContain('审核通过后可下载')
    expect(cell.find('a.xlwms-manual-download').exists()).toBe(false)
  })

})


describe('ShipOut Production V1 isolated from Sandbox', () => {
  const morePlusRows={...usSmPendingRows,data:[{...usSmPendingRows.data[0],warehouse_id:1,
    warehouse_name:'美西 MorePlus 仓',erp_order_id:'007-1031-c99999'}]}

  it('shows the real API integration as safely disabled without emitting a send button', () => {
    const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,shipoutProductionUi:true,
      shipoutProductionReadEnabled:false,shipoutProductionDispatchEnabled:false})
    expect(wrapper.text()).toContain('待发货 · 可预检')
    expect(wrapper.text()).toContain('当前未开通')
    expect(wrapper.text()).not.toContain('确认提交正式仓')
    expect(wrapper.find('[data-shipout-order-id="99"] button').exists()).toBe(false)
  })

  it('supports a real read-only preflight and blocks send when Production dispatch is off', async () => {
    const fetchMock=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      order_id:99,ship_date:'2026-11-02',order_no:'C99999-007',items:[{omsSku:'TEST-SKU',qty:1}],
      shipping_mode:'9',blockers:[],ready:true,payload_hash:'a'.repeat(64),already_attempted:false,
    })})
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,shipoutProductionUi:true,
        shipoutProductionReadEnabled:true,shipoutProductionDispatchEnabled:false})
      const previewButton=wrapper.findAll('button').find(b=>b.text()==='正式 API 预检')
      expect(previewButton).toBeTruthy()
      await previewButton!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('TEST-SKU × 1'))
      expect(wrapper.text()).toContain('正式发货未授权')
      expect(wrapper.text()).not.toContain('确认提交正式仓')
      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0][0]).toContain('/shipout/production/preflight')
      expect(fetchMock.mock.calls[0][1].method).toBe('GET')
    } finally { vi.unstubAllGlobals() }
  })

  it('sends a fake provider order once and does not mark shipped or retry', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="V1-TEST-CSRF">')
    const fetchMock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:99,ship_date:'2026-11-02',order_no:'C99999-007',items:[{omsSku:'TEST-SKU',qty:1}],
        shipping_mode:'9',blockers:[],ready:true,payload_hash:'a'.repeat(64),already_attempted:false,
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'acknowledged',provider_order_id:'SYNTHETIC-ORDER',result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',
      })})
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({rows:morePlusRows,trackingImportPreview:null,shipoutProductionUi:true,
        shipoutProductionReadEnabled:true,shipoutProductionDispatchEnabled:true})
      await wrapper.findAll('button').find(b=>b.text()==='正式 API 预检')!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('确认提交正式仓 · 仅一次'))
      await wrapper.findAll('button').find(b=>b.text().includes('确认提交正式仓'))!.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('API ACK 已受理'))
      expect(fetchMock).toHaveBeenCalledTimes(2)
      const [url,opts]=fetchMock.mock.calls[1]
      expect(url).toContain('/shipout/production/send')
      expect(opts.method).toBe('POST')
      expect(JSON.parse(opts.body)).toEqual({
        ship_date:'2026-11-02',shipping_mode:'9',payload_hash:'a'.repeat(64),
        confirmation:'SUBMIT_SHIPOUT_ORDER_99',
      })
      expect(wrapper.text()).not.toContain('确认提交正式仓 · 仅一次')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })
})


describe('ShipOut Production V1 strict cutover and operator gate', () => {
  const approvedRow={...usSmPendingRows.data[0],
    warehouse_id:1,warehouse_name:'美西 MorePlus 仓',
    erp_order_id:'007-1031-c99999',order_id:99}

  it('excludes pre-cutover orders from API preview or send even when READ is ON', () => {
    const wrapper=mountPage({rows:{...usSmPendingRows,data:[approvedRow]},
      trackingImportPreview:null,shipoutProductionUi:true,shipoutProductionReadEnabled:true,
      shipoutProductionDispatchEnabled:true,shipoutProductionMinOrderId:100})
    expect(wrapper.text()).toContain('历史订单不纳入正式 API V1 自动化范围')
    expect(wrapper.text()).not.toContain('正式 API 预检')
    expect(wrapper.text()).not.toContain('确认提交正式仓')
    expect(wrapper.text()).toContain('查询正式 OMS')
  })

  it('keeps send hidden from a non-approved logistics operator', () => {
    const wrapper=mountPage({rows:{...usSmPendingRows,data:[approvedRow]},
      trackingImportPreview:null,shipoutProductionUi:true,shipoutProductionReadEnabled:true,
      shipoutProductionDispatchEnabled:false,shipoutProductionMinOrderId:1})
    expect(wrapper.text()).toContain('正式 API 预检')
    expect(wrapper.text()).not.toContain('确认提交正式仓')
  })
})

describe('ShipOut Production V1 three-account scoped UI', () => {
  const row={...usSmPendingRows.data[0],warehouse_id:1,
    warehouse_name:'美西 MorePlus 仓',order_id:99}
  const rows={...usSmPendingRows,data:[row]}

  it('shows Vita preview and one-shot send without global logistics.operate', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="V1-VITA-SYNTHETIC">')
    const mock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:99,ship_date:'2026-11-02',order_no:'C99999-007',
        items:[{omsSku:'TX218Y',qty:1}],shipping_mode:'9',
        ready:true,payload_hash:'f'.repeat(64),
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'acknowledged',provider_order_id:'SYNTHETIC-ORDER',
        result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',
      })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({rows,trackingImportPreview:null,
        shipoutProductionUi:true,shipoutProductionReadEnabled:true,
        shipoutProductionDispatchEnabled:true,shipoutProductionOperatorAllowed:true,
        shipoutProductionMinOrderId:1,canOperate:false})
      await page.findAll('button').find(b=>b.text()==='正式 API 预检')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('确认提交正式仓 · 仅一次'))
      await page.findAll('button').find(b=>b.text().includes('确认提交正式仓'))!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('API ACK 已受理'))
      expect(mock).toHaveBeenCalledTimes(2)
      expect(mock.mock.calls[1][1].method).toBe('POST')
      expect(page.text()).not.toContain('确认提交正式仓 · 仅一次')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('hides preflight/send from any staff outside three-account V1 allowlist', () => {
    const page=mountPage({rows,trackingImportPreview:null,
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,
      shipoutProductionDispatchEnabled:false,shipoutProductionOperatorAllowed:false,
      shipoutProductionMinOrderId:1,canOperate:true})
    expect(page.text()).not.toContain('正式 API 预检')
    expect(page.text()).not.toContain('确认提交正式仓')
    expect(page.text()).toContain('查询正式 OMS')
  })
})

describe('R12 six-list overseas dispatch state without duplicate submission', () => {
  it('shows seven tabs including stockout and prominent red anomaly status', () => {
    const anomaly={...usSmPendingRows.data[0],order_id:7903,
      shipout_production:{attempted:true,is_oms_created:false,status:'oms_quarantined',
        label:'EzeeShip 运输服务失败 · 已隔离（禁止自动重发）',provider_order_id:'OMS-ERR-1'},
      warehouse_dispatch:{status:'oms_quarantined',label:'下发异常',
        failure_code:'PROVIDER_EZEESHIP_SHIPPING_ERROR',oms_order_no:'OMS-ERR-1',
        last_checked_at:'2026-11-10T11:00:00Z',detail:'待人工核对',can_retry_local_block:false}}
    const page=mountPage({view:'exceptions',rows:{...usSmPendingRows,data:[anomaly],total:1},
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,canOperate:true})
    expect(page.findAll('button.oversea-tab')).toHaveLength(7)
    expect(page.text()).toContain('缺货单')
    expect(page.text()).toContain('待审核')
    expect(page.text()).toContain('待发货')
    expect(page.text()).toContain('已下发')
    expect(page.text()).not.toContain('待下发 0')
    expect(page.text()).toContain('下发异常 · EzeeShip')
    expect(page.find('[data-shipout-order-id="7903"] [role="alert"]').exists()).toBe(true)
    expect(page.text()).toContain('Dede 核实')
    expect(page.text()).toContain('确认真实出库')
    page.unmount()
  })
  it('confirmed OMS can show Tracking entry but is not marked shipped', () => {
    const confirmed={...usSmPendingRows.data[0],order_id:7963,
      shipout_production:{attempted:true,is_oms_created:true,status:'oms_confirmed',
        label:'已下发 · OMS 两次回读确认（未出库）',provider_order_id:'OMS-OK'},
      warehouse_dispatch:{status:'oms_confirmed',label:'已下发',failure_code:null,
        oms_order_no:'OMS-OK',last_checked_at:'2026-11-10T11:00:00Z',
        detail:'',can_retry_local_block:false}}
    const page=mountPage({view:'issued',rows:{...usSmPendingRows,data:[confirmed],total:1},
      shipoutProductionUi:true,canOperate:true})
    expect(page.text()).toContain('已下发 · OMS 已核实（未实际出库）')
    expect(page.find('input[placeholder="填写真实 Tracking"]').exists()).toBe(true)
    expect(page.text()).toContain('确认真实出库')
    expect(page.text()).not.toContain('已发货 · 同步官网成功')
    page.unmount()
  })
})

describe('R13 official ShipOut status 4, shipment awaiting reconciliation', () => {
  it('shows provider shipped while ERP remains pending and demands real Tracking', () => {
    const row={...usSmPendingRows.data[0],order_id:7952,review_status:'approved' as const,
      shipment_status:'pending',tracking_number:null,
      shipout_production:{attempted:true,is_oms_created:true,
        status:'oms_shipped_pending_tracking',
        label:'OMS 已发货 · Tracking 待回写 ERP',provider_order_id:'OMS-7952'},
      warehouse_dispatch:{status:'oms_shipped_pending_tracking',
        label:'OMS 已发货 · Tracking 待回写 ERP',
        detail:'须读取真实 Tracking',failure_code:null,oms_order_no:'OMS-7952',
        last_checked_at:'2026-11-10T11:00:00Z',can_retry_local_block:false}}
    const page=mountPage({view:'issued',rows:{...usSmPendingRows,data:[row],total:1},
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,canOperate:true})
    expect(page.text()).toContain('OMS 已发货 · Tracking 待回写')
    expect(page.text()).toContain('OMS 状态 4：Shipped')
    expect(page.text()).toContain('确认真实出库')
    expect(page.find('input[placeholder="填写真实 Tracking"]').exists()).toBe(true)
    expect(page.text()).not.toContain('下发异常已隔离')
    expect(page.text()).not.toContain('确认提交正式仓 · 仅一次')
    page.unmount()
  })
})

describe('MorePlus inline JSON editing without full-page reload', () => {
  beforeEach(() => {routerPost.mockClear();routerPatch.mockClear();routerReload.mockClear();routerVisit.mockClear()})
  it('moves USPS remark into Tracking after one JSON save without route reload', async () => {
    const mock=vi.spyOn(axios,'patch').mockResolvedValueOnce({data:{
      order_id:8100,shipment_status:'pending',tracking_number:'9434650105794040132223',
      carrier:'USPS',service:'Priority',note:null,auto_shipped:false,
      website_sync:null,tracking_moved:true,message:'已将 USPS 单号自动归位 Tracking'}})
    const row={...usSmPendingRows.data[0],order_id:8100,warehouse_id:1,note:null,tracking_number:null}
    const page=mountPage({view:'issued',rows:{...usSmPendingRows,data:[row]}})
    const note=page.find('.note-input')
    expect(note.attributes('placeholder')).toContain('USPS单号自动转入Tracking')
    await note.setValue('9434650105794040132223')
    await note.trigger('blur')
    await vi.waitFor(()=>expect(mock).toHaveBeenCalledTimes(1))
    expect(mock.mock.calls[0][0]).toBe('/logistics/oversea/orders/8100/draft')
    expect(mock.mock.calls[0][1]).toEqual({note:'9434650105794040132223'})
    await vi.waitFor(()=>expect((page.find('.tracking-input').element as HTMLInputElement).value).toBe('9434650105794040132223'))
    expect((page.find('.note-input').element as HTMLInputElement).value).toBe('')
    expect(routerPatch).not.toHaveBeenCalled()
    expect(routerReload).not.toHaveBeenCalled()
    expect(routerVisit).not.toHaveBeenCalled()
    page.unmount();mock.mockRestore()
  })
  it('saves a normal note inline without returning a new page', async () => {
    const mock=vi.spyOn(axios,'patch').mockResolvedValueOnce({data:{
      order_id:8101,shipment_status:'pending',tracking_number:null,
      carrier:'USPS',service:'Priority',note:'请先核对仓库',auto_shipped:false,
      website_sync:null,tracking_moved:false,message:'字段已保存，无需刷新页面'}})
    const row={...usSmPendingRows.data[0],order_id:8101,warehouse_id:1,note:null}
    const page=mountPage({view:'issued',rows:{...usSmPendingRows,data:[row]}})
    await page.find('.note-input').setValue('请先核对仓库')
    await page.find('.note-input').trigger('blur')
    await vi.waitFor(()=>expect(mock).toHaveBeenCalledTimes(1))
    expect(mock.mock.calls[0][1]).toEqual({note:'请先核对仓库'})
    expect(routerPatch).not.toHaveBeenCalled()
    expect(routerReload).not.toHaveBeenCalled()
    page.unmount();mock.mockRestore()
  })
  it('automatically removes truly shipped row and adjusts two tab counters locally', async () => {
    const mock=vi.spyOn(axios,'patch').mockResolvedValueOnce({data:{
      order_id:8102,shipment_status:'shipped',tracking_number:'9434650105794040132223',
      carrier:'USPS',service:'Priority',note:null,auto_shipped:true,
      website_sync:'pending',tracking_moved:false,message:'ERP 已发货'}})
    const row={...usSmPendingRows.data[0],order_id:8102,warehouse_id:1,note:null,tracking_number:null}
    const page=mountPage({view:'issued',rows:{...usSmPendingRows,data:[row],total:1},
      viewCounts:{review:0,pending:0,issued:1,shipped:591,exceptions:0,all:592}})
    await page.find('.tracking-input').setValue('9434650105794040132223')
    await page.find('.tracking-input').trigger('blur')
    await vi.waitFor(()=>expect(mock).toHaveBeenCalledTimes(1))
    await vi.waitFor(()=>expect(page.text()).toContain('已发货 592'))
    expect(page.text()).toContain('已下发 0')
    expect(page.find('.note-input').exists()).toBe(false)
    expect(routerReload).not.toHaveBeenCalled()
    expect(routerVisit).not.toHaveBeenCalled()
    page.unmount();mock.mockRestore()
  })
})
describe('MorePlus Production ACK pending lifecycle without a second POST', () => {
  const attempt={attempted:true,is_oms_created:false,status:'oms_ack_pending',
    label:'API 已受理 · 待 OMS 核实（禁止重发）',provider_order_id:'2116863614032625665'}
  const row={...usSmPendingRows.data[0],warehouse_id:1,
    warehouse_name:'美西 MorePlus 仓',order_id:7791,
    erp_order_id:'007-1030-c41941',shipout_production:attempt}

  it('renders persisted OMS-created state after reload; never offers second send', () => {
    const page=mountPage({
      view:'dispatched',rows:{...usSmPendingRows,data:[row]},trackingImportPreview:null,
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,shipoutProductionDispatchEnabled:true,
      shipoutProductionOperatorAllowed:true,shipoutProductionMinOrderId:7791,
      viewCounts:{review:0,pending:2,issued:0,shipped:577,exceptions:0,all:580},
    })
    expect(page.text()).toContain('下发中 · 禁止重新提交')
    expect(page.text()).toContain('2116863614032625665')
    expect(page.text()).toContain('尚未证实 OMS 有效接单')
    expect(page.text()).not.toContain('确认提交正式仓')
    expect(page.text()).not.toContain('正式 API 预检')
    expect(page.findAll('button').some(b=>b.text()==='查询正式 OMS')).toBe(true)
    expect(page.text()).toContain('待发货 2')
  })

  it('marks unresolved one-shot dispatch for reconciliation, not resubmission', () => {
    const page=mountPage({
      view:'exceptions',rows:{...usSmPendingRows,data:[{
        ...row,shipout_production:{attempted:true,is_oms_created:false,
          status:'requires_reconciliation',label:'提交结果待核对 · 禁止重发',provider_order_id:null},
      }]},trackingImportPreview:null,
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,
      shipoutProductionDispatchEnabled:true,shipoutProductionOperatorAllowed:true,
      shipoutProductionMinOrderId:7791,
    })
    expect(page.text()).toContain('提交结果待人工核对')
    expect(page.text()).toContain('禁止再次下发')
    expect(page.text()).not.toContain('正式 API 预检')
    expect(page.text()).not.toContain('确认提交正式仓')
  })

  it('uses UI navigation only after ACK; no second API submission', async () => {
    routerVisit.mockClear()
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="OMS-STATE-TEST">')
    const mock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:7791,ship_date:'2026-11-02',order_no:'C41941-007',
        items:[{omsSku:'TX218Y',qty:1}],shipping_mode:'9',
        ready:true,payload_hash:'f'.repeat(64),
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        status:'acknowledged',provider_order_id:'2116863614032625665',
        result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',
      })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({
        rows:{...usSmPendingRows,data:[{...row,shipout_production:null}]},
        trackingImportPreview:null,shipoutProductionUi:true,shipoutProductionReadEnabled:true,
        shipoutProductionDispatchEnabled:true,shipoutProductionOperatorAllowed:true,
        shipoutProductionMinOrderId:7791,
      })
      await page.findAll('button').find(b=>b.text()==='正式 API 预检')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('确认提交正式仓 · 仅一次'))
      await page.findAll('button').find(b=>b.text().includes('确认提交正式仓'))!.trigger('click')
      await vi.waitFor(()=>expect(routerReload).toHaveBeenCalledWith(
        expect.objectContaining({only:['rows','viewCounts']})))
      expect(routerVisit).not.toHaveBeenCalledWith(
        '/logistics/oversea/us-moreplus?view=dispatched',expect.any(Object))
      expect(mock).toHaveBeenCalledTimes(2)
      expect(mock.mock.calls[1][1].method).toBe('POST')
      expect(page.text()).not.toContain('确认提交正式仓 · 仅一次')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })
})


describe('Production ShipOut cancelled OMS readback is an explicit shipping blocker', () => {
  it('shows cancellation alert and never offers resubmit or tracking sync after status 6', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="OMS-CANCELLED-READONLY-SYNTHETIC">')
    const row={...usSmPendingRows.data[0],order_id:7791,warehouse_id:1,
      warehouse_name:'美西 MorePlus 仓',
      shipout_production:{attempted:true,is_oms_created:true,status:'oms_created',
        label:'OMS 已建单 · 待实际出库',provider_order_id:'2116863614032625665'}}
    const mock=vi.fn().mockResolvedValueOnce({ok:true,json:async()=>({
      order_id:7791,order_no:'C41941-007',attempt_status:'acknowledged',
      provider_order_id:'2116863614032625665',provider_status:6,
      reconciliation:'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW',
      provider_failure_code:'PROVIDER_DUPLICATE_ORDER',
      shipped:false,tracking_apply:false,read_only:true,
    })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({view:'dispatched',rows:{...usSmPendingRows,data:[row]},
        trackingImportPreview:null,shipoutProductionUi:true,shipoutProductionReadEnabled:true,
        shipoutProductionDispatchEnabled:true,shipoutProductionOperatorAllowed:true,
        shipoutProductionMinOrderId:7791})
      await page.findAll('button').find(b=>b.text()==='查询正式 OMS')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('OMS 已取消／中止'))
      expect(page.text()).toContain('禁止重发')
      expect(page.text()).toContain('不能同步 Tracking 或标记已发货')
      expect(page.text()).not.toContain('OMS：仓库订单可见')
      expect(page.text()).not.toContain('确认提交正式仓')
      expect(mock).toHaveBeenCalledTimes(1)
      expect(mock.mock.calls[0][0]).toContain('/shipout/production/status')
      expect(routerPost).not.toHaveBeenCalledWith(expect.stringContaining('tracking-outbound'),expect.anything(),expect.anything())
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })
})

describe('MorePlus async production acceptance is not final business success', () => {
  it('shows queued readback as pending, provider cancellation as blocked, and never says success', async () => {
    const mock=vi.fn().mockResolvedValueOnce({ok:true,json:async()=>({
      batch_id:'00000000-0000-4000-8000-000000000098',status:'awaiting_oms',
      dry_run:false,total:2,processed:1,
      items:[
        {order_id:7913,order_no:'007-1106-c42149',status:'provider_cancelled',
          result_code:'PROVIDER_DUPLICATE_ORDER',provider_order_id:'OMS-CANCEL-1'},
        {order_id:7914,order_no:'007-1106-c42148',status:'pending_oms',
          result_code:'ASYNC_ACCEPTED_QUERY_REQUIRED',provider_order_id:'OMS-ACK-2'},
      ],
    })})
    vi.stubGlobal('fetch',mock)
    try {
      const rendered=mountPage({shipoutProductionBatchUi:true,trackingImportPreview:null,
        shipoutProductionLatestBatch:{id:'00000000-0000-4000-8000-000000000098',
          status:'awaiting_oms',dry_run:false,total:2}})
      await vi.waitFor(()=>expect(rendered.text()).toContain('仓库异步判定重复订单'))
      expect(rendered.text()).toContain('OMS 异步核实中')
      expect(rendered.text()).toContain('等待正式 OMS 异步回读')
      expect(rendered.text()).toContain('已核实 1 / 2')
      expect(rendered.text()).not.toContain('OMS 已接单 ·')
      rendered.unmount()
    } finally {
      vi.unstubAllGlobals()
    }
  })
})

describe('MorePlus R9 Dede manual fallback and no duplicate resubmission', () => {
  it('shows confirmed manual outbound action only for attempted nonduplicate rows', () => {
    const row={...usSmPendingRows.data[0],order_id:7903,
      review_status:'approved' as const,shipment_status:'pending',
      shipout_production:{attempted:true,is_oms_created:false,status:'oms_quarantined',
        label:'EzeeShip异常 · 交 Dede 人工',provider_order_id:'OMS-EXISTING'},
      warehouse_dispatch:{status:'oms_quarantined',label:'EzeeShip异常',
        failure_code:'PROVIDER_EZEESHIP_SHIPPING_ERROR',
        detail:'',oms_order_no:'OMS-EXISTING',last_checked_at:null,
        can_retry_local_block:false}}
    const page=mountPage({warehouseKey:'us-moreplus',view:'exceptions',canOperate:true,
      shipoutProductionUi:true,rows:{...usSmPendingRows,total:1,data:[row]}})
    expect(page.text()).toContain('确认真实出库')
    expect(page.text()).toContain('先核对既有 OMS 原单')
    expect(page.find('input[placeholder="Dede 核实的人工出库单号"]').exists()).toBe(true)
    page.unmount()
  })
  it('hides manual action for an OMS-confirmed duplicate order', () => {
    const row={...usSmPendingRows.data[0],order_id:7913,
      review_status:'approved' as const,shipment_status:'pending',
      shipout_production:{attempted:true,is_oms_created:false,status:'oms_quarantined',
        label:'海外仓判定重复订单',provider_order_id:'OMS-DUP'},
      warehouse_dispatch:{status:'oms_quarantined',label:'海外仓判定重复订单',
        failure_code:'PROVIDER_DUPLICATE_ORDER',
        detail:'',oms_order_no:'OMS-DUP',last_checked_at:null,
        can_retry_local_block:false}}
    const page=mountPage({warehouseKey:'us-moreplus',view:'exceptions',canOperate:true,
      shipoutProductionUi:true,rows:{...usSmPendingRows,total:1,data:[row]}})
    expect(page.text()).not.toContain('确认真实出库')
    expect(page.find('input[placeholder="Dede 核实的人工出库单号"]').exists()).toBe(false)
    page.unmount()
  })
})

describe('MorePlus per-order quarantine and partial batch completion', () => {
  it('shows quarantined order and blocks another one-shot send while an unrelated order remains eligible', () => {
    const failed={...usSmPendingRows.data[0],warehouse_id:1,order_id:7913,
      shipout_production:{attempted:true,is_oms_created:false,status:'oms_quarantined',
        label:'海外仓判定重复订单 · 已隔离（禁止自动重发）',provider_order_id:'OMS-FAILED-7913'}}
    const healthy={...usSmPendingRows.data[0],warehouse_id:1,order_id:7914,
      erp_order_id:'007-1110-c42150',shipout_production:null}
    const page=mountPage({view:'exceptions',
      rows:{...usSmPendingRows,data:[failed,healthy],total:2},
      shipoutProductionUi:true,shipoutProductionReadEnabled:true,
      shipoutProductionDispatchEnabled:true,shipoutProductionOperatorAllowed:true})
    expect(page.text()).toContain('下发异常已隔离')
    expect(page.text()).toContain('海外仓判定重复订单')
    expect(page.text()).toContain('禁止自动重发')
    expect(page.text()).toContain('正式 API 预检')
    const failedRow=page.find('[data-shipout-order-id="7913"]')
    expect(failedRow.text()).not.toContain('正式 API 预检')
    page.unmount()
  })

  it('shows partial completion without claiming full success', async () => {
    const id='00000000-0000-4000-8000-000000000124'
    const mock=vi.fn().mockResolvedValueOnce({ok:true,json:async()=>({
      batch_id:id,status:'completed_partial',dry_run:false,total:2,processed:2,
      items:[
        {order_id:7913,order_no:'007-1110-c42149',status:'provider_cancelled',
          result_code:'PROVIDER_DUPLICATE_ORDER',provider_order_id:'OMS-FAILED'},
        {order_id:7914,order_no:'007-1110-c42150',status:'oms_visible',
          result_code:'OMS_VISIBLE_PENDING_REAL_FULFILLMENT',provider_order_id:'OMS-OK'},
      ],
    })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true,trackingImportPreview:null,
        shipoutProductionLatestBatch:{id,status:'completed_partial',dry_run:false,total:2}})
      await vi.waitFor(()=>expect(page.text()).toContain('本批次结束 · 异常单已隔离'))
      expect(page.text()).toContain('已核实 2 / 2')
      expect(page.text()).toContain('OMS 已取消／中止')
      expect(page.text()).toContain('OMS 订单可见 · 待实际出库')
      page.unmount()
    } finally { vi.unstubAllGlobals() }
  })
})

describe('MorePlus Dev-only one-click batch workbench', () => {
  it('shows the top-right bulk button only when the separate feature flag is enabled', () => {
    expect(mountPage().findAll('button').some(b=>b.text()==='一键推送仓库')).toBe(false)
    expect(mountPage({shipoutProductionBatchUi:true}).findAll('button').some(b=>b.text()==='一键推送仓库')).toBe(true)
    expect(mountPage({shipoutProductionBatchUi:true,warehouseKey:'us-3sm',warehouse:warehouseOptions[1]})
      .findAll('button').some(b=>b.text()==='一键推送仓库')).toBe(false)
  })

  it('allows a new batch after completed_partial and blocks one while paused', async () => {
    const id='00000000-0000-4000-8000-000000000098'
    const statuses=['completed_partial','paused'] as const
    for (const status of statuses) {
      const mock=vi.fn().mockResolvedValue({
        ok:true,json:async()=>({batch_id:id,status,dry_run:false,total:1,processed:1,
          items:[{order_id:7913,order_no:'007-1106-c42149',status:'provider_cancelled',
            result_code:'PROVIDER_DUPLICATE_ORDER',provider_order_id:'OMS-CANCEL-1'}]}),
      })
      vi.stubGlobal('fetch',mock)
      try {
        const page=mountPage({shipoutProductionBatchUi:true,
          shipoutProductionLatestBatch:{id,status,dry_run:false,total:1}})
        await vi.waitFor(()=>expect(page.text()).toContain('仓库异步判定重复订单'))
        const button=page.findAll('button').find(b=>b.text()==='一键推送仓库')!
        expect(button.attributes('disabled')===undefined).toBe(status==='completed_partial')
        page.unmount()
      } finally {vi.unstubAllGlobals()}
    }
  })

  it('shows admin the paused one-click button with read-only preview and prevents POST', async () => {
    const mock=vi.fn().mockResolvedValue({
      ok:true,json:async()=>({count:1,overflow:false,dry_run:false,fingerprint:'a'.repeat(64)}),
    })
    vi.stubGlobal('fetch',mock)
    try {
      const approveAll=vi.spyOn(window,'confirm').mockReturnValue(true)
      const page=mountPage({shipoutProductionBatchUi:true,shipoutProductionBatchPaused:true,
        trackingImportPreview:null})
      expect(page.text()).toContain('一键推送仓库')
      expect(page.text()).toContain('管理员可查看预览与历史结果，但不可提交新订单')
      await page.findAll('button').find(b=>b.text()==='一键推送仓库')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('待逐单校验 1 单'))
      const send=page.findAll('button').find(b=>b.text().includes('确认逐单推送'))
      expect(send?.attributes('disabled')).toBeDefined()
      await send!.trigger('click')
      expect(mock.mock.calls.map(c=>c[0])).toEqual([
        '/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all',
      ])
      page.unmount()
      approveAll.mockRestore()
    } finally {
      vi.unstubAllGlobals()
    }
  })

  it('no selection cancel means no HTTP request and no POST', async () => {
    const ask=vi.spyOn(window,'confirm').mockReturnValue(false)
    const fetchMock=vi.fn()
    vi.stubGlobal('fetch',fetchMock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true})
      await page.findAll('button').find(b=>b.text()==='一键推送仓库')!.trigger('click')
      expect(ask).toHaveBeenCalledTimes(1)
      expect(ask.mock.calls[0][0]).toContain('包含缺货订单')
      expect(fetchMock).not.toHaveBeenCalled()
      page.unmount()
    } finally {ask.mockRestore();vi.unstubAllGlobals()}
  })

  it('selected rows persist across page changes and only chosen IDs are previewed', async () => {
    const first={...usSmPendingRows.data[0],order_id:51,warehouse_id:1}
    const second={...first,order_id:52,erp_order_id:'007-SECOND'}
    const mock=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      scope:'selected',scan_count:2,excluded_count:0,count:2,overflow:false,
      dry_run:true,fingerprint:'a'.repeat(64),
    })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true,
        rows:{...usSmPendingRows,total:2,last_page:2,data:[first]}})
      await page.find('input[aria-label^="选择推送"]').setValue(true)
      await page.setProps({rows:{...usSmPendingRows,total:2,last_page:2,current_page:2,data:[second]}})
      await page.find('input[aria-label^="选择推送"]').setValue(true)
      expect(page.text()).toContain('已勾选 2 单（跨页保留）')
      await page.findAll('button').find(b=>b.text()==='仅推送所选订单')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('手动选择 扫描 2 单'))
      expect(mock.mock.calls.map(c=>c[0])).toEqual([
        '/logistics/oversea/us-moreplus/shipout/batch/preview?scope=selected&order_ids=51,52',
      ])
      page.unmount()
    } finally {vi.unstubAllGlobals()}
  })

  it('switching out of pending clears hidden selected orders', async () => {
    const item={...usSmPendingRows.data[0],order_id:86,warehouse_id:1}
    const mock=vi.fn()
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true,
        rows:{...usSmPendingRows,data:[item]}})
      await page.find('input[aria-label^="选择推送"]').setValue(true)
      expect(page.text()).toContain('已勾选 1 单')
      await page.findAll('button.oversea-tab').find(b=>b.text().includes('已发货'))!.trigger('click')
      expect(page.text()).not.toContain('已勾选 1 单')
      expect(mock).not.toHaveBeenCalled()
      page.unmount()
    } finally {vi.unstubAllGlobals()}
  })

  it('select current page and clearing selection never trigger provider submission', async () => {
    const item={...usSmPendingRows.data[0],order_id:83,warehouse_id:1}
    const mock=vi.fn()
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true,
        rows:{...usSmPendingRows,data:[item,{...item,order_id:84}]}})
      await page.find('input[aria-label="选择当前页待发货订单"]').setValue(true)
      expect(page.text()).toContain('已勾选 2 单')
      await page.findAll('button').find(b=>b.text()==='清空勾选')!.trigger('click')
      expect(page.text()).not.toContain('已勾选 2 单')
      expect(mock).not.toHaveBeenCalled()
      page.unmount()
    } finally {vi.unstubAllGlobals()}
  })

  it('previews, confirms once, and reads Dev progress without per-order browser submission', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="DEV-BATCH-TEST">')
    const fingerprint='a'.repeat(64)
    const batchId='00000000-0000-4000-8000-000000000123'
    const confirm=vi.spyOn(window,'confirm').mockReturnValue(true)
    const mock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({count:2,overflow:false,dry_run:true,fingerprint})})
      .mockResolvedValueOnce({ok:true,json:async()=>({batch_id:batchId,status:'queued',dry_run:true,total:2})})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        batch_id:batchId,status:'completed',dry_run:true,total:2,processed:2,
        items:[{order_id:7903,order_no:'007-1109-c42141',status:'simulated',result_code:'DEV_SIMULATED_NO_PROVIDER_IO',provider_order_id:null},
          {order_id:7904,order_no:'007-1109-c42142',status:'simulated',result_code:'DEV_SIMULATED_NO_PROVIDER_IO',provider_order_id:null}],
      })})
    vi.stubGlobal('fetch',mock)
    try {
      const page=mountPage({shipoutProductionBatchUi:true,shipoutProductionBatchDryRun:true,trackingImportPreview:null})
      await page.findAll('button').find(b=>b.text()==='一键推送仓库')!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('待逐单校验 2 单'))
      await page.findAll('button').find(b=>b.text().includes('确认运行 Dev 模拟'))!.trigger('click')
      await vi.waitFor(()=>expect(page.text()).toContain('2 / 2'))
      expect(page.text()).toContain('Dev 模拟通过')
      expect(mock.mock.calls.map(call=>call[0])).toEqual([
        '/logistics/oversea/us-moreplus/shipout/batch/preview?scope=all',
        '/logistics/oversea/us-moreplus/shipout/batch/start',
        '/logistics/oversea/us-moreplus/shipout/batch/'+batchId+'/status',
      ])
      expect(JSON.parse(mock.mock.calls[1][1].body)).toEqual({
        fingerprint,confirmation:'CONFIRM_MOREPLUS_BATCH_2',scope:'all',order_ids:'',
      })
      expect(confirm).toHaveBeenCalledTimes(2)
      page.unmount()
    } finally {
      vi.unstubAllGlobals()
      confirm.mockRestore()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })
})
