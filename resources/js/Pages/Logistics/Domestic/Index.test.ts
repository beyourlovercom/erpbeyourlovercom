import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { routerPost, routerVisit } = vi.hoisted(() => ({ routerPost: vi.fn(), routerVisit: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props:['title'], template:'<title>{{ title }}</title>' },
  router: { post:routerPost, visit:routerVisit },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const baseProps = {
  view:'pending',
  rows:{ data:[{
    line:1,order_id:1,erp_order_id:'007-0923-c50001',provider_reference:'C50001-007',order_url:'/customers/orders/1',
    recipient_name:'A',phone:'123',country:'DE',state:'DE-SN',city:'Dresden',postal_code:'01219',
    carrier:'4PX',service:'SD经济挂号-带电',tracking_number:null,shipment_status:'pending',shipped_at:null,
    review_status:'approved' as const,export_blockers:[],items:[{sku:'M2',quantity:1}],
  }],current_page:1,last_page:1,total:1 },
  viewCounts:{review:0,pending:1,'today-4px':0,'today-weship':0,exceptions:0,today:0,'4px':1,weship:0},
  canOperate:true,
  canReview:true,
  trackingImportProvider:'4px' as const,
  trackingImportPreview:{
    token:'a'.repeat(64),apply_count:1,noop_count:0,blocked_count:1,
    rows:[
      {row:2,order_id:1,order_number:'C50001-007',tracking_number:'4PX-1',carrier:'4PX',provider_status:'已出货',status:'apply',message:''},
      {row:3,order_id:2,order_number:'C50002-007',tracking_number:'',carrier:'4PX',provider_status:'已出货',status:'blocked',message:'尚未完成国内物流审核'},
    ],
  },
  providerStatus:{
    '4px':{export_ready:true,return_ready:true,label:'速达 / 4PX'},
    weship:{export_ready:true,return_ready:true,label:'纬狮 / Weship',note:'纬狮独立保留原 24 列模板 · XLSX 下载 · XLS/XLSX 回传'},
  },
}

describe('Domestic logistics production workbench', () => {
  beforeEach(() => { routerPost.mockClear(); routerVisit.mockClear() })

  it('splits SKU and product quantity into separate columns on the domestic review page only', () => {
    const review = mount(Index,{props:{...baseProps,view:'review'}})
    const headers = review.findAll('.orders thead th').map(th=>th.text())
    const cells = review.findAll('.orders tbody tr:first-child td')
    expect(headers).toContain('SKU')
    expect(headers).toContain('数量')
    expect(cells[headers.indexOf('SKU')].find('div').text()).toBe('M2')
    expect(cells[headers.indexOf('数量')].text()).toBe('1')

    const pending = mount(Index,{props:{...baseProps,view:'pending'}})
    const pendingHeaders = pending.findAll('.orders thead th').map(th=>th.text())
    expect(pendingHeaders).toContain('SKU / 数量')
    expect(pendingHeaders).not.toContain('数量')
  })

  it('shows the read-only 今日订单 XLSX download before the 4PX provider button only on the aggregate today tab', () => {
    const today=mount(Index,{props:{...baseProps,view:'today'}})
    const links=today.findAll('.provider-card:first-child .actions a').map(a=>a.attributes('href'))
    expect(links.slice(0,2)).toEqual(['/logistics/china/today/export','/logistics/china/export/4px'])
    expect(today.find('[data-testid="download-today-orders"]').text()).toBe('下载今日订单')
    expect(mount(Index,{props:{...baseProps,view:'today-4px'}}).find('[data-testid="download-today-orders"]').exists()).toBe(false)
    expect(mount(Index,{props:{...baseProps,view:'today-weship'}}).find('[data-testid="download-today-orders"]').exists()).toBe(false)
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

  it('confirms actual shipment only on explicit click and hides input for unreviewed, stockout or shipped rows', async () => {
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
      expect(blocked.find('input[aria-label^="填写发货单号"]').exists()).toBe(false)
      expect(blocked.text()).not.toContain('确认发货')
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
    expect(wrapper.text()).not.toContain('纬狮 / Weship')
    expect(wrapper.text()).not.toContain('Fail Closed')
    expect(wrapper.find('a[href="/logistics/china/export/weship"]').exists()).toBe(false)
    expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('.xls,.xlsx')
  })


  it('shows only the matching provider operation card on provider-specific daily tabs', () => {
    const speeda=mount(Index,{props:{...baseProps,view:'today-4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speeda.text()).toContain('速达 / 4PX')
    expect(speeda.text()).toContain('所有订单按现有数据填写 SKU / 69条码（空缺留空）')
    expect(speeda.text()).not.toContain('纬狮 / Weship')
    expect(speeda.find('a[href="/logistics/china/export/4px"]').exists()).toBe(true)

    const weship=mount(Index,{props:{...baseProps,view:'today-weship',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weship.text()).toContain('纬狮 / Weship')
    expect(weship.text()).not.toContain('速达 / 4PX')
    expect(weship.text()).toContain('纬狮独立保留原 24 列模板')
    expect(weship.text()).not.toContain('Fail Closed')
    expect(weship.text()).toContain('下载纬狮下单表')
    expect(weship.text()).toContain('上传纬狮单号表')
    expect(weship.find('a[href="/logistics/china/export/weship"]').exists()).toBe(true)
  })

  it('shows a mixed-carrier ERP batch download/upload only on the pending work queue', () => {
    const pending=mount(Index,{props:{...baseProps,view:'pending',trackingImportPreview:null,trackingImportProvider:null}})
    expect(pending.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(true)
    expect(pending.text()).toContain('上传批量单号表')
    expect(pending.text()).toContain('可混合承运商')
    expect(pending.text()).toContain('不替代承运商自己的下单模板')

    const history=mount(Index,{props:{...baseProps,view:'4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(history.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(false)
    expect(history.text()).not.toContain('上传批量单号表')
  })

  it('keeps cumulative carrier history query-only and hides provider upload/download controls', () => {
    const speedaHistory=mount(Index,{props:{...baseProps,view:'4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speedaHistory.find('.provider-grid').exists()).toBe(false)
    expect(speedaHistory.find('a[href="/logistics/china/export/4px"]').exists()).toBe(false)
    expect(speedaHistory.find('input[type="file"]').exists()).toBe(false)

    const weshipHistory=mount(Index,{props:{...baseProps,view:'weship',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weshipHistory.find('.provider-grid').exists()).toBe(false)
    expect(weshipHistory.text()).not.toContain('上传纬狮单号表')
  })

  it('allows applying safe rows even when the preview contains blocked rows', async () => {
    const wrapper=mount(Index,{props:baseProps})
    expect(wrapper.text()).toContain('可应用 1 · 重复 0 · 阻断 1')
    const button=wrapper.findAll('button').find(b => b.text().includes('应用可安全行'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith(
      '/logistics/china/4px/tracking-import/apply',
      {token:'a'.repeat(64)},
      {preserveScroll:true},
    )
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
    expect(nav[2]).toContain('待发货')
    await wrapper.find('button.stockout-btn').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/stockout', {},
      expect.objectContaining({preserveScroll:true,preserveState:true}))

    const stockout=mount(Index,{props:{...baseProps,view:'stockout',
      rows:{...baseProps.rows,data:[{...unreviewed,review_status:'stockout' as const}]}}})
    expect(stockout.find('button.stockout-btn').exists()).toBe(false)
    await stockout.find('button.approve').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/approve', {},
      expect.objectContaining({preserveScroll:true,preserveState:true}))
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

    const conflict={...shipped,tracking_sync_status:'conflict',tracking_sync_reason:'官网已有相同 Tracking，但承运商不一致'}
    const conflictWrapper=mount(Index,{props:{...baseProps,canTrackingSync:true,rows:{...baseProps.rows,data:[conflict]}}})
    expect(conflictWrapper.text()).toContain('冲突待核对')
    expect(conflictWrapper.find('input[aria-label^="选择同步"]').exists()).toBe(false)
    expect(conflictWrapper.findAll('tbody button').filter(b=>b.text().includes('同步官网物流'))).toHaveLength(0)
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
