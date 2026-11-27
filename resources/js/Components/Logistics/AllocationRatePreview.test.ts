import {flushPromises,mount} from '@vue/test-utils'
import {afterEach,describe,expect,it,vi} from 'vitest'
import AllocationRatePreview from './AllocationRatePreview.vue'

const props={orderId:10,revision:'1',warehouses:[{code:'US-NY',name:'美东纽约仓'},{code:'US-LA',name:'美西 MorePlus'}]}
const result={status:'reference',country:'US',as_of:'2026-11-27',measurement:{weight_kg:1,dimensions_cm:[20,15,10],item_count:2,basis:'stacked_packaging_estimate'},rows:[
  {warehouse_code:'US-NY',carrier:'USPS',service:'ground_advantage',version_id:4,version_number:2,status:'draft_reference',reason:'unapproved_transport_reference',amount_cny:37.5,native_totals:{USD:5},extra_item_usd:1,components:[{component_type:'transport',amount:5,currency:'USD',source_sheet:'USPS尾程',source_cells:'C7'}]},
  {warehouse_code:'US-LA',carrier:null,service:null,status:'unavailable',reason:'rate_scope_missing',amount_cny:null,native_totals:null,extra_item_usd:null}
]}
afterEach(()=>vi.unstubAllGlobals())
describe('Allocation rate preview',()=>{
  it('shows conditional source estimate, not missing price as zero',async()=>{
    const f=vi.fn().mockResolvedValue({ok:true,json:async()=>result});vi.stubGlobal('fetch',f)
    const w=mount(AllocationRatePreview,{props});await flushPromises()
    expect(w.text()).toContain('¥37.50');expect(w.text()).toContain('USD 5.00')
    expect(w.text()).toContain('额外件操作参考 USD 1.00')
    expect(w.text()).toContain('无对应仓库报价')
    expect(w.text()).toContain('不参与自动分仓排名')
    expect(w.text()).toContain('USPS尾程 · C7')
    expect(f.mock.calls[0][0]).toBe('/logistics/orders/10/fulfillment-allocation/rate-preview')
    await w.setProps({disabled:true});await flushPromises()
    expect(w.text()).not.toContain('¥37.50');expect(w.text()).toContain('暂不展示整单运费')
    expect(f).toHaveBeenCalledTimes(1);w.unmount()
  })
  it('cancels stale order response and reloads exact selected order',async()=>{
    let complete:(x:unknown)=>void=()=>{}
    const f=vi.fn().mockImplementationOnce(()=>new Promise(r=>{complete=r})).mockResolvedValueOnce({ok:true,json:async()=>({status:'unavailable',reason:'missing_packaging_standard',rows:[]})})
    vi.stubGlobal('fetch',f)
    const w=mount(AllocationRatePreview,{props});await w.setProps({orderId:11});await flushPromises()
    expect(f.mock.calls[0][1].signal.aborted).toBe(true)
    complete({ok:true,json:async()=>result});await flushPromises()
    expect(w.text()).toContain('缺少已审核的物流包装重量/尺寸')
    expect(w.text()).not.toContain('¥37.50');w.unmount()
  })
  it('confirms only the exact approved route and version',async()=>{
    const row={...result.rows[0],status:'unavailable',reason:'eligibility_unconfirmed',amount_cny:null,native_totals:null}
    const f=vi.fn().mockResolvedValue({ok:true,json:async()=>({...result,rows:[row]})});vi.stubGlobal('fetch',f)
    const w=mount(AllocationRatePreview,{props});await flushPromises()
    await w.get('.rate-money button').trigger('click');await flushPromises()
    expect(f.mock.calls[1][0]).toContain('confirmed_scope=US-NY%7CUSPS%7Cground_advantage%7C4');w.unmount()
  })
})
