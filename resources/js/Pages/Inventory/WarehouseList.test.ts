import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WarehouseList from './WarehouseList.vue'
vi.mock('@inertiajs/vue3',()=>({Head:{template:'<span />'},Link:{props:['href'],template:'<a :href="href"><slot /></a>'}}))
vi.mock('../../Layouts/AuthenticatedLayout.vue',()=>({default:{template:'<div><slot /></div>'}}))
vi.mock('../../Components/DataGrid/GlobalGrid.vue',async()=>({default:(await import('../../Components/DataGrid/testing/GlobalGridHarness')).default}))
const warehouse={slug:'us-ny',public_slug:'us-ny',code:'US-NY',name:'纽约仓',name_en:'NY',country_code:'US',address:'',role_label:'海外履约',is_fulfillment_warehouse:true,ownership_type:'external' as const,is_active:true,priority:10,product_count:null,quantity_on_hand:0,inventory_state:'confirmed' as const,inventory_state_label:'已确认',last_inventory_at:null,fulfillment_available_on:null,fulfillment_state:'ready' as const,fulfillment_state_label:'可发货'}
describe('warehouse master grid migration',()=>{
 it('keeps the warehouse link, zero vs unknown and management permission',()=>{
   const w=mount(WarehouseList,{props:{warehouses:[warehouse],canManage:false}})
   expect(w.get('a[href="/inventory/us-ny"]').text()).toContain('纽约仓')
   const cells=w.findAll('tbody td')
   expect(cells[5].text()).toBe('—')
   expect(cells[6].text()).toBe('0')
   expect(w.find('a[href="/inventory/add"]').exists()).toBe(false)
 })
 it('retains the authorized warehouse creation entry',()=>{
   const w=mount(WarehouseList,{props:{warehouses:[],canManage:true}})
   expect(w.get('a[href="/inventory/add"]').text()).toContain('增加仓库')
 })
})
