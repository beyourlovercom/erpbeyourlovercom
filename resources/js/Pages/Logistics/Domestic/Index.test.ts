import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { routerGet, routerPost, routerPatch, routerVisit, routerReload, feedbackSpy } = vi.hoisted(() => ({ routerGet:vi.fn(), routerPost: vi.fn(), routerPatch: vi.fn(), routerVisit: vi.fn(), routerReload: vi.fn(), feedbackSpy: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props:['title'], template:'<title>{{ title }}</title>' },
  router: { get:routerGet, post:routerPost, patch:routerPatch, visit:routerVisit, reload:routerReload },
}))
vi.mock('../../../lib/globalFeedback', () => ({ publishGlobalFeedback:feedbackSpy }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const baseProps = {
  view:'pending',
  rows:{ data:[{
    line:1,order_id:1,paid_date:'11-09',amount:'127.35',amount_currency:'EUR',amount_source:'网站真实订单总金额',erp_order_id:'007-0923-c50001',provider_reference:'C50001-007',order_url:'/customers/orders/1',
    recipient_name:'A',phone:'123',country:'DE',state:'DE-SN',city:'Dresden',postal_code:'01219',
    carrier:'4PX',service:'SD经济挂号-带电',carrier_selection_source:'manual',carrier_ready:true,tracking_number:null,shipment_status:'pending',shipped_at:null,
    review_status:'approved' as const,export_blockers:[],items:[{sku:'M2',quantity:1}],
  }],current_page:1,last_page:1,total:1 },
  viewCounts:{review:0,pending:1,'today-4px':0,'today-weship':0,exceptions:0,today:0,all:1},
  canOperate:true,
  canReview:true,
  carrierOptions:[
    {value:'4PX',label:'速达',services:[{value:'SD经济挂号-带电',label:'经济挂号 - 带电'}]},
    {value:'Weship',label:'纬狮',services:[{value:'标准挂号',label:'标准挂号'}]},
  ],
  trackingImportProvider:'4px' as const,
  trackingImportPreview:{
    token:'a'.repeat(64),apply_count:1,noop_count:0,blocked_count:1,
    rows:[
      {row:2,order_id:1,order_number:'C50001-007',tracking_number:'4PX-1',carrier:'4PX',provider_status:'已出货',status:'apply',message:''},
      {row:3,order_id:2,order_number:'C50002-007',tracking_number:'',carrier:'4PX',provider_status:'已出货',status:'blocked',message:'尚未完成国内物流审核'},
    ],
  },
  providerStatus:{
    '4px':{export_ready:true,return_ready:true,label:'速达'},
    weship:{export_ready:true,return_ready:true,label:'纬狮',note:'纬狮独立保留原 24 列模板 · XLSX 下载 · XLS/XLSX 回传'},
  },
}

describe('Domestic logistics production workbench', () => {
  it('shows a selected-only pending XLSX link without changing the full export URL', async () => {
    const wrapper = mount(Index, {props:{...baseProps,trackingImportPreview:null,trackingImportProvider:null,
      globalGridPilotAvailable:true,globalGridPilotEnabled:true}})
    expect(wrapper.find('[data-testid="download-domestic-selected"]').exists()).toBe(false)
    const state = (wrapper.vm as unknown as {$:{setupState:Record<string,unknown>}}).$.setupState
    const select = state.onBulkSelectionChanged as (rows:{id:number}[])=>void
    select([{id:1}])
    await wrapper.vm.$nextTick()
    const link = wrapper.get('[data-testid="download-domestic-selected"]')
    expect(link.text()).toContain('下载已选 1 单')
    expect(link.attributes('href')).toBe('/logistics/china/pending-batch/export?order_ids%5B%5D=1')
    expect(wrapper.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(true)
    expect((state.bulkOptions as {label:string}[]).map(option=>option.label)).toContain('批量到待分仓')
  })
  beforeEach(() => { routerGet.mockClear(); routerPost.mockClear(); routerPatch.mockClear(); routerVisit.mockClear(); routerReload.mockClear(); feedbackSpy.mockClear(); vi.unstubAllGlobals() })

  it('offers the same real force-return route in review, stockout and pending grids', async () => {
    for (const view of ['review','stockout','pending']) {
      const wrapper=mount(Index,{props:{...baseProps,view,globalGridPilotEnabled:true,trackingImportPreview:null}})
      const state=(wrapper.vm as unknown as {$:{setupState:Record<string,any>}}).$.setupState
      expect(state.bulkOptions.map((option:{label:string})=>option.label)).toContain('批量到待分仓')
      state.onBulkSelectionChanged([{id:1}])
      const response={ok:true,status:200,headers:{get:()=>null},json:async()=>({
        results:[{order_id:1,ok:true,message:'已进入待分仓'}],success_count:1,failed_count:0,
      })}
      const fetchSpy=vi.fn().mockResolvedValue(response)
      vi.stubGlobal('fetch',fetchSpy)
      await state.applyGridBatch({action:'force_return_allocation',reason:''})
      expect(fetchSpy).toHaveBeenCalledWith('/logistics/china/batch-return-allocation',
        expect.objectContaining({method:'POST',body:JSON.stringify({order_ids:[1]})}))
      expect(routerReload).toHaveBeenCalledWith({only:['rows','viewCounts']})
      wrapper.unmount()
      routerReload.mockClear()
    }
  })

  it('does not offer force allocation to domestic viewers without review permission', () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'pending',canReview:false,globalGridPilotEnabled:true}})
    const state=(wrapper.vm as unknown as {$:{setupState:Record<string,any>}}).$.setupState
    expect(state.bulkOptions.map((option:{label:string})=>option.label)).not.toContain('批量到待分仓')
  })

  it('uses one compact toolbar and bottom help for all nine domestic views', () => {
    const views=['review','stockout','outsource','pending','today','today-4px','today-weship','exceptions','all']
    for (const view of views) {
      const wrapper=mount(Index,{props:{...baseProps,view,trackingImportPreview:null,trackingImportProvider:null}})
      expect(wrapper.find('.domestic-compact-workspace').exists(),view).toBe(true)
      expect(wrapper.find('header.compact-workspace-toolbar').exists(),view).toBe(true)
      expect(wrapper.findAll('nav.tabs button'),view).toHaveLength(9)
      expect(wrapper.find('.domestic-head-actions a[href="/logistics/wait-stock"]').exists(),view).toBe(true)
      expect(wrapper.find('.eyebrow').exists(),view).toBe(false)
      expect(wrapper.find('.continuity').exists(),view).toBe(false)
      const help=wrapper.get('footer.compact-workspace-help')
      expect(help.text(),view).toContain('生产连续性')
      expect(help.text(),view).toContain('Tracking 冲突只阻断对应订单')
    }
  })

  it('routes pending-row commercial reject through the existing reason dialog and keeps the pending view', async () => {
    const staged = { ...baseProps.rows.data[0], review_status: 'pending' as const, tracking_number: 'STAGED-0001' }
    const wrapper = mount(Index, { props: { ...baseProps, trackingImportPreview: null,
      rows: { ...baseProps.rows, data: [staged] } } })
    const state = (wrapper.vm as unknown as { $: { setupState: Record<string, unknown> } }).$.setupState
    const columns = state.domesticGridColumns as { colId: string; cellRendererParams?: { rejectPending?: (id: number) => void } }[]
    const reject = columns.find(column => column.colId === 'actions')?.cellRendererParams?.rejectPending
    expect(reject).toBeTypeOf('function')
    reject?.(1)
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[aria-label="驳回国内订单"]').text()).toContain('STAGED-0001')
    expect(wrapper.get('[aria-label="驳回国内订单"]').text()).toContain('不会自动取消物流商')
    await wrapper.get('#domestic-reject-reason').setValue('收件人地址需要重新核对')
    await wrapper.get('.review-reject-dialog button.reject-btn').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/reject',
      { reason: '收件人地址需要重新核对', return_view: 'pending' },
      expect.objectContaining({ preserveScroll: true, preserveState: true }))
  })

  it('retires both provider history tabs and sends unified column dropdown filters to the server', async () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'all',gridFilters:{carrier:'4px'},
      globalGridPilotAvailable:true,globalGridPilotEnabled:true,trackingImportProvider:null,trackingImportPreview:null}})
    const tabs=wrapper.get('nav.tabs').text()
    expect(tabs).toContain('全部国内订单')
    expect(tabs).not.toContain('速达订单')
    expect(tabs).not.toContain('纬狮订单')
    const state=(wrapper.vm as unknown as {$:{setupState:Record<string,unknown>}}).$.setupState
    expect(state.allGridFilterColumns).toEqual(expect.arrayContaining(['carrier','status','warehouse_route','review','shipment_status']))
    expect(state.allGridFilterValues).toMatchObject({carrier:'4px'})
    const columns=state.domesticGridColumns as {colId:string;floatingFilterComponent?:unknown}[]
    expect(columns.find(col=>col.colId==='carrier')?.floatingFilterComponent).toBeTruthy()
    const filter=state.filterAllDomestic as (value:{colId:string;value:string})=>void
    filter({colId:'carrier',value:'Weship'})
    expect(routerGet).toHaveBeenCalledWith('/logistics/china',expect.objectContaining({
      view:'all',filter_carrier:'Weship',page:1,global_grid:'1',
    }),expect.objectContaining({replace:true}))
    filter({colId:'unknown',value:'x'})
    expect(routerGet).toHaveBeenCalledTimes(1)
  })

  it('Zhang gets only the dedicated real external-purchase queue and can submit procurement progress', async () => {
    const row={...baseProps.rows.data[0],procurement:{
      id:11,number:'PO-20261114-0001',status:'pending',carrier:null,tracking_number:null,
      received_at:null,released_at:null,items:[{
        id:13,sku:'M1',quantity:1,purchase_quantity:1,purchase_url:null,purchase_size:null,
        received_quantity:0,received_sku:null,
      }],
    }}
    const wrapper=mount(Index,{props:{...baseProps,view:'outsource',canLogisticsView:false,
      canProcurementOperate:true,canProcurementRelease:false,rows:{...baseProps.rows,data:[row]}}})
    expect(wrapper.findAll('nav.tabs button')).toHaveLength(1)
    expect(wrapper.find('nav.tabs').text()).toContain('外采单')
    expect(wrapper.find('[data-testid="domestic-external-procurement"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('PO-20261114-0001')
    expect(wrapper.text()).not.toContain('Vita 确认 → 国内待发货')
    await wrapper.find('.external-actions button').trigger('click')
    expect(routerPatch).toHaveBeenCalledWith('/purchase/orders/11',
      expect.objectContaining({status:'purchasing',items:[expect.objectContaining({id:13})]}),
      expect.objectContaining({preserveScroll:true,onSuccess:expect.any(Function)}))
    expect(wrapper.find('.domestic-grid-main').exists()).toBe(false)
    expect(wrapper.find('.orders').exists()).toBe(false)
  })

  it('Vita alone sees arrival release while Zhang submits warehouse receipt evidence', async () => {
    const procurement={
      id:12,number:'PO-20261114-0002',status:'ordered',carrier:'顺丰',tracking_number:'SF1',
      received_at:null,released_at:null,items:[{
        id:14,sku:'MF414A',quantity:1,purchase_quantity:1,purchase_url:null,purchase_size:null,
        received_quantity:0,received_sku:null,
      }],
    }
    const ordered=mount(Index,{props:{...baseProps,view:'outsource',canProcurementOperate:true,
      canProcurementRelease:false,rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],procurement}]}}})
    const buttons=ordered.findAll('.external-actions button')
    await buttons.find(button=>button.text()==='登记到货')!.trigger('click')
    expect(ordered.text()).toContain('工厂实际到货核验')
    await ordered.find('.external-receipt textarea').setValue('2026-11-14 实物核对收货')
    await ordered.find('.external-receipt button.primary').trigger('click')
    expect(routerPatch).toHaveBeenCalledWith('/purchase/orders/12',
      expect.objectContaining({status:'arrived',receipt_note:'2026-11-14 实物核对收货',
        items:[expect.objectContaining({received_sku:'MF414A',received_quantity:1})]}),
      expect.any(Object))

    const arrived=mount(Index,{props:{...baseProps,view:'outsource',canProcurementOperate:false,
      canProcurementRelease:true,rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],
        procurement:{...procurement,status:'arrived',received_at:'2026-11-14T10:00:00Z'}}]}}})
    const release=arrived.find('.external-actions button.primary')
    expect(release.text()).toContain('Vita 确认')
    await release.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/purchase/orders/12/release-to-domestic',{},
      expect.objectContaining({preserveScroll:true}))
  })

  it('keeps export blockers visible in the grid instead of hiding actionable errors in help', () => {
    const blocked={...baseProps.rows.data[0],export_blockers:['缺电话，禁止导出']}
    const wrapper=mount(Index,{props:{...baseProps,view:'review',rows:{...baseProps.rows,data:[blocked]}}})
    expect(wrapper.get('.orders').text()).toContain('缺电话，禁止导出')
    expect(wrapper.get('footer.compact-workspace-help').text()).not.toContain('缺电话，禁止导出')
  })

  it('prefills validated automatic carrier and allows direct manual override', async () => {
    const legacy={...baseProps.rows.data[0],carrier_selection_source:'automatic',carrier_ready:true,carrier:'4PX',service:'SD经济挂号-带电'}
    const wrapper=mount(Index,{props:{...baseProps,view:'pending',rows:{...baseProps.rows,data:[legacy]}}})
    const carrier=wrapper.find('select[aria-label^="选择物流商"]')
    const service=wrapper.find('select[aria-label^="选择服务类型"]')
    expect(carrier.exists()).toBe(true)
    expect(service.exists()).toBe(true)
    expect((carrier.element as HTMLSelectElement).value).toBe('4PX')
    expect(carrier.text()).toContain('速达')
    expect(carrier.text()).not.toContain('4PX')
    expect((service.element as HTMLSelectElement).value).toBe('SD经济挂号-带电')
    expect(service.attributes('disabled')).toBeUndefined()
    const ship=wrapper.findAll('button').find(b=>b.text()==='确认发货')!
    expect(ship.attributes('disabled')).toBeDefined()

    await carrier.setValue('Weship')
    expect((service.element as HTMLSelectElement).value).toBe('')
    await service.setValue('标准挂号')
    expect(ship.attributes('disabled')).toBeDefined()
    await wrapper.find('.domestic-service-entry button').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/carrier-service',
      {carrier:'Weship',service:'标准挂号'},
      expect.objectContaining({preserveState:true,preserveScroll:true}))
  })

  it('shows only Chinese 4PX service labels while retaining original submitted channel values', async () => {
    const services = [
      {value:'SD经济挂号-带电',label:'Economy Registered - Battery / SD经济挂号-带电',name_zh:'SD经济挂号-带电'},
      {value:'SD标准挂号-带电',label:'Standard Registered - Battery / SD标准挂号-带电',name_zh:'SD标准挂号-带电'},
      {value:'SD标准挂号-普货',label:'Standard Registered - General / SD标准挂号-普货',name_zh:'SD标准挂号-普货'},
    ]
    const wrapper = mount(Index,{props:{...baseProps,carrierOptions:[
      {value:'4PX',label:'速达',services},
      {value:'Weship',label:'纬狮',services:[{value:'标准挂号',label:'标准挂号'}]},
    ]}})
    const service = wrapper.find('select[aria-label^="选择服务类型"]')
    const options = service.findAll('option').slice(1)
    expect(options.map(option=>option.text())).toEqual(services.map(item=>item.name_zh))
    expect(options.map(option=>(option.element as HTMLOptionElement).value)).toEqual(services.map(item=>item.value))
    expect((service.element as HTMLSelectElement).value).toBe('SD经济挂号-带电')
    await service.setValue('SD标准挂号-普货')
    await wrapper.find('.domestic-service-entry button').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/carrier-service',
      {carrier:'4PX',service:'SD标准挂号-普货'},expect.any(Object))
  })

  it('keeps readonly and review queues non-editable but allows unreviewed pending routes', () => {
    const readonly=mount(Index,{props:{...baseProps,canOperate:false}})
    expect(readonly.find('select[aria-label^="选择物流商"]').exists()).toBe(false)
    const review=mount(Index,{props:{...baseProps,view:'review'}})
    expect(review.find('select[aria-label^="选择物流商"]').exists()).toBe(false)
    const unreviewed=mount(Index,{props:{...baseProps,view:'pending',
      rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],review_status:'pending' as const}]}}})
    expect(unreviewed.find('select[aria-label^="选择物流商"]').exists()).toBe(true)
  })

  it('splits SKU and product quantity into separate columns on the domestic review page only', () => {
    const review = mount(Index,{props:{...baseProps,view:'review'}})
    const headers = review.findAll('.orders thead th').map(th=>th.text())
    const cells = review.findAll('.orders tbody tr:first-child td')
    expect(headers).toContain('SKU')
    expect(headers).toContain('数量')
    expect(headers.slice(headers.indexOf('订单状态') + 1,headers.indexOf('订单状态') + 5)).toEqual(['所属分仓','成交日期','SKU','数量'])
    expect(cells[headers.indexOf('成交日期')].text()).toBe('11-09')
    expect(cells[headers.indexOf('成交日期')].attributes('title')).toBe('')
    expect(cells[headers.indexOf('SKU')].find('div').text()).toBe('M2')
    expect(cells[headers.indexOf('数量')].text()).toBe('1')
    expect(cells[headers.indexOf('金额')].text()).toBe('127.35 EUR')

    const pending = mount(Index,{props:{...baseProps,view:'pending'}})
    const pendingHeaders = pending.findAll('.orders thead th').map(th=>th.text())
    expect(pendingHeaders).not.toContain('SKU / 数量')
    expect(pendingHeaders).not.toContain('成交日期')
    expect(pendingHeaders.slice(pendingHeaders.indexOf('国家/地区') + 1, pendingHeaders.indexOf('国家/地区') + 4)).toEqual(['SKU','数量','金额'])
    const pendingCells = pending.findAll('.orders tbody tr:first-child td')
    expect(pendingCells[pendingHeaders.indexOf('SKU')].find('div').text()).toBe('M2')
    expect(pendingCells[pendingHeaders.indexOf('数量')].text()).toBe('1')
    expect(pendingCells[pendingHeaders.indexOf('金额')].text()).toBe('127.35 EUR')
    expect(pendingCells[pendingHeaders.indexOf('金额')].attributes('title')).toBe('网站真实订单总金额')
    const history = mount(Index,{props:{...baseProps,view:'all'}})
    const historyHeaders = history.findAll('.orders thead th').map(th=>th.text())
    expect(historyHeaders).toContain('SKU / 数量')
    expect(historyHeaders).not.toContain('金额')
    const missingAmount = mount(Index,{props:{...baseProps,view:'pending',rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],amount:null,amount_currency:null}]}}})
    const missingHeaders = missingAmount.findAll('.orders thead th').map(th=>th.text())
    expect(missingAmount.findAll('.orders tbody tr:first-child td')[missingHeaders.indexOf('金额')].text()).toBe('—')
    const noPaid = mount(Index,{props:{...baseProps,view:'review',rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],paid_date:null}]}}})
    const noPaidHeaders = noPaid.findAll('.orders thead th').map(th=>th.text())
    expect(noPaid.findAll('.orders tbody tr:first-child td')[noPaidHeaders.indexOf('成交日期')].text()).toBe('—')
  })

  it('has exactly one stockout action and restores a business-approved pending order', async () => {
    const stockoutRow = {
      ...baseProps.rows.data[0],review_status:'stockout' as const,
      tracking_sync_status:'failed' as const,
      tracking_sync_reason:'旧异常',
    }
    const wrapper=mount(Index,{props:{...baseProps,view:'stockout',canTrackingSync:true,
      rows:{...baseProps.rows,data:[stockoutRow]},trackingImportPreview:null,trackingImportProvider:null}})
    const headers=wrapper.findAll('.orders thead th').map(th=>th.text())
    expect(headers).toContain('操作')
    expect(headers).not.toContain('选择')
    expect(headers.filter(th=>th==='操作')).toHaveLength(1)
    expect(wrapper.findAll('.orders tbody tr:first-child button').map(button=>button.text())).toEqual(['已到货-可发货','进待分仓'])
    expect(wrapper.find('.tracking-selection-bar').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('重新审核')
    expect(wrapper.find('.review-reject-backdrop').exists()).toBe(false)
    await wrapper.find('.orders tbody tr:first-child button').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/restock',{},
      expect.objectContaining({preserveScroll:false,onFinish:expect.any(Function)}))

    const readOnly=mount(Index,{props:{...baseProps,view:'stockout',canReview:false,canTrackingSync:true,
      rows:{...baseProps.rows,data:[stockoutRow]}}})
    expect(readOnly.findAll('.orders tbody tr:first-child button')).toHaveLength(0)
  })

  it('uses the correct table span in pending, review, and history empty states', () => {
    for (const [view, expected] of [['pending','16'], ['review','17'], ['today','14']]) {
      const wrapper = mount(Index,{props:{...baseProps,view,rows:{...baseProps.rows,data:[]}}})
      expect(wrapper.get('.orders tbody td.empty').attributes('colspan')).toBe(expected)
    }
  })

  it('places 今日订单 immediately after 待发货 and renders three concise one-line modules with no commas', () => {
    const today=mount(Index,{props:{...baseProps,view:'today',trackingImportPreview:null,trackingImportProvider:null}})
    const nav=today.findAll('nav.tabs button').map(b=>b.text())
    expect(nav[2]).toContain('外采单')
    expect(nav[3]).toContain('待发货')
    expect(nav[4]).toContain('今日订单')
    const cards=today.findAll('.today-shortcut')
    expect(cards).toHaveLength(3)
    expect(cards[0].text()).toBe('⬇️这批订单')
    expect(cards[1].text()).toBe('⏬今日速达')
    expect(cards[2].text()).toBe('⏬今日纬狮')
    expect(today.find('.today-shortcuts').text()).not.toMatch(/[,，]/)
    expect(today.findAll('.today-shortcut a').map(a=>a.attributes('href'))).toEqual([
      '/logistics/china/today/export',
      '/logistics/china/export/4px',
      '/logistics/china/export/weship',
    ])
    expect(today.find('[data-testid="download-today-orders"]').exists()).toBe(true)
    expect(today.findAll('.today-shortcut button')).toHaveLength(0)
    expect(today.text()).not.toContain('上传速达单号表')
    expect(today.text()).not.toContain('上传纬狮单号表')
    expect(mount(Index,{props:{...baseProps,view:'today-4px'}}).find('.today-shortcuts').exists()).toBe(false)
    expect(mount(Index,{props:{...baseProps,view:'today-weship'}}).find('.today-shortcuts').exists()).toBe(false)
  })

  it('keeps the only working upload control on pending and auto-detects the submitted template server-side', async () => {
    const today=mount(Index,{props:{...baseProps,view:'today'}})
    expect(today.find('input[type="file"]').exists()).toBe(false)
    expect(today.text()).not.toContain('⏫上传物流单号')
    expect(mount(Index,{props:{...baseProps,view:'today-4px'}}).find('input[type="file"]').exists()).toBe(false)
    expect(mount(Index,{props:{...baseProps,view:'today-weship'}}).find('input[type="file"]').exists()).toBe(false)
    const wrapper=mount(Index,{props:baseProps})
    const fileInput=wrapper.find('input[type="file"]')
    expect(fileInput.exists()).toBe(true)
    const click=vi.spyOn(fileInput.element as HTMLInputElement,'click').mockImplementation(()=>{})
    const api=vi.fn().mockResolvedValue({ok:true,redirected:false,json:async()=>({message:'已生成回传预览'})})
    vi.stubGlobal('fetch',api)
    await wrapper.findAll('button').find(b=>b.text().includes('⏫上传物流单号'))!.trigger('click')
    expect(click).toHaveBeenCalledTimes(1)
    const file=new File(['test'],'4px.xlsx')
    Object.defineProperty(fileInput.element,'files',{configurable:true,value:[file]})
    await fileInput.trigger('change')
    await flushPromises()
    expect(api).toHaveBeenCalledWith('/logistics/china/tracking-import/preview',
      expect.objectContaining({method:'POST',body:expect.any(FormData)}))
    expect(routerReload).toHaveBeenCalled()
    expect(feedbackSpy).toHaveBeenCalledWith(expect.objectContaining({state:'SUCCESS'}))
    click.mockRestore()
    expect(mount(Index,{props:{...baseProps,canOperate:false}}).find('input[type="file"]').exists()).toBe(false)
  })

  it('shows failures through global island and does not navigate to a system error page', async () => {
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue({
      ok:false,status:500,headers:{get:()=> 'request-500'},json:async()=>{throw new Error('html response')},
    }))
    const wrapper=mount(Index,{props:baseProps})
    await wrapper.findAll('button').find(b=>b.text().includes('确认发货（仅可应用订单）'))!.trigger('click')
    await flushPromises()
    expect(feedbackSpy).toHaveBeenCalledWith(expect.objectContaining({
      state:'ERROR',message:expect.stringContaining('切勿直接重复提交'),
    }))
    expect(routerReload).not.toHaveBeenCalled()
    expect(routerVisit).not.toHaveBeenCalled()
  })

  it('saves a reviewed domestic Tracking inline without accidentally confirming shipment', async () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'pending'}})
    const input=wrapper.find('input[aria-label^="填写发货单号"]')
    expect(input.exists()).toBe(true)
    await input.setValue('4PX20261103')
    await input.trigger('blur')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/tracking-draft',
      {tracking_number:'4PX20261103'},expect.objectContaining({preserveScroll:true}))
    expect(routerPost).not.toHaveBeenCalledWith('/logistics/china/orders/1/quick-ship',expect.anything(),expect.anything())
  })

  it('confirms only on click; unreviewed pending stays editable, stockout and shipped do not', async () => {
    const ready=mount(Index,{props:{...baseProps,view:'pending'}})
    await ready.find('input[aria-label^="填写发货单号"]').setValue('4PX20261103')
    const confirm=ready.findAll('button').find(b=>b.text()==='确认发货')
    expect(confirm).toBeTruthy()
    await confirm!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/quick-ship',
      {tracking_number:'4PX20261103'},expect.objectContaining({preserveScroll:true}))

    for(const review of ['pending','stockout'] as const) {
      const blocked=mount(Index,{props:{...baseProps,rows:{...baseProps.rows,data:[{
        ...baseProps.rows.data[0],review_status:review,
      }]}}})
      expect(blocked.find('input[aria-label^="填写发货单号"]').exists()).toBe(review==='pending')
      expect(blocked.findAll('button').filter(b => b.text() === '确认发货')).toHaveLength(review==='pending'?1:0)
    }
    const shipped=mount(Index,{props:{...baseProps,rows:{...baseProps.rows,data:[{
      ...baseProps.rows.data[0],shipment_status:'shipped',tracking_number:'TRACKED',
    }]}}})
    expect(shipped.find('input[aria-label^="填写发货单号"]').exists()).toBe(false)
    expect(shipped.text()).toContain('TRACKED')
    expect(mount(Index,{props:{...baseProps,canOperate:false}}).find('input[aria-label^="填写发货单号"]').exists()).toBe(false)
  })

  it('shows the daily domestic flow with isolated 4PX and Weship outbound downloads', () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'today-4px'}})
    expect(wrapper.text()).toContain('国内发货')
    expect(wrapper.text()).toContain('单个异常订单不会阻断其他正常订单')
    expect(wrapper.find('a[href="/logistics/china/export/4px"]').exists()).toBe(true)
    expect(wrapper.get('.provider-grid').text()).not.toContain('纬狮')
    expect(wrapper.text()).not.toContain('Fail Closed')
    expect(wrapper.find('a[href="/logistics/china/export/weship"]').exists()).toBe(false)
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
  })


  it('shows only the matching provider operation card on provider-specific daily tabs', () => {
    const speeda=mount(Index,{props:{...baseProps,view:'today-4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speeda.text()).toContain('速达')
    expect(speeda.text()).toContain('所有订单按现有数据填写 SKU / 69条码（空缺留空）')
    expect(speeda.get('.provider-grid').text()).not.toContain('纬狮')
    expect(speeda.find('a[href="/logistics/china/export/4px"]').exists()).toBe(true)

    const weship=mount(Index,{props:{...baseProps,view:'today-weship',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weship.text()).toContain('纬狮')
    expect(weship.get('.provider-grid').text()).not.toContain('速达')
    expect(weship.text()).toContain('纬狮独立保留原 24 列模板')
    expect(weship.text()).not.toContain('Fail Closed')
    expect(weship.text()).toContain('下载纬狮下单表')
    expect(weship.text()).not.toContain('上传纬狮单号表')
    expect(weship.find('a[href="/logistics/china/export/weship"]').exists()).toBe(true)
  })

  it('R4-5B preserves one Weship download, compact Grid and explicit old-table fallback',()=>{
    const speeda=mount(Index,{props:{...baseProps,view:'today-4px',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true}})
    expect(speeda.find('.domestic-compact-workspace').exists()).toBe(true)
    expect(speeda.find('.continuity').exists()).toBe(false)
    expect(speeda.find('.compact-workspace-help').text()).toContain('27 列模板')
    expect(speeda.findAll('.provider-grid a').map(a=>a.attributes('href')))
      .toEqual(['/logistics/china/export/4px'])
    expect(speeda.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(speeda.find('input[type="file"]').exists()).toBe(false)

    const weship=mount(Index,{props:{...baseProps,view:'today-weship',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true}})
    expect(weship.find('.domestic-compact-workspace').exists()).toBe(true)
    expect(weship.find('.compact-workspace-help').text()).toContain('纬狮')
    expect(weship.findAll('.provider-grid a').map(a=>a.attributes('href')))
      .toEqual(['/logistics/china/export/weship'])
    expect(weship.find('.table-wrap.orders table').exists()).toBe(false)
    expect(weship.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(weship.find('input[type="file"]').exists()).toBe(false)
    const fallback=mount(Index,{props:{...baseProps,view:'today-weship',
      globalGridPilotAvailable:true,globalGridPilotEnabled:false}})
    expect(fallback.find('.table-wrap.orders table').exists()).toBe(true)
    expect(fallback.find('a[href="/logistics/china/export/weship"]').exists()).toBe(true)
  })

  it('R4-6 exceptions keeps compact read-only Grid, one footer and explicit native fallback',()=>{
    const exception={...baseProps.rows.data[0],exception_type:'进度冲突',
      exception_reason:'物流已发货，但缺少发货时间',shipment_status:'shipped',review_status:'conflict' as const}
    const grid=mount(Index,{props:{...baseProps,view:'exceptions',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true,
      rows:{...baseProps.rows,data:[exception]},canArchive:false}})
    expect(grid.find('.domestic-compact-workspace').exists()).toBe(true)
    expect(grid.find('.table-wrap.orders table').exists()).toBe(false)
    expect(grid.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(grid.find('input[type="file"]').exists()).toBe(false)
    expect(grid.findAll('.provider-grid a')).toHaveLength(0)
    expect(grid.findAll('[data-testid="domestic-global-grid-toggle"]')).toHaveLength(1)
    expect(grid.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('返回原表')
    const native=mount(Index,{props:{...baseProps,view:'exceptions',
      globalGridPilotAvailable:true,globalGridPilotEnabled:false,
      rows:{...baseProps.rows,data:[exception]},canArchive:false}})
    expect(native.find('.table-wrap.orders table').exists()).toBe(true)
    expect(native.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('切换新版表')
  })

  it('R4-7A 速达历史默认 Grid：直接业务操作，无原操作或重复表，回退原表仍可用',()=>{
    const speeda=mount(Index,{props:{...baseProps,view:'all',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true,
      trackingImportProvider:null,trackingImportPreview:null,canTrackingSync:true}})
    expect(speeda.find('.domestic-compact-workspace').exists()).toBe(true)
    expect(speeda.find('.table-wrap.orders table').exists()).toBe(false)
    expect(speeda.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(speeda.find('input[type="file"]').exists()).toBe(false)
    expect(speeda.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('返回原表')
    expect(speeda.findAll('.provider-grid a')).toHaveLength(0)

    const fallback=mount(Index,{props:{...baseProps,view:'all',
      globalGridPilotAvailable:true,globalGridPilotEnabled:false,
      trackingImportProvider:null,trackingImportPreview:null}})
    expect(fallback.find('.table-wrap.orders table').exists()).toBe(true)
    expect(fallback.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('切换新版表')

    const weship=mount(Index,{props:{...baseProps,view:'all',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true,
      trackingImportProvider:null,trackingImportPreview:null,canTrackingSync:true}})
    expect(weship.find('.domestic-compact-workspace').exists()).toBe(true)
    expect(weship.find('.table-wrap.orders table').exists()).toBe(false)
    expect(weship.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(weship.find('input[type="file"]').exists()).toBe(false)
    expect(weship.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('返回原表')
    expect(weship.findAll('.provider-grid a')).toHaveLength(0)

    const weshipFallback=mount(Index,{props:{...baseProps,view:'all',
      globalGridPilotAvailable:true,globalGridPilotEnabled:false,
      trackingImportProvider:null,trackingImportPreview:null}})
    expect(weshipFallback.find('.table-wrap.orders table').exists()).toBe(true)
    expect(weshipFallback.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('切换新版表')
  })

  it('shows a mixed-carrier ERP batch download/upload only on the pending work queue', () => {
    const pending=mount(Index,{props:{...baseProps,view:'pending',trackingImportPreview:null,trackingImportProvider:null}})
    expect(pending.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(true)
    expect(pending.get('[data-testid="download-domestic-sku-merged"]').attributes('href')).toBe('/logistics/china/pending-batch/export-sku-merged')
    expect(pending.text()).toContain('⏫上传物流单号')
    expect(pending.find('input[type="file"]').attributes('accept')).toBe('.xls,.xlsx')
    expect(pending.text()).toContain('自动识别格式')
    expect(pending.text()).toContain('不改变物流商下单模板')

    const history=mount(Index,{props:{...baseProps,view:'all',trackingImportPreview:null,trackingImportProvider:null}})
    expect(history.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(false)
    expect(history.find('[data-testid="download-domestic-sku-merged"]').exists()).toBe(false)
    expect(history.text()).not.toContain('⏫上传物流单号')
  })

  it('R4-7B cumulative Weship has the same direct guarded compact actions and no duplicate toolbars',()=>{
    const view=mount(Index,{props:{...baseProps,view:'all',
      globalGridPilotAvailable:true,globalGridPilotEnabled:true,
      canReview:true,canOperate:true,canTrackingSync:true,canArchive:true,
      trackingImportProvider:null,trackingImportPreview:null}})
    expect(view.get('[data-testid="domestic-global-grid-toggle"]').text()).toBe('返回原表')
    expect(view.find('.table-wrap.orders table').exists()).toBe(false)
    expect(view.find('[data-testid="domestic-native-batch-toggle"]').exists()).toBe(false)
    expect(view.find('input[type="file"]').exists()).toBe(false)
    expect(view.text()).not.toContain('上传纬狮单号表')
    expect(view.find('.provider-grid').exists()).toBe(false)
    // The shared DOMESTIC Grid is not reimplemented; the separate provider
    // namespace stores independent column order, pin=0 and visibility.
    expect(view.findAll('.domestic-grid-main')).toHaveLength(1)
  })

  it('keeps cumulative carrier history query-only and hides provider upload/download controls', () => {
    const speedaHistory=mount(Index,{props:{...baseProps,view:'all',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speedaHistory.find('.provider-grid').exists()).toBe(false)
    expect(speedaHistory.find('a[href="/logistics/china/export/4px"]').exists()).toBe(false)
    expect(speedaHistory.find('input[type="file"]').exists()).toBe(false)

    const weshipHistory=mount(Index,{props:{...baseProps,view:'all',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weshipHistory.find('.provider-grid').exists()).toBe(false)
    expect(weshipHistory.text()).not.toContain('上传纬狮单号表')
  })

  it('applies only preview safe rows and reports queued Woo sync separately', async () => {
    const wrapper=mount(Index,{props:baseProps})
    expect(wrapper.text()).toContain('可应用 1 · 重复 0 · 阻断 1')
    const api=vi.fn().mockResolvedValue({ok:true,redirected:false,json:async()=>({
      applied_count:1,noop_count:0,blocked_count:1,failed_count:0,sync_pending_count:1,
    })})
    vi.stubGlobal('fetch',api)
    const button=wrapper.findAll('button').find(b=>b.text().includes('确认发货（仅可应用订单）'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    await flushPromises()
    expect(api).toHaveBeenCalledWith('/logistics/china/4px/tracking-import/apply',
      expect.objectContaining({method:'POST',body:JSON.stringify({token:'a'.repeat(64)})}))
    expect(routerReload).toHaveBeenCalled()
    expect(feedbackSpy).toHaveBeenCalledWith(expect.objectContaining({
      state:'SUCCESS',message:expect.stringContaining('尚不代表官网已同步'),
    }))
  })

  it('routes review approval and the Vita allocation queue without duplicating allocation logic', async () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'review',rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],review_status:'pending' as const}]}}})
    expect(wrapper.find('a[href="/logistics/wait-stock"]').text()).toContain('待分配')
    await wrapper.find('button.approve').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/approve', {}, expect.objectContaining({preserveScroll:true,preserveState:true}))
  })

  it('renders stockout tab adjacent to review and handles stockout or recovery', async () => {
    const unreviewed={...baseProps.rows.data[0],review_status:'pending' as const}
    const wrapper=mount(Index,{props:{...baseProps,view:'review',
      rows:{...baseProps.rows,data:[unreviewed]}}})
    const nav=wrapper.findAll('nav.tabs button').map(button=>button.text())
    expect(nav[0]).toContain('待审核')
    expect(nav[1]).toContain('缺货单')
    expect(nav[2]).toContain('外采单')
    expect(nav[3]).toContain('待发货')
    await wrapper.find('button.stockout-btn').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/stockout', {},
      expect.objectContaining({preserveScroll:true,preserveState:true}))

    const stockout=mount(Index,{props:{...baseProps,view:'stockout',
      rows:{...baseProps.rows,data:[{...unreviewed,review_status:'stockout' as const}]}}})
    expect(stockout.find('button.stockout-btn').exists()).toBe(false)
    await stockout.find('button.approve').trigger('click')
    expect(stockout.findAll('.orders tbody tr:first-child button').map(button=>button.text())).toEqual(['已到货-可发货','进待分仓'])
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/restock', {},
      expect.objectContaining({preserveScroll:false,onFinish:expect.any(Function)}))
  })

  it('requires a rejection reason and hides business review from logistics-only accounts', async () => {
    const pending={...baseProps.rows.data[0],review_status:'pending' as const}
    const wrapper=mount(Index,{props:{...baseProps,view:'review',
      rows:{...baseProps.rows,data:[pending]}}})
    await wrapper.find('button.reject-btn').trigger('click')
    expect(wrapper.text()).toContain('驳回到 Vita 待分仓')
    const confirm=wrapper.findAll('button.reject-btn').at(-1)!
    expect(confirm.attributes('disabled')).toBeDefined()
    await wrapper.find('textarea').setValue('收件地址错误，需要 Vita 确认')
    await confirm.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/reject',
      {reason:'收件地址错误，需要 Vita 确认'}, expect.objectContaining({preserveScroll:true,preserveState:true}))

    const logisticsOnly=mount(Index,{props:{...baseProps,view:'review',canReview:false,
      rows:{...baseProps.rows,data:[pending]}}})
    expect(logisticsOnly.find('button.approve').exists()).toBe(false)
    expect(logisticsOnly.find('button.stockout-btn').exists()).toBe(false)
    expect(logisticsOnly.find('button.reject-btn').exists()).toBe(false)
  })

  it('keeps batch sync contextual and removes the duplicate header action', async () => {
    const shipped={...baseProps.rows.data[0],source_type:'woocommerce',tracking_sync_status:'pending',
      shipment_status:'shipped',tracking_number:'4PX-TRACK-1',shipped_at:'2026-10-29T00:00:00Z'}
    const wrapper=mount(Index,{props:{...baseProps,canTrackingSync:true,
      rows:{...baseProps.rows,data:[shipped]}}})
    expect(wrapper.text()).toContain('官网同步')
    expect(wrapper.find('.domestic-head-actions button').exists()).toBe(false)
    const checkbox=wrapper.find('input[aria-label^="选择同步"]')
    expect(checkbox.exists()).toBe(true)
    await checkbox.setValue(true)
    const batch=wrapper.findAll('button').find(b=>b.text()==='批量同步官网物流')
    expect(batch).toBeTruthy()
    await batch!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/tracking-outbound/selected',
      {order_ids:[1],manual_override:false},expect.objectContaining({preserveScroll:true}))
    expect(mount(Index,{props:{...baseProps,canTrackingSync:false}}).text()).not.toContain('同步官网物流')
    expect(mount(Index,{props:{...baseProps,canTrackingSync:true}}).find('input[aria-label^="选择同步"]').exists()).toBe(false)

    const conflict={...shipped,tracking_sync_status:'conflict',tracking_sync_reason:'官网已有相同 Tracking，但物流商不一致'}
    const conflictWrapper=mount(Index,{props:{...baseProps,canTrackingSync:true,rows:{...baseProps.rows,data:[conflict]}}})
    expect(conflictWrapper.text()).toContain('冲突待核对')
    expect(conflictWrapper.find('input[aria-label^="选择同步"]').exists()).toBe(false)
    expect(conflictWrapper.findAll('tbody button').filter(b=>b.text().includes('同步官网物流'))).toHaveLength(0)
  })
  it('creates a warehouse-list carrier with 默认 and waits for explicit row confirmation',async()=>{
    const post=vi.spyOn(axios,'post').mockResolvedValue({data:{carrier:{value:'德邦物流',label:'德邦物流',default_service_value:'默认',services:[{value:'默认',label:'默认'}]}}})
    try{
      const wrapper=mount(Index,{props:baseProps})
      const carrier=wrapper.get('select[aria-label^="选择物流商"]')
      expect(carrier.findAll('option').at(-1)?.text()).toContain('添加物流商')
      await carrier.setValue('__add_carrier__')
      expect(wrapper.find('[role="dialog"][aria-label="添加物流商"]').exists()).toBe(true)
      await wrapper.get('input[placeholder="例如：德邦物流"]').setValue('德邦物流')
      await wrapper.get('.domestic-inline-carrier-dialog form').trigger('submit')
      await flushPromises()
      expect(post).toHaveBeenCalledWith('/logistics/carriers',{name:'德邦物流',name_en:null},expect.objectContaining({headers:{Accept:'application/json'}}))
      expect((carrier.element as HTMLSelectElement).value).toBe('德邦物流')
      await wrapper.setProps({carrierOptions:[...baseProps.carrierOptions,{value:'德邦物流',label:'德邦物流',services:[{value:'默认',label:'默认'}]}]})
      expect([...((carrier.element as HTMLSelectElement).options)].filter(option=>option.value==='德邦物流')).toHaveLength(1)
      expect((wrapper.get('select[aria-label^="选择服务类型"]').element as HTMLSelectElement).value).toBe('默认')
      expect(routerPost).not.toHaveBeenCalled()
      await wrapper.get('.domestic-service-entry button').trigger('click')
      expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/carrier-service',expect.objectContaining({carrier:'德邦物流',service:'默认'}),expect.any(Object))
      expect(routerPost).toHaveBeenCalledTimes(1)
    }finally{post.mockRestore()}
  })
  it('preserves warehouse selection on carrier-create validation failure and restricts edit permissions',async()=>{
    const post=vi.spyOn(axios,'post').mockRejectedValue({isAxiosError:true,response:{status:422,data:{errors:{name:['物流商已存在']}}}})
    try{
      const wrapper=mount(Index,{props:baseProps})
      const carrier=wrapper.get('select[aria-label^="选择物流商"]')
      await carrier.setValue('__add_carrier__')
      await wrapper.get('input[placeholder="例如：德邦物流"]').setValue('速达')
      await wrapper.get('.domestic-inline-carrier-dialog form').trigger('submit')
      await flushPromises()
      expect(wrapper.get('.domestic-inline-carrier-dialog [role="alert"]').text()).toContain('物流商已存在')
      expect((carrier.element as HTMLSelectElement).value).toBe('4PX')
      expect(routerPost).not.toHaveBeenCalled()
      const readonly=mount(Index,{props:{...baseProps,canOperate:false}})
      expect(readonly.find('select[aria-label^="选择物流商"]').exists()).toBe(false)
      expect(readonly.find('.domestic-inline-carrier-dialog').exists()).toBe(false)
    }finally{post.mockRestore()}
  })
  it('looks up the exact EU parcel barcode inline without altering carrier exports', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok:true, json:async () => ({items:[{sku:'M2',quantity:1,barcode_69:'6977291390537'}]}),
    })
    vi.stubGlobal('fetch', fetchMock)
    try {
      const wrapper = mount(Index,{props:baseProps})
      const button = wrapper.findAll('button').find(b=>b.text()==='69条码')
      expect(button).toBeTruthy()
      await button!.trigger('click')
      await flushPromises()
      expect(fetchMock).toHaveBeenCalledWith(
        '/logistics/china/orders/1/barcode-69',
        expect.objectContaining({headers:{Accept:'application/json'}}),
      )
      expect(wrapper.text()).toContain('6977291390537')
      expect(wrapper.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(true)
      const outside = mount(Index,{props:{...baseProps,rows:{...baseProps.rows,
        data:[{...baseProps.rows.data[0],country:'US'}]}}})
      expect(outside.findAll('button').some(b=>b.text()==='69条码')).toBe(false)
    } finally {vi.unstubAllGlobals()}
  })

})

it('keeps the status dropdown editable across domestic review, pending, stockout and provider views', () => {
  const active = {
    ...baseProps.rows.data[0], workflow_revision:5, can_edit_workflow_status:true, order_kind:'customer',
    workflow_status_projection:{code:'sending',en:'Sending',zh:'待发',color:'neutral',source:'canonical_projection'},
  }
  const options = [
    {code:'sending',en:'Sending',zh:'待发',color:'neutral',mutable:true},
    {code:'missing_info',en:'Missing Info',zh:'信息缺失',color:'amber',mutable:true},
  ]
  for (const view of ['review','pending','stockout','today','today-4px','today-weship','exceptions','all','outsource']) {
    const row = view === 'outsource' ? {...active, procurement:{
      id:90, number:'PO-T1',status:'ordered',carrier:null,tracking_number:null,received_at:null,released_at:null,
      items:[{id:19,sku:'MF414A',quantity:1,purchase_quantity:1,purchase_url:null,purchase_size:null,received_quantity:0,received_sku:null}],
    }} : active
    const page = mount(Index, { props:{
      ...baseProps, view, globalGridPilotEnabled:false,
      rows:{...baseProps.rows,data:[row]},canEditWorkflowStatus:true,workflowStatusOptions:options,
    } })
    expect(page.find('select[aria-label^="订单工作状态"]').exists(),view).toBe(true)
  }
  const readonly = mount(Index,{props:{
    ...baseProps, view:'pending',globalGridPilotEnabled:false,
    canEditWorkflowStatus:false,workflowStatusOptions:options,
    rows:{...baseProps.rows,data:[active]},
  }})
  expect(readonly.find('select[aria-label^="订单工作状态"]').exists()).toBe(false)
  expect(readonly.find('.order-workflow-status').text()).toContain('待发')
})
