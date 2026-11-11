import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const { routerReload } = vi.hoisted(() => ({ routerReload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  router: { post: vi.fn(), patch: vi.fn(), visit: vi.fn(), reload: routerReload },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const warehouse = { key:'us-moreplus', id:1, code:'US-LA', label:'MorePlus', carrier:'USPS', url:'/logistics/oversea/us-moreplus' }
const batch = { id: '101', status:'running', dry_run:false, total:3 }

describe('R3-C ShipOut hidden-tab read-only status policy', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })
  it('pauses hidden GET checks and catches up on visible, without POST', async () => {
    const requests: Array<{ url: string; method: string }> = []
    vi.stubGlobal('fetch', vi.fn(async (url: string, options: RequestInit = {}) => {
      requests.push({url:String(url), method:String(options.method ?? 'GET')})
      return {ok:true, status:200, json: async()=>({
        batch_id:101,status:'running',dry_run:false,total:3,processed:0,items:[],
      })} as Response
    }))
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const wrapper = mount(Show, { props: {
      warehouseKey:'us-moreplus', warehouse, warehouseOptions:[warehouse],
      view:'pending', rows:{ data:[], current_page:1,last_page:1,per_page:50,total:0,from:null,to:null },
      viewCounts:{review:0,pending:0,shipped:0,exceptions:0,all:0},
      canOperate:true,canReview:true,carrierOptions:[],trackingImportPreview:null,
      xlwmsOmsWebEnabled:true,shipoutProductionMinOrderId:1,
      shipoutProductionOperatorAllowed:true,shipoutProductionBatchUi:true,
      shipoutProductionLatestBatch:batch,
    }})
    await flushPromises()
    vi.advanceTimersByTime(15_000)
    await flushPromises()
    expect(requests).toHaveLength(0)
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(requests).toHaveLength(1)
    expect(requests[0].method).toBe('GET')
    expect(requests[0].url).toContain('/shipout/batch/101/status')
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    vi.advanceTimersByTime(15_000)
    await flushPromises()
    expect(requests).toHaveLength(1)
    wrapper.unmount()
    document.dispatchEvent(new Event('visibilitychange'))
    expect(requests).toHaveLength(1)
  })
})
