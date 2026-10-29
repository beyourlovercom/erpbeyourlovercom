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
  canReview: true, canOperate: true, carrierOptions: [], trackingImportPreview: preview, ...overrides,
} })

describe('Oversea Dede file exchange workbench', () => {
  beforeEach(() => { routerPost.mockClear(); routerPatch.mockClear(); routerVisit.mockClear() })

  it('exposes the real XLSX round-trip flow and provider evidence', () => {
    const wrapper = mountPage()
    expect(wrapper.find('a[href="/logistics/oversea/us-moreplus/export"]').text()).toContain('下载仓库下单表')
    expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('.xlsx,.csv,.txt')
    expect(wrapper.text()).toContain('上传物流回传表')
    expect(wrapper.text()).toContain('上传仓库 / WMS')
    expect(wrapper.text()).toContain('USPS Ground Advantage')
    expect(wrapper.text()).toContain('Pending')
    expect(wrapper.text()).toContain('ERP确认时间')
    expect(wrapper.text()).toContain('确认导入并标记已发货')
    expect(wrapper.text()).toContain('不会阻止发货')
  })

  it('keeps 3SM file return disabled and exposes manual confirm after tracking entry', async () => {
    const wrapper = mountPage({ warehouseKey: 'us-3sm', warehouse: warehouseOptions[1], trackingImportPreview: null, rows: usSmPendingRows })
    expect(wrapper.text()).not.toContain('上传物流回传表')
    expect(wrapper.text()).toContain('世迈订单行直接审核、API 发货')
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

  it('shows the top-right warehouse sync action only for an operator on US-SM and renders real row outcomes', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRFTOKEN">')
    const request=vi.fn().mockResolvedValue({ok:true,json:async()=>({count:2,results:[{order_id:99,code:'existing'},{order_id:100,code:'blocked'}]})})
    vi.stubGlobal('fetch',request)
    try {
      const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],trackingImportPreview:null,rows:usSmPendingRows,xlwmsBatchEnabled:true})
      const button=wrapper.find('button.xlwms-batch-button')
      expect(button.exists()).toBe(true)
      await button.trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('本次处理 2 单'))
      expect(wrapper.text()).toContain('订单 99 · 已存在')
      expect(wrapper.text()).toContain('订单 100 · 已阻断')
      expect(request).toHaveBeenCalledWith('/logistics/oversea/us-3sm/xlwms/sync',expect.objectContaining({method:'POST',credentials:'same-origin'}))
    } finally { vi.unstubAllGlobals(); document.head.querySelector('meta[name="csrf-token"]')?.remove() }
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


  it('shows OMS tracking/state inline for an existing approved single OMS attempt and never links to a debug page', async () => {
    const row = {
      ...usSmPendingRows.data[0],
      xlwms: {state:'draft_cancelled_confirmed', oms_order_no:'OBS0272610260RS',
        oms_status_code:'4', oms_status_checked_at:null, oms_tracking_no:null,
        failure_code:null, oms_status_failure_code:null},
    }
    const wrapper=mountPage({
      warehouseKey:'us-3sm', warehouse:warehouseOptions[1], trackingImportPreview:null,
      rows:{...usSmPendingRows,data:[row]},xlwmsAuthorizedOrderId:0,
    })
    expect(wrapper.text()).toContain('世迈 OMS · API 发货 / 状态')
    expect(wrapper.text()).toContain('OBS0272610260RS')
    expect(wrapper.text()).toContain('已取消')
    expect(wrapper.findAll('a').some(a=>a.attributes('href')?.endsWith('/xlwms'))).toBe(false)
    expect(wrapper.text()).not.toContain('确认 API 发货')
    expect(wrapper.find('button.xlwms-action').text()).toContain('更新 OMS 状态')
  })

  it('provides local preflight but never enables sending when the exact order is not authorized', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRFTOKEN">')
    const request=vi.fn().mockResolvedValue({
      ok:true,json:async()=>({
        order_id:99,blockers:[],ready:true,payload_hash:'a'.repeat(64),
        oms_warehouse:'Yangfan',items:[{sku:'REVIEW-SKU',quantity:1}],
        already_attempted:false,
      }),
    })
    vi.stubGlobal('fetch',request)
    try {
      const wrapper=mountPage({
        warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:usSmPendingRows,xlwmsAuthorizedOrderId:0,
      })
      await wrapper.find('button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('未获得单笔真实发货授权'))
      expect(wrapper.text()).not.toContain('确认 API 发货')
      expect(request).toHaveBeenCalledTimes(1)
      expect(request.mock.calls[0][0]).toBe('/logistics/oversea/us-3sm/orders/99/xlwms/preflight')
      expect(request.mock.calls[0][1].method).toBe('GET')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('allows exactly one confirmed per-order API send only after the authorized preflight', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRFTOKEN">')
    const fetchMock=vi.fn()
      .mockResolvedValueOnce({ok:true,json:async()=>({
        order_id:99,blockers:[],ready:true,payload_hash:'a'.repeat(64),
        oms_warehouse:'Yangfan',items:[{sku:'REVIEW-SKU',quantity:1}],already_attempted:false,
      })})
      .mockResolvedValueOnce({ok:true,json:async()=>({
        state:'submitted_oms',status_label:'OMS 已接单（未出库）',oms_order_no:'OBS-TEST-123',
      })})
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({
        warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:usSmPendingRows,xlwmsAuthorizedOrderId:99,
      })
      await wrapper.find('button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('确认 API 发货'))
      expect(fetchMock).toHaveBeenCalledTimes(1)
      await wrapper.find('button.xlwms-action.primary').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('OBS-TEST-123'))
      expect(fetchMock).toHaveBeenCalledTimes(2)
      const [url,options]=fetchMock.mock.calls[1]
      expect(url).toBe('/logistics/oversea/us-3sm/orders/99/xlwms/send')
      expect(JSON.parse(options.body)).toEqual({
        payload_hash:'a'.repeat(64),confirmation:'SUBMIT_US_SM_ORDER_99',
      })
      expect(wrapper.text()).not.toContain('确认 API 发货')
      expect(wrapper.text()).toContain('OMS 已建单 · 接单待核实')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('queries existing OMS state inline, preserves ERP workflow and does not navigate', async () => {
    document.head.insertAdjacentHTML('beforeend','<meta name="csrf-token" content="TEST-CSRFTOKEN">')
    const row={...usSmPendingRows.data[0],xlwms:{
      state:'submitted_oms',oms_order_no:'OBS-TEST-123',oms_status_code:null,
      oms_status_checked_at:null,oms_tracking_no:null,
      failure_code:null,oms_status_failure_code:null,
    }}
    const fetchMock=vi.fn().mockResolvedValue({
      ok:true,json:async()=>({
        read_only:true,fresh:true,oms_order_no:'OBS-TEST-123',
        oms_status_code:'4',oms_status_label:'已取消',oms_tracking_no:null,
        oms_status_checked_at:'2026-10-26T08:00:00Z',oms_status_failure_code:null,
        erp_status_unchanged:true,woo_status_unchanged:true,
      }),
    })
    vi.stubGlobal('fetch',fetchMock)
    try {
      const wrapper=mountPage({
        warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]},xlwmsAuthorizedOrderId:0,
      })
      await wrapper.find('button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('已取消'))
      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0][0]).toContain('/xlwms/status')
      expect(routerPost).not.toHaveBeenCalled()
      expect(routerVisit).not.toHaveBeenCalled()
      expect(wrapper.text()).not.toContain('确认 API 发货')
    } finally {
      vi.unstubAllGlobals()
      document.head.querySelector('meta[name="csrf-token"]')?.remove()
    }
  })

  it('keeps the operator on the same order row and shows red failure with no OMS outbound creation', async () => {
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:false,status:403}))
    try {
      const wrapper=mountPage({
        warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
        trackingImportPreview:null,rows:usSmPendingRows,xlwmsAuthorizedOrderId:0,
      })
      await wrapper.find('button.xlwms-action').trigger('click')
      await vi.waitFor(()=>expect(wrapper.find('small.xlwms-error').exists()).toBe(true))
      expect(wrapper.find('small.xlwms-error').text()).toContain('没有此操作权限')
      expect(wrapper.find('table.oversea-table').exists()).toBe(true)
      expect(routerPost).not.toHaveBeenCalled()
    } finally {vi.unstubAllGlobals()}
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

  it('places Woo tracking sync in the original overseas shipping header without disturbing OMS controls', async () => {
    const shipped={...usSmPendingRows.data[0],source_type:'woocommerce',tracking_sync_status:'pending',
      shipment_status:'shipped',tracking_number:'TRACK-USSM-1',shipped_at:'2026-10-29T00:00:00Z'}
    const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      canTrackingSync:true,trackingImportPreview:null,rows:{...usSmPendingRows,data:[shipped]}})
    expect(wrapper.text()).toContain('同步官网物流')
    expect(wrapper.find('button.xlwms-batch-button').exists()).toBe(true)
    await wrapper.find('input[aria-label^="选择同步"]').setValue(true)
    const batch=wrapper.findAll('button').find(b=>b.text()==='批量同步官网物流')
    expect(batch).toBeTruthy()
    await batch!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/tracking-outbound/selected',
      {order_ids:[99]},expect.objectContaining({preserveScroll:true}))
    expect(mountPage({canTrackingSync:false}).text()).not.toContain('同步官网物流')
  })
  it('treats OMS exception status 5 as not yet accepted by the warehouse', () => {
    const row={...usSmPendingRows.data[0],warehouse_dispatch:{
      status:'exception',label:'OMS 异常 · 待处理',detail:'OMS 异常 · 需处理后重新提交',
      oms_order_no:'OBS0272610290RS',last_checked_at:'2026-10-29 13:33',
      failure_code:null,can_retry_local_block:false,
    },xlwms:{state:'batch_existing',oms_order_no:'OBS0272610290RS',oms_status_code:'5',
      oms_status_checked_at:'2026-10-29 13:33',oms_tracking_no:null,failure_code:null,oms_status_failure_code:null}}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],trackingImportPreview:null,
      rows:{...usSmPendingRows,data:[row]}})
    expect(page.text()).toContain('OMS 异常 · 待处理')
    expect(page.text()).toContain('OMS 异常 · 需处理后重新提交')
    expect(page.text()).toContain('OBS0272610290RS')
    expect(page.text()).not.toContain('需处理：batch_existing')
    expect(page.find('td.xlwms-cell').text()).not.toContain('已发货')
  })

  it('keeps real OMS code 0 in draft, not dispatched to warehouse', () => {
    const row={...usSmPendingRows.data[0],warehouse_dispatch:{
      status:'oms_draft',label:'OMS 草稿 · 未提交仓库',detail:'需补齐真实面单并在 OMS 提交',
      oms_order_no:'OBS0272610290RS',last_checked_at:null,
      failure_code:null,can_retry_local_block:false,
    },xlwms:{state:'batch_existing',oms_order_no:'OBS0272610290RS',
      oms_status_code:'0',oms_status_checked_at:null,oms_tracking_no:null,
      failure_code:null,oms_status_failure_code:null}}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],
      trackingImportPreview:null,rows:{...usSmPendingRows,data:[row]}})
    const cell=page.find('td.xlwms-cell').text()
    expect(cell).toContain('OMS 草稿 · 未提交仓库')
    expect(cell).toContain('需补齐真实面单并在 OMS 提交')
    expect(cell).not.toContain('已下发 OMS')
    expect(cell).not.toContain('已发货')
  })

  it('allows safe local preflight after create_disabled without allowing OMS creation', async () => {
    const row={...usSmPendingRows.data[0],warehouse_dispatch:{
      status:'pending_dispatch',label:'待下发',detail:'未下发 · 建单权限关闭',
      oms_order_no:null,last_checked_at:null,failure_code:'create_disabled',can_retry_local_block:true,
    },xlwms:{state:'batch_blocked',oms_order_no:null,oms_status_code:null,
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:'create_disabled',oms_status_failure_code:null}}
    const fake=vi.fn().mockResolvedValue({ok:true,json:async()=>({order_id:99,blockers:[],ready:true,
      payload_hash:'a'.repeat(64),oms_warehouse:'OMS-US-SM',items:[{sku:'REVIEW-SKU',quantity:1}],already_attempted:false})})
    vi.stubGlobal('fetch',fake)
    try {
      const wrapper=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],trackingImportPreview:null,
        rows:{...usSmPendingRows,data:[row]},xlwmsAuthorizedOrderId:0})
      const btn=wrapper.findAll('button').find(b=>b.text().trim()==='API 发货')
      expect(btn).toBeDefined()
      await btn!.trigger('click')
      await vi.waitFor(()=>expect(fake).toHaveBeenCalledTimes(1))
      expect(fake.mock.calls[0][0]).toBe('/logistics/oversea/us-3sm/orders/99/xlwms/preflight')
      expect(fake.mock.calls[0][1].method).toBe('GET')
      await vi.waitFor(()=>expect(wrapper.text()).toContain('未获得此订单的实际发货授权'))
      expect(wrapper.text()).not.toContain('确认 API 发货')
    } finally {vi.unstubAllGlobals()}
  })

  it('shows local create-disabled attempt as not dispatched and keeps per-order action visible', () => {
    const row={...usSmPendingRows.data[0],warehouse_dispatch:{
      status:'pending_dispatch',label:'待下发',detail:'未下发 · 建单权限关闭',
      oms_order_no:null,last_checked_at:null,failure_code:'create_disabled',can_retry_local_block:true,
    },xlwms:{state:'batch_blocked',oms_order_no:null,oms_status_code:null,
      oms_status_checked_at:null,oms_tracking_no:null,failure_code:'create_disabled',oms_status_failure_code:null}}
    const page=mountPage({warehouseKey:'us-3sm',warehouse:warehouseOptions[1],trackingImportPreview:null,
      rows:{...usSmPendingRows,data:[row]},xlwmsBatchEnabled:false})
    expect(page.text()).toContain('待下发')
    expect(page.text()).toContain('未下发 · 建单权限关闭')
    expect(page.text()).toContain('API 发货')
    expect(page.find('button.xlwms-batch-button').attributes('disabled')).toBeDefined()
    expect(page.text()).not.toContain('batch_blocked')
  })

})
