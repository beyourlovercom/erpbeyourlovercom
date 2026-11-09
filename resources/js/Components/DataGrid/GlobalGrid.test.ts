import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import GlobalGrid from './GlobalGrid.vue'
import GlobalGridCell from './GlobalGridCell.vue'

vi.mock('./OmsGrid.vue', () => ({
  default: {
    name:'OmsGrid',
    props:['namespace','title','columns','rows','requiredColumnIds','serverPagination',
      'rowIdentityKey','showSearch','enableSelection','allowAllRows','showClientCsvExport','preferenceScope'],
    emits:['page-change','page-size-change','sort-change','search-change','column-search-change'],
    template:'<section data-testid="underlying-oms"><slot name="actions"/></section>',
  },
}))

describe('Global Grid V1 shared contract', () => {
  const props = {
    namespace:'grid.inventory.products.global.v1',title:'产品库存',
    columns:[{colId:'sku',field:'sku',headerName:'SKU'}],
    rows:[{id:1,sku:'MF414A'}],
    requiredColumnIds:['sku'],
    page:{current_page:1,last_page:3,per_page:50,total:125},
  }
  it('reuses the only table engine with a separate preference namespace and bounded paging', () => {
    const wrapper = mount(GlobalGrid,{props})
    const underlying = wrapper.findComponent({name:'OmsGrid'})
    expect(wrapper.find('[data-testid="erp-global-grid-v1"]').exists()).toBe(true)
    expect(underlying.props('namespace')).toBe('grid.inventory.products.global.v1')
    expect(underlying.props('serverPagination')).toEqual(props.page)
    expect(underlying.props('allowAllRows')).toBe(false)
    expect(underlying.props('enableSelection')).toBe(false)
    expect(underlying.props('showClientCsvExport')).toBe(false)
    expect(underlying.props('showSearch')).toBe(false)
    expect(underlying.props('requiredColumnIds')).toEqual(['sku'])
  })
  it('forwards only data navigation intents, not business mutation actions', () => {
    const wrapper = mount(GlobalGrid,{props})
    const underlying = wrapper.findComponent({name:'OmsGrid'})
    underlying.vm.$emit('page-change',2)
    underlying.vm.$emit('page-size-change',100)
    underlying.vm.$emit('sort-change',{colId:'sku',direction:'asc'})
    expect(wrapper.emitted('page-change')?.[0]).toEqual([2])
    expect(wrapper.emitted('page-size-change')?.[0]).toEqual([100])
    expect(wrapper.emitted('sort-change')?.[0]).toEqual([{colId:'sku',direction:'asc'}])
  })
})

describe('Global Grid V1 cell links and actions', () => {
  it('rejects javascript and scheme-relative links but keeps canonical internal links', () => {
    const bad = mount(GlobalGridCell,{props:{params:{
      mode:'link',value:'unsafe',data:{id:1},hrefFor:()=> 'javascript:alert(1)',
    }}})
    expect(bad.find('a').exists()).toBe(false)
    const externalBad = mount(GlobalGridCell,{props:{params:{
      mode:'link',value:'unsafe',data:{id:1},hrefFor:()=> '//evil.example',
    }}})
    expect(externalBad.find('a').exists()).toBe(false)
    const good = mount(GlobalGridCell,{props:{params:{
      mode:'link',value:'MF414A',data:{id:4},hrefFor:r=>'/products/'+r.id,
    }}})
    expect(good.find('a').attributes('href')).toBe('/products/4')
  })
  it('renders only caller-authorized actions and dispatches button click once', async () => {
    const callback = vi.fn()
    const readOnly=mount(GlobalGridCell,{props:{params:{mode:'actions',data:{id:7},
      actionsFor:r=>[{label:'查看',href:'/purchase/orders/'+r.id}]}}})
    expect(readOnly.findAll('button')).toHaveLength(0)
    expect(readOnly.find('a').attributes('href')).toBe('/purchase/orders/7')
    const operator=mount(GlobalGridCell,{props:{params:{mode:'actions',data:{id:7},
      actionsFor:()=>[{label:'删除',onClick:callback,danger:true}]}}})
    await operator.get('button').trigger('click')
    expect(callback).toHaveBeenCalledTimes(1)
  })
})
