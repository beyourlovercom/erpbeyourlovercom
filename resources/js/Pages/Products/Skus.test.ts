import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Skus from './Skus.vue'
import SkuMappingGridCell from './SkuMappingGridCell.vue'
import GlobalGrid from '../../Components/DataGrid/GlobalGrid.vue'
import { router } from '@inertiajs/vue3'

vi.mock('@inertiajs/vue3',()=>({
  Head:{template:'<span/>'},router:{get:vi.fn(),put:vi.fn(),reload:vi.fn()},
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue',()=>({default:{template:'<div><slot/></div>'}}))
vi.mock('../../Components/DataGrid/OmsGrid.vue',()=>({default:{
  name:'OmsGrid',props:['namespace','columns','rows','serverPagination','serverSort','serverSearch','requiredColumnIds','preferenceScope'],
  emits:['page-change','page-size-change','search-change','sort-change'],
  template:'<div data-testid="actual-shared-grid-contract"><slot name="actions"/></div>',
}}))
const product={id:5,sku:'TEST-A',is_active:true,material_name:null,image_url:'/products/media/1',
  material_versions:[{id:1,material_code:'001-A',material_name:'完整名称 A'},{id:2,material_code:'002-A',material_name:'完整名称 B'}],
  website_skus:['SITE-A'],mappings:{FACTORY:{warehouse_id:5,sku:'TEST-A',status:'factory_default'},DE:{warehouse_id:6,sku:'DE-A',status:'verified'}},alias_count:3}
const props={products:{data:[product],current_page:1,last_page:3,total:101,per_page:50,links:[]},
  filters:{search:'',missing:''},unmappedMaterialCount:1,unmappedMaterials:[{id:3,material_code:'RAW-A',material_name:'待绑定物料',factory_physical_qty:1}],
  productOptions:[{id:5,sku:'TEST-A'}],canOperate:true,canApproveStock:true}

describe('SKU matrix shared Grid regression',()=>{
  it('uses shared grid by default, all twelve columns, and server pagination',async()=>{
    const w=mount(Skus,{props});await flushPromises()
    const grid=w.findComponent(GlobalGrid)
    expect(grid.exists()).toBe(true)
    expect(w.find('table').exists()).toBe(false)
    expect(grid.props('columns').map((c:any)=>c.headerName)).toEqual(['产品图片','ERP SKU','库存表代码','库存表名称','网站 SKU','中国工厂','德国仓','加拿大仓','美西 MorePlus','美东','美世迈仓','别名数量'])
    expect(grid.props('rows')[0].material_code).toBe('001-A / 002-A')
    expect(grid.props('page')).toEqual({current_page:1,last_page:3,total:101,per_page:50})
    expect(w.findComponent({name:'OmsGrid'}).props('preferenceScope')).toBe('account')
    grid.vm.$emit('page-size-change',100)
    expect(router.get).toHaveBeenLastCalledWith('/products/skus',expect.objectContaining({per_page:100,page:1}),expect.anything())
    grid.vm.$emit('search-change','001-A')
    expect(router.get).toHaveBeenLastCalledWith('/products/skus',expect.objectContaining({search:'001-A',page:1}),expect.anything())
    grid.vm.$emit('sort-change',{colId:'alias_count',direction:'desc'})
    expect(router.get).toHaveBeenLastCalledWith('/products/skus',expect.objectContaining({sort:'alias_count',direction:'desc'}),expect.anything())
    w.unmount()
  })
  it('preserves multi-version detail, edit, warehouse and unbound actions',async()=>{
    const w=mount(Skus,{props});await flushPromises()
    const grid=w.findComponent(GlobalGrid);const columns=grid.props('columns') as any[]
    const row=grid.props('rows')[0]
    columns.find(c=>c.colId==='material_code').cellRendererParams.activate(row)
    await w.vm.$nextTick()
    expect(w.get('[role=dialog]').text()).toContain('002-A')
    await w.get('[role=dialog]').findAll('button').find(b=>b.text()==='002-A')!.trigger('click')
    expect(w.get('[role=dialog]').text()).toContain('物料版本核实')
    await w.get('[role=dialog]').findAll('button').find(b=>b.text()==='取消')!.trigger('click')
    columns.find(c=>c.colId==='DE').cellRendererParams.activate(row)
    await w.vm.$nextTick()
    expect(w.get('[role=dialog]').text()).toContain('德国仓 SKU 核实')
    await w.get('[role=dialog]').findAll('button').find(b=>b.text()==='取消')!.trigger('click')
    await w.findAll('button').find(b=>b.text().startsWith('待绑定物料 ·'))!.trigger('click')
    await flushPromises()
    expect(w.findComponent(GlobalGrid).props('namespace')).toBe('grid.products.sku-unbound.v1')
    expect(w.find('table').exists()).toBe(false)
    w.unmount()
  })
  it('does not expose edit or add controls when permission is denied',async()=>{
    const w=mount(Skus,{props:{...props,canOperate:false}});await flushPromises()
    const grid=w.findComponent(GlobalGrid);const cols=grid.props('columns') as any[]
    const row=grid.props('rows')[0]
    expect(cols.find(c=>c.colId==='material_code').cellRendererParams.canAdd(row)).toBe(false)
    expect(cols.find(c=>c.colId==='DE').cellRendererParams.canActivate(row)).toBe(false)
    expect(w.text()).not.toContain('Excel 批量维护')
    w.unmount()
  })
  it('cell buttons dispatch existing callbacks and reject unsafe image URLs',async()=>{
    const activate=vi.fn(),add=vi.fn()
    const cell=mount(SkuMappingGridCell,{props:{params:{data:{id:5},value:'001-A',mode:'text',activate,canAdd:()=>true,add}}})
    await cell.get('.sku-grid-link').trigger('click');await cell.get('.sku-grid-add').trigger('click')
    expect(activate).toHaveBeenCalledWith({id:5});expect(add).toHaveBeenCalledWith({id:5})
    const image=mount(SkuMappingGridCell,{props:{params:{value:'//invalid.example/image',mode:'image',activate}}})
    expect(image.find('img').exists()).toBe(false)
  })
})
