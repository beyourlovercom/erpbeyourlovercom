import {mount} from '@vue/test-utils'
import {describe,expect,it,vi} from 'vitest'
import DomesticGridPendingRouteCell from './DomesticGridPendingRouteCell.vue'

const carriers=[
  {value:'4PX',label:'速达 4PX',default_service_value:'SD经济挂号-带电',services:[
    {value:'SD经济挂号-带电',label:'经济挂号-带电'},
    {value:'SD标准挂号-带电',label:'标准挂号-带电'},
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
