import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import Overdue from './Overdue.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{title}}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const baseRow = {
  warehouse_id:1,warehouse_code:'US-LA',warehouse_name:'美西 MorePlus 仓',
  product_id:100,sku:'BY489A',canonical_sku:'BY489A-Lubes',
  product_name:'Test Product',putaway_date:'2026-01-06',
  quantity:12,age_days:302,overdue_days:212,lot_id:440,
  stock_status:'sellable',source:'dated_lot',quality:'批次已核对',
}
const props = {
  asOf:'2026-11-04',cutoffDate:'2026-08-06',
  summary:{overdue_units:5027,overdue_products:56,overdue_warehouse_products:85,
    overdue_lot_rows:94,overdue_warehouses:4,unknown_age_units:16,
    lot_mismatch_skus:2,lot_mismatch_units:2},
  warehouses:[{code:'US-LA',name:'美西 MorePlus 仓'},{code:'DE',name:'德国天运仓'}],
  warehouseUnits:{'US-LA':2653,'DE':1050},
  filters:{warehouse:'',search:''},
  filteredUnits:5027,filteredRows:94,
  rows:{data:[baseRow],current_page:1,last_page:2,total:94,from:1,to:50,
    prev_page_url:null,next_page_url:'/inventory/overdue?page=2'},
}

describe('overdue warehouse inventory UI', () => {
  it('shows one overview entrypoint and exact sample-style fields with a per-lot result', () => {
    const wrapper = mount(Overdue, {props})
    expect(wrapper.text()).toContain('5,027')
    expect(wrapper.text()).toContain('2026-08-06')
    expect(wrapper.find('a[data-testid="overdue-export"]').attributes('href')).toBe('/inventory/overdue/export')
    expect(wrapper.findAll('table thead th').map(th => th.text()).slice(0,7))
      .toEqual(['仓别','组别','责任人','入库时间','FNSKU','物料名称','数量'])
    expect(wrapper.text()).toContain('BY489A')
    expect(wrapper.text()).toContain('ERP: BY489A-Lubes')
    expect(wrapper.text()).toContain('批次 #440')
    expect(wrapper.text()).toContain('12')
  })

  it('keeps the current warehouse/SKU filters on XLSX download and requests the filtered page', async () => {
    const wrapper = mount(Overdue, {props:{...props,filters:{warehouse:'DE',search:'VA617A'}}})
    expect(wrapper.find('a[data-testid="overdue-export"]').attributes('href'))
      .toBe('/inventory/overdue/export?warehouse=DE&search=VA617A')
    await wrapper.find('select[aria-label="筛选海外仓"]').setValue('US-LA')
    await wrapper.find('input[aria-label="搜索超期库存"]').setValue('BX288A')
    await wrapper.find('form').trigger('submit')
    expect(router.get).toHaveBeenCalledWith('/inventory/overdue',
      {warehouse:'US-LA',search:'BX288A'},{preserveState:true,replace:true})
  })

  it('indicates lot mismatch and never offers inventory apply control', () => {
    const wrapper = mount(Overdue,{props:{...props,rows:{...props.rows,data:[{...baseRow,quality:'批次数量与当前库存不符，已按当前库存封顶'}]}}})
    expect(wrapper.text()).toContain('待复核')
    expect(wrapper.findAll('button').some(b => b.text().includes('写入')||b.text().includes('核销'))).toBe(false)
  })
})
