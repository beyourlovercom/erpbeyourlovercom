import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import OrderWarehouseRouteSelect from './OrderWarehouseRouteSelect.vue'
import OrderWarehouseRouteGridCell from './OrderWarehouseRouteGridCell.vue'

const options = [
  {code:'UNASSIGNED',name:'未分仓'},
  {code:'US-SM',name:'美国3世迈仓'},
  {code:'US-LA',name:'美西 MorePlus 仓'},
  {code:'FACTORY',name:'中国工厂'},
  {code:'TINGON',name:'合作-Tingon'},
  {code:'OUTSOURCE',name:'外采 → 中国工厂发货'},
]
const projection = {code:'UNASSIGNED',name:'未分仓',source:'manual',revision:2}

it('edits exactly one route with optimistic revision and refreshes all list classifications',async()=>{
  const patch = vi.spyOn(axios,'patch').mockResolvedValueOnce({
    data:{routing_warehouse:{code:'US-SM',name:'美国3世迈仓',source:'manual',revision:3}},
  })
  const reload = vi.spyOn(router,'reload').mockImplementation(()=>undefined)
  const wrapper = mount(OrderWarehouseRouteSelect,{props:{
    orderId:35,projection,options,canEdit:true,
  }})
  await wrapper.find('select').setValue('US-SM')
  await vi.waitFor(()=>expect(patch).toHaveBeenCalledWith('/orders/35/warehouse-route',{
    warehouse_code:'US-SM',expected_routing_revision:2,
  }))
  expect(reload).toHaveBeenCalled()
  patch.mockRestore();reload.mockRestore()
})

it('does not mutate for read-only rows or consume grid row clicks',async()=>{
  const wrapper = mount(OrderWarehouseRouteGridCell,{props:{params:{
    data:{order_id:45,routing_warehouse:projection,can_edit_warehouse_route:false},
    warehouseRouteOptions:options,canEditWarehouseRoute:true,
  }}})
  expect(wrapper.find('select').exists()).toBe(false)
  expect(wrapper.text()).toContain('未分仓')
  const click=vi.fn()
  const parent=document.createElement('div')
  document.body.appendChild(parent)
  parent.appendChild(wrapper.element)
  parent.addEventListener('click',click)
  await wrapper.trigger('click')
  expect(click).not.toHaveBeenCalled()
  parent.remove()
})

it('shows server conflict and restores the previous warehouse after a rejected edit',async()=>{
  const patch=vi.spyOn(axios,'patch').mockRejectedValueOnce({
    response:{status:409,data:{message:'所属分仓已被其他人修改，请刷新后重试。'}},
  })
  const wrapper=mount(OrderWarehouseRouteSelect,{props:{
    orderId:46,projection,options,canEdit:true,
  }})
  await wrapper.find('select').setValue('FACTORY')
  await vi.waitFor(()=>expect(wrapper.text()).toContain('所属分仓已被其他人修改'))
  expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('UNASSIGNED')
  patch.mockRestore()
})

it('uses the canonical order_id rather than the shipment row identifier',async()=>{
  const patch=vi.spyOn(axios,'patch').mockResolvedValueOnce({
    data:{routing_warehouse:{code:'TINGON',name:'合作-Tingon',source:'manual',revision:3}},
  })
  const reload=vi.spyOn(router,'reload').mockImplementation(()=>undefined)
  const wrapper=mount(OrderWarehouseRouteGridCell,{props:{params:{
    data:{id:999,order_id:65,routing_warehouse:projection,can_edit_warehouse_route:true},
    warehouseRouteOptions:options,canEditWarehouseRoute:true,
  }}})
  await wrapper.find('select').setValue('TINGON')
  await vi.waitFor(()=>expect(patch).toHaveBeenCalledWith('/orders/65/warehouse-route',{
    warehouse_code:'TINGON',expected_routing_revision:2,
  }))
  patch.mockRestore();reload.mockRestore()
})
