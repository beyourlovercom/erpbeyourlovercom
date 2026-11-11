import {mount} from '@vue/test-utils'
import {describe,expect,it,vi} from 'vitest'
import DomesticGridPendingRouteCell from './DomesticGridPendingRouteCell.vue'
import DomesticGridPendingCell from './DomesticGridPendingCell.vue'

const carriers=[
  {value:'4PX',label:'速达 4PX',default_service_value:'SD经济挂号-带电',services:[
    {value:'SD经济挂号-带电',label:'Economy Registered - Battery / SD经济挂号-带电',name_zh:'SD经济挂号-带电'},
    {value:'SD标准挂号-带电',label:'Standard Registered - Battery / SD标准挂号-带电',name_zh:'SD标准挂号-带电'},
    {value:'SD标准挂号-普货',label:'Standard Registered - General / SD标准挂号-普货',name_zh:'SD标准挂号-普货'},
  ]},
  {value:'Weship',label:'纬狮',default_service_value:'Standard',services:[
    {value:'Standard',label:'普通渠道'},
  ]},
]
const row={carrier:'4PX',service:'SD经济挂号-带电',review_status:'approved',shipment_status:'pending'}
function setup(column:'carrier'|'service', canOperate=true, data={...row}){
  const saveCarrier=vi.fn()
  const params={
    data:{id:100},column:{getColId:()=>column},carrierOptions:carriers,
    canOperate,source:()=>data,carrierBusy:()=>false,saveCarrier,
  }
  return {wrapper:mount(DomesticGridPendingRouteCell,{props:{params}}),saveCarrier}
}
describe('R4-3-F row carrier/service dropdowns',()=>{
  it('prefills 4PX and its service without requiring another confirmation',()=>{
    expect((setup('carrier').wrapper.get('select').element as HTMLSelectElement).value).toBe('4PX')
    expect((setup('service').wrapper.get('select').element as HTMLSelectElement).value).toBe('SD经济挂号-带电')
  })
  it('displays only Chinese names in Global Grid options and readonly cells while preserving service values',()=>{
    const select=setup('service').wrapper.get('select')
    expect(select.findAll('option').map(option=>option.text())).toEqual(['SD经济挂号-带电','SD标准挂号-带电','SD标准挂号-普货'])
    expect(select.findAll('option').map(option=>(option.element as HTMLOptionElement).value)).toEqual(['SD经济挂号-带电','SD标准挂号-带电','SD标准挂号-普货'])
    const readonly=setup('service',false).wrapper.get('.domestic-pending-route-readonly')
    expect(readonly.text()).toBe('SD经济挂号-带电')
    expect(readonly.text()).not.toContain('Economy Registered')
  })
  it('changing carrier selects its configured service and persists both fields',async()=>{
    const {wrapper,saveCarrier}=setup('carrier')
    await wrapper.get('select').setValue('Weship')
    expect(saveCarrier).toHaveBeenCalledOnce()
    expect(saveCarrier).toHaveBeenCalledWith(100,'Weship','Standard')
  })
  it('changing only the service saves same carrier and selected service',async()=>{
    const {wrapper,saveCarrier}=setup('service')
    await wrapper.get('select').setValue('SD标准挂号-带电')
    expect(saveCarrier).toHaveBeenCalledWith(100,'4PX','SD标准挂号-带电')
  })
  it('read-only, unapproved and shipped rows never render an editable select',()=>{
    expect(setup('carrier',false).wrapper.find('select').exists()).toBe(false)
    expect(setup('carrier',true,{...row,review_status:'pending'}).wrapper.find('select').exists()).toBe(false)
    expect(setup('service',true,{...row,shipment_status:'shipped'}).wrapper.find('select').exists()).toBe(false)
  })
})

describe('Domestic Global Grid pending shipping dialog labels',()=>{
  it('shows Chinese-only 4PX services without modifying selected channel values',async()=>{
    const wrapper=mount(DomesticGridPendingCell,{props:{params:{
      data:{id:100,order_number:'C100-007',review_status:'approved',shipment_status:'pending'},
      carrierOptions:carriers,canReview:true,canOperate:true,source:()=>({...row,order_id:100,carrier_ready:true,tracking_number:null}),
      carrierBusy:()=>false,trackingBusy:()=>false,feedback:()=>'',
      saveCarrier:vi.fn(),saveTracking:vi.fn(),confirmShip:vi.fn(),reopenPending:vi.fn(),
      canTrackingSync:false,syncEligible:()=>false,syncBusy:()=>false,syncTracking:vi.fn(),
    }}})
    await wrapper.get('button.pending-edit').trigger('click')
    const selects=Array.from(document.body.querySelectorAll('select[aria-label="服务类型"]')) as HTMLSelectElement[]
    expect(selects).toHaveLength(1)
    expect(Array.from(selects[0].options).map(option=>option.text)).toEqual(['请选择服务类型','SD经济挂号-带电','SD标准挂号-带电','SD标准挂号-普货'])
    expect(Array.from(selects[0].options).slice(1).map(option=>option.value)).toEqual(['SD经济挂号-带电','SD标准挂号-带电','SD标准挂号-普货'])
    wrapper.unmount()
  })
})
