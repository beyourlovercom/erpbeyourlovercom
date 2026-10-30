import { mount } from '@vue/test-utils'
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
  canReview: true, canOperate: true, carrierOptions: [], trackingImportPreview: preview, xlwmsOmsWebEnabled:true, ...overrides,
} })

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
    expect(wrapper.text()).toContain('审核通过后，点击「下发世迈仓」')
    expect(wrapper.text()).toContain('确认发货')
    expect(wrapper.find('a[href="/logistics/oversea/us-3sm/export"]').exists()).toBe(true)

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
    expect(wrapper.find('td.xlwms-cell button.xlwms-action.primary').text()).toBe('下发世迈仓')
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



  it('disables US-SM one-click action when secure OMS connection has not been configured', () => {
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      xlwmsOmsWebEnabled:false,rows:usSmPendingRows,trackingImportPreview:null})
    const cell=page.find('td.xlwms-cell')
    expect(cell.text()).toContain('世迈仓尚未连接')
    const button=cell.find('button.xlwms-action.primary')
    expect(button.exists()).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).toContain('请管理员连接世迈仓')
  })

  it('makes the first click a single clear warehouse action without older API or approval panels', async () => {
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:usSmPendingRows})
    const cell=page.find('td.xlwms-cell')
    expect(cell.text()).toContain('待下发')
    expect(cell.find('button.xlwms-action.primary').text()).toBe('下发世迈仓')
    expect(page.text()).toContain('一键下发草稿')
    expect(cell.text()).not.toContain('申请审核已记录')
    expect(cell.text()).not.toContain('状态码')
    expect(page.text()).not.toContain('OpenAPI')
    expect(cell.find('input.xlwms-reship-reason').exists()).toBe(false)
  })

  it('sends the first OMS draft request directly and shows a human-readable result', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRF-UX">')
    const fake=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      state:'oms_draft_created',oms_order_no:'OBS-NEW-1',attempt_ref:'BYL-WEB-1',failure_code:null,
    })})
    vi.stubGlobal('fetch',fake)
    try {
      const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:usSmPendingRows})
      await page.find('td.xlwms-cell button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(page.find('td.xlwms-cell').text()).toContain('已保存到仓库草稿箱'))
      expect(page.find('td.xlwms-cell').text()).toContain('仓库单号：OBS-NEW-1')
      expect(fake).toHaveBeenCalledTimes(1)
      expect(fake.mock.calls[0][0]).toContain('/orders/99/xlwms/web-draft')
      expect(fake.mock.calls[0][1].method).toBe('POST')
    } finally {vi.unstubAllGlobals();document.head.querySelector('meta[name="csrf-token"]')?.remove()}
  })

  it('shows old cancelled order as one concise sentence and uses the same one-click route', async () => {
    const row={...usSmPendingRows.data[0],xlwms:{
      state:'draft_cancelled_confirmed',oms_order_no:'OBS-OLD-1',oms_status_code:'4',
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:null,oms_status_failure_code:null,
    }}
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRF-UX">')
    const fake=vi.fn().mockResolvedValue({ok:true,json:async()=>({
      state:'existing_current_draft',oms_order_no:'OBS-NEW-2',attempt_ref:'BYL-WEB-2',failure_code:null,
    })})
    vi.stubGlobal('fetch',fake)
    try {
      const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
      const cell=page.find('td.xlwms-cell')
      expect(cell.find('.xlwms-state').text()).toContain('上次仓库单已取消，可重新下发')
      expect(cell.find('button.xlwms-action.primary').text()).toBe('重新下发')
      expect(cell.find('details.xlwms-history').exists()).toBe(true)
      expect(cell.find('details.xlwms-history summary').text()).toBe('查看历史仓库记录')
      expect(cell.text()).not.toContain('申请审核')
      await cell.find('button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(cell.find('.xlwms-state').text()).toContain('草稿箱已有此订单'))
      expect(fake.mock.calls[0][0]).toContain('/xlwms/web-draft')
      expect(fake).toHaveBeenCalledTimes(1)
    } finally {vi.unstubAllGlobals();document.head.querySelector('meta[name="csrf-token"]')?.remove()}
  })

  it('explains a missing OMS connection in ordinary language, not raw API codes', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRF-UX">')
    const fake=vi.fn().mockResolvedValue({ok:false,status:500,json:async()=>({message:'OMS_WEB_TOKEN_MISSING'})})
    vi.stubGlobal('fetch',fake)
    try {
      const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:usSmPendingRows})
      await page.find('td.xlwms-cell button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(page.find('td.xlwms-cell .xlwms-error').exists()).toBe(true))
      expect(page.find('td.xlwms-cell .xlwms-error').text()).toContain('请联系管理员')
      expect(page.find('td.xlwms-cell').text()).not.toContain('OMS_WEB_TOKEN_MISSING')
    } finally {vi.unstubAllGlobals();document.head.querySelector('meta[name="csrf-token"]')?.remove()}
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



  it('places Woo tracking sync in the original overseas shipping header without disturbing OMS controls', async () => {
    const shipped={...usSmPendingRows.data[0],source_type:'woocommerce',tracking_sync_status:'pending',
      shipment_status:'shipped',tracking_number:'TRACK-USSM-1',shipped_at:'2026-10-29T00:00:00Z'}
    const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      canTrackingSync:true,trackingImportPreview:null,rows:{...usSmPendingRows,data:[shipped]}})
    expect(wrapper.text()).toContain('同步官网物流')
    expect(wrapper.find('button.xlwms-batch-button').exists()).toBe(false)
    await wrapper.find('input[aria-label^="选择同步"]').setValue(true)
    const batch=wrapper.findAll('button').find(b=>b.text()==='批量同步官网物流')
    expect(batch).toBeTruthy()
    await batch!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/tracking-outbound/selected',
      {order_ids:[99]},expect.objectContaining({preserveScroll:true}))
    expect(mountPage({canTrackingSync:false}).text()).not.toContain('同步官网物流')
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


  it('keeps prior OMS records collapsed while the new single-click action remains available', () => {
    const row={...usSmPendingRows.data[0],shipment_status:'shipped',xlwms:{
      state:'batch_existing',oms_order_no:'OBS-OLD',oms_status_code:'5',
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:null,oms_status_failure_code:null,
    },web_draft:{state:'oms_draft_created',oms_order_no:'OBS-LAST',attempt_ref:'BYL-1',failure_code:null}}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.find('button.xlwms-action.primary').text()).toBe('重新下发')
    expect(cell.find('details.xlwms-history').exists()).toBe(true)
    expect(cell.find('details.xlwms-history').attributes('open')).toBeUndefined()
    expect(cell.find('input.xlwms-reship-reason').exists()).toBe(false)
    expect(cell.text()).not.toContain('重新下发仓库 · 独立出库草稿')
  })

  it('does not offer warehouse dispatch until review is approved', () => {
    const row={...usSmPendingRows.data[0],review_status:'pending' as const}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell')
    expect(cell.text()).toContain('审核通过后可下发')
    expect(cell.find('button.xlwms-action.primary').exists()).toBe(false)
  })

})
