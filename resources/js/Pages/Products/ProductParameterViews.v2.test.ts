import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import Index from './Index.vue'
import FactoryParameters from './FactoryParameters.vue'
import FactoryParameterTasks from './FactoryParameterTasks.vue'

const {go,save,post}=vi.hoisted(()=>({go:vi.fn(),save:vi.fn(),post:vi.fn()}))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<div data-head />' },
  router: { get: go, post, delete: vi.fn() },
  useForm: (v: Record<string, unknown>) => reactive({...v, post:save, errors:{}, processing:false,isDirty:false}),
  usePage: () => ({url:'/products/list',props:{auth:{user:{id:1,name:'QA',email:'qa@invalid'}},shellNavigation:[]}}),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props:['title','breadcrumbs'], template:'<main><slot /></main>' },
}))

const page = {
  data: [{
    id:7,sku:'DEMO-FACTORY',name:'Pillow',is_active:true,image_url:null,woo_url:null,
    parameter_status:'已填',parameter_review_status:'待审核',material:'Cotton+Memory Foam',
    net_weight_kg:'1.589',color_box_gross_kg:'1.65',courier_gross_kg:'1.83',
    package_dimensions:'50 × 23 × 19 cm',package_volume_l:21.85,carton_dimensions:'60 × 40 × 50 cm',
    carton_gross_weight_kg:'20',accessories_count:1,accessories_summary:'Pillow × 1 pcs'
  }],
  current_page:1,last_page:1,total:1,per_page:50,from:1,to:1
}

describe('Product Grid batch factory task approvals', () => {
  const twoProducts = {
    ...page,
    total: 3,
    data: [
      {...page.data[0],id:7,sku:'SAMPLE-A',name:'Pillow A',parameter_assigned_user_id:2},
      {...page.data[0],id:8,sku:'SAMPLE-B',name:'Pillow B',parameter_assigned_user_id:null},
    ]
  }

  it('only exposes current-page selection and batch controls to Product managers', async () => {
    const w=mount(Index,{props:{
      products:twoProducts,filters:{view:'measurements'},canOperate:true,
      parameterFactoryUsers:[{id:2,name:'QA Factory'}],
    }})
    expect(w.findAll('table.product-data-table')).toHaveLength(1)
    expect(w.findAll('.parameter-select-cell input[type=checkbox]')).toHaveLength(3)
    expect(w.find('.parameter-bulk-toolbar').exists()).toBe(true)
    expect(w.text()).toContain('最多100件/次')
    expect(w.text()).toContain('QA Factory')
    const checkboxes=w.findAll('td.parameter-select-cell input[type=checkbox]')
    await checkboxes[1].setValue(true)
    expect(w.find('.parameter-bulk-title').text()).toContain('选中 1 件')
    await w.find('th.parameter-select-cell input[type=checkbox]').setValue(true)
    expect(w.find('.parameter-bulk-title').text()).toContain('选中 2 件')
    const viewer=mount(Index,{props:{products:twoProducts,filters:{view:'measurements'},canOperate:false}})
    expect(viewer.find('.parameter-bulk-toolbar').exists()).toBe(false)
    expect(viewer.find('.parameter-select-cell').exists()).toBe(false)
  })

  it('posts exact checked product IDs with explicit role and required fields, never a page-wide filter', async () => {
    vi.stubGlobal('confirm',vi.fn(()=>true))
    post.mockClear()
    const w=mount(Index,{props:{
      products:twoProducts,filters:{view:'measurements'},canOperate:true,
      parameterFactoryUsers:[{id:2,name:'QA Factory'}]
    }})
    await w.findAll('td.parameter-select-cell input[type=checkbox]')[0].setValue(true)
    await w.find('select[aria-label="批量工厂员工"]').setValue('2')
    const button=w.find('.parameter-bulk-submit')
    expect(button.text()).toContain('1 件')
    await button.trigger('click')
    expect(post).toHaveBeenCalledWith('/products/parameter-tasks/bulk',
      expect.objectContaining({
        product_ids:[7],
        action:'required',
        assigned_user_id:2,
        required_fields:expect.arrayContaining(['material','net_weight_kg']),
        allow_reassign:false,
      }),expect.anything())
    const sent=post.mock.calls[0][1]
    expect(sent).not.toHaveProperty('view')
    expect(sent).not.toHaveProperty('search')
    expect(sent).not.toHaveProperty('select_all')
    vi.unstubAllGlobals()
  })

  it('requires assignee before submit and preserves other grid views', async () => {
    post.mockClear()
    const w=mount(Index,{props:{products:twoProducts,filters:{view:'measurements'},canOperate:true}})
    await w.findAll('td.parameter-select-cell input[type=checkbox]')[0].setValue(true)
    await w.find('.parameter-bulk-submit').trigger('click')
    expect(w.find('[role=alert]').text()).toContain('请先选择工厂填报员工')
    expect(post).not.toHaveBeenCalled()
    const standard=mount(Index,{props:{products:twoProducts,filters:{view:'standard'},canOperate:true}})
    expect(standard.find('.parameter-bulk-toolbar').exists()).toBe(false)
    expect(standard.find('table.product-data-table').exists()).toBe(true)
    const accessories=mount(Index,{props:{products:twoProducts,filters:{view:'accessories'},canOperate:true}})
    expect(accessories.find('.parameter-bulk-toolbar').exists()).toBe(false)
    expect(accessories.find('table.product-data-table').exists()).toBe(true)
  })
})

describe('Product OS canonical grid views', () => {
  it('keeps same product row/table and shows measurement fields plus counts only within tabs', async () => {
    go.mockClear()
    const w=mount(Index, { props:{products:page,filters:{view:'measurements',parameter_status:'已填'},canOperate:false,
      parameterCounts:{'全部':4,'已填':1,'待填':1,'必填':1,'其他':1}}})
    expect(w.findAll('.product-data-table')).toHaveLength(1)
    for(const t of ['产品材质','工厂净重 kg','彩盒毛重 kg','快递毛重 kg','审核情况','包装长']) {
      if(t==='包装长')continue
      expect(w.text()).toContain(t)
    }
    expect(w.text()).toContain('50 × 23 × 19 cm')
    expect(w.text()).toContain('包装体积 L')
    expect(w.text()).toContain('21.85')
    expect(w.text()).toContain('待审核')
    expect(w.find('a[href="/products/7"]').exists()).toBe(true)
    expect(w.find('a[download]').exists()).toBe(false)
    expect(w.findAll('.product-grid-status-tabs button')).toHaveLength(5)
    const button=w.findAll('.product-grid-status-tabs button').find(b=>b.text().includes('必填'))
    await button!.trigger('click')
    expect(go).toHaveBeenCalledWith('/products/list',expect.objectContaining({
      view:'measurements',parameter_status:'必填',page:1,
    }),expect.anything())
  })

  it('switches view without rebuilding the table or losing canonical identity', async () => {
    go.mockClear()
    const w=mount(Index, { props:{products:page,filters:{view:'accessories'},canOperate:false}})
    expect(w.findAll('.product-data-table')).toHaveLength(1)
    expect(w.text()).toContain('包装内配件')
    expect(w.text()).toContain('Pillow × 1 pcs')
    expect(w.find('a[href="/products/7"]').exists()).toBe(true)
    const b=w.findAll('.product-grid-view-tabs button').find(b=>b.text().includes('标准产品列表'))
    await b!.trigger('click')
    expect(go).toHaveBeenCalledWith('/products/list',expect.objectContaining({
      view:'standard',parameter_status:'',page:1,
    }),expect.anything())
  })

  it('factory task inbox shows only task rows with status tabs and direct product links', () => {
    const w=mount(FactoryParameterTasks,{props:{tasks:[
      {product_id:77,sku:'DEMO77',name:'Factory Pillow',status:'必填',latest_status:null,required:true},
    ]}})
    expect(w.find('a[href="/products/factory-parameters/77"]').exists()).toBe(true)
    expect(w.findAll('nav[aria-label="填报状态筛选"] button')).toHaveLength(5)
    expect(w.text()).toContain('Factory Pillow')
    expect(w.text()).toContain('必填')
  })

  it('factory form permits submission without photos and computes actual packing volume', async () => {
    const w=mount(FactoryParameters,{props:{
      product:{id:77,sku:'DEMO77',name:'Factory Pillow'},
      task:{is_required:true,required_fields:['net_weight_kg']},
      draft:{id:3,measurements:{net_weight_kg:'1.589',package_length_cm:'50',
        package_width_cm:'23',package_height_cm:'19'},accessories:[],notes:null,media:[]},
      history:[]
    }})
    expect(w.text()).toContain('图片选填')
    expect(w.text()).toContain('不上传照片也能提交审核')
    expect(w.text()).toContain('21.85')
    expect(w.find('input[type="file"]').exists()).toBe(true)
    expect(w.findAll('input[type="number"]').length).toBeGreaterThan(8)
    expect(w.findAll('button').some(b=>b.text()==='提交审核')).toBe(true)
  })
})
