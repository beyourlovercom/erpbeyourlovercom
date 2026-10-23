import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
vi.mock('@inertiajs/vue3',()=>({Head:{template:'<span />'},Link:{props:['href'],template:'<a :href="href"><slot /></a>'}}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue',()=>({default:{template:'<div><slot /></div>'}}))
describe('canonical warehouse settings',()=>{
  it('links existing warehouse master configuration rather than a provider page',()=>{
    const wrapper=mount(Index,{props:{warehouses:[{id:1,code:'US-SM',name:'世迈仓',country_code:'US',is_active:true,url:'/inventory/us-sm?tab=config'}],autoDispatchEnabled:false}})
    expect(wrapper.text()).toContain('世迈仓')
    expect(wrapper.find('a[href="/inventory/us-sm?tab=config"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings/warehouses/xlwms"]').exists()).toBe(false)
  })
})
