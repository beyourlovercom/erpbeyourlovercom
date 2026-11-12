import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Orders from './Orders.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{title}}</title>' },
  router: { get, post },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title','breadcrumbs'], template: '<div><slot /></div>' },
}))
vi.mock('../../Components/DataGrid/GlobalGrid.vue', () => ({
  __esModule: true, __isTeleport: false, __isSuspense: false, __isKeepAlive: false,
  default: {
    name: 'GlobalGrid',
    props: [
      'namespace','title','columns','rows','requiredColumnIds','page','search','sort',
      'enableSelection','clientCsvExport','defaultPinnedRight','showSearch','filterColumns','filterValues',
    ],
    emits: ['page-change','page-size-change','sort-change','search-change','column-search-change'],
    template: '<section data-testid="mock-global-grid"><slot name="actions"/></section>',
  },
}))
const base = {
  id: 10, erp_order_id: '007-REVIEW-10', external_order_number: 'SRC-10',
  order_date: '2026-11-11', recipient_name: '验收收件人', source_type: 'woocommerce',
  fulfillment_suffix: 'B', root_order_id: 3, archived: false, tracking_number: 'TRACK-10',
  shipment_status: 'shipped', carrier: 'USPS', status: 'pending', synced_at: null,
  tracking_outbound_status: null, tracking_outbound_attempts: null,
  tracking_sync_status: 'pending', tracking_sync_reason: null, tracking_sync_verified_at: null,
  progress: { code: 'unsynced' as 'unsynced'|'failed'|'synced'|'notified', label: '未同步', reason: '尚待验证' as string|null },
  can_sync: true, sync_block_reason: null as string|null, can_reconcile: true, can_operate: true,
}
const props = (orders = [base]) => ({
  globalGridPilotAvailable: true, globalGridPilotEnabled: true, canOperate: true,
  orders, filters: { search: '', source: '', per_page: 50, sort: 'date', direction: 'desc' as const, grid_progress: '' },
  pagination: { total: 8000, current_page: 1, last_page: 160, per_page: 50, from: 1, to: 50 },
})

describe('Sync Orders Global Grid R0–R3 canary', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    vi.stubGlobal('confirm', vi.fn(() => true))
    vi.stubGlobal('alert', vi.fn())
  })
  const grid = async (custom = props()) => {
    const wrapper = mount(Orders, { props: custom })
    await flushPromises()
    return { wrapper, component: wrapper.findComponent({name: 'GlobalGrid'}) }
  }

  it('uses shared GlobalGrid with optional right columns and isolated account namespace', async () => {
    const { wrapper, component } = await grid()
    expect(wrapper.find('[data-testid="mock-global-grid"]').exists()).toBe(true)
    expect(wrapper.find('.overview-table').exists()).toBe(false)
    expect(component.props('namespace')).toBe('grid.sync.orders.global.v1')
    expect(component.props('enableSelection')).toBe(false)
    expect(component.props('clientCsvExport')).toBe(false)
    expect(component.props('defaultPinnedRight')).toBe(2)
    expect(component.props('requiredColumnIds')).toEqual(['order_number'])
    const columns = component.props('columns') as Array<Record<string,unknown>>
    expect(columns.at(-2)?.colId).toBe('actions')
    expect(columns.at(-1)?.colId).toBe('progress')
    expect(columns.slice(-2).every(c=>c.lockPinned !== true && c.lockPosition !== 'right')).toBe(true)
    const action=columns.find(c=>c.colId==='actions')!
    expect(action.width).toBe(178)
    expect(action.minWidth).toBe(178)
    expect(action.maxWidth).toBe(330)
    expect((action.cellRendererParams as {compactActions?:boolean}).compactActions).not.toBe(true)
    expect(columns.find(c => c.colId === 'outbound_status')?.hide).toBe(true)
    expect(component.props('page')).toEqual({total:8000,current_page:1,last_page:160,per_page:50})
    expect(wrapper.text()).toContain('返回原表')
    expect(wrapper.text()).toContain('已通知')
  })

  it('shows direct actions with no dropdown and sizes rare 3/4-action rows without truncation', async()=>{
    const {component}=await grid()
    const actions=(component.props('columns') as Array<Record<string,any>>).find(c=>c.colId==='actions')!
    const items=actions.cellRendererParams.actionsFor({id:10})
    expect(items.map((item:{label:string})=>item.label)).toEqual(['查看','同步网站'])
    expect(actions.cellRendererParams.compactActions).toBeUndefined()
    expect(actions.width).toBeGreaterThanOrEqual(160)

    const failed={...base,progress:{code:'failed' as const,label:'失败',reason:'待核对'},
      can_sync:false,can_reconcile:true}
    const {component:failureGrid}=await grid(props([failed]))
    const failCol=(failureGrid.props('columns') as Array<Record<string,any>>).find(c=>c.colId==='actions')!
    expect(failCol.width).toBe(310)
    expect(failCol.cellRendererParams.actionsFor({id:10}).map((item:{label:string})=>item.label))
      .toEqual(['查看','同步网站','核对官网','原因'])
    expect(failCol.cellRendererParams.compactActions).toBeUndefined()
    expect(post).not.toHaveBeenCalled()
  })

  it('shows 查看 and 同步网站 as directly clickable controls without an extra 操作 dropdown',async()=>{
    const {component}=await grid()
    const col=(component.props('columns') as Array<Record<string,any>>).find(c=>c.colId==='actions')!
    const cell=mount(GlobalGridCell,{props:{params:{...col.cellRendererParams,data:{id:10}}}})
    expect(cell.find('button[aria-label="操作"]').exists()).toBe(false)
    expect(cell.get('a').text()).toBe('查看')
    expect(cell.get('a').attributes('href')).toBe('/customers/orders/10')
    expect(cell.get('button').text()).toBe('同步网站')
    expect(post).not.toHaveBeenCalled()
    await cell.get('button').trigger('click')
    // Test-only mocked router; never sends actual order tracking data.
    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/sync/orders/10/tracking-outbound')
    cell.unmount()
  })
  it('enables server-side second-row filters and dispatches full-library sync status selection', async () => {
    const { component } = await grid()
    expect(component.props('filterColumns')).toEqual([
      'order_number','date','source','recipient','carrier','tracking','progress',
    ])
    expect(component.props('filterValues')).toMatchObject({ progress: '', tracking: '', recipient: '' })
    const columns = component.props('columns') as Array<Record<string, unknown>>
    const status = columns.at(-1)!
    expect(status.colId).toBe('progress')
    expect(status.floatingFilterComponent).toBeTruthy()
    component.vm.$emit('column-search-change', { colId: 'progress', value: 'failed' })
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({
      grid_progress: 'failed', global_grid: '1', page: 1,
    }), expect.any(Object))
    expect(post).not.toHaveBeenCalled()

    component.vm.$emit('column-search-change', { colId: 'tracking', value: 'TEST-TRACK' })
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({
      grid_progress: 'failed', grid_tracking: 'TEST-TRACK', page: 1,
    }), expect.any(Object))

    component.vm.$emit('column-search-change', { colId: 'unknown_sql_column', value: 'x' })
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('labels server-filtered totals as 筛选结果 rather than 全部订单', async () => {
    const { wrapper } = await grid({
      ...props(),
      filters: { ...props().filters, grid_progress: 'failed' },
      pagination: { total: 2, current_page: 1, last_page: 1, per_page: 50, from: 1, to: 2 },
    })
    expect(wrapper.find('.total-counter').text()).toContain('筛选结果')
    expect(wrapper.find('.total-counter').text()).toContain('2')
  })

  it('mobile preserves visible order identity and sync progress with independent layout', async () => {
    const prior=window.innerWidth
    Object.defineProperty(window,'innerWidth',{configurable:true,value:390})
    const { wrapper,component }=await grid()
    expect(component.props('namespace')).toBe('grid.sync.orders.global.mobile.v1')
    expect(component.props('defaultPinnedRight')).toBe(2)
    const columns=component.props('columns') as Array<Record<string, unknown>>
    expect(columns.find(col=>col.colId==='actions')?.pinned).toBe('right')
    expect(columns.at(-1)?.colId).toBe('progress')
    expect(columns.at(-1)?.pinned).toBe('right')
    expect(columns.find(col=>col.colId==='order_number')?.width).toBe(132)
    expect(columns.find(col=>col.colId==='actions')?.width).toBe(100)
    expect(columns.at(-1)?.width).toBe(96)
    wrapper.unmount()
    Object.defineProperty(window,'innerWidth',{configurable:true,value:prior})
  })

  it('preserves all current order identity and proof fields in Grid rows', async () => {
    const { component } = await grid()
    const rows = component.props('rows') as Array<Record<string, unknown>>
    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({
      id:10,order_number:'007-REVIEW-10',original_number:'SRC-10',
      source_label:'官网 Woo',tracking_label:'TRACK-10',progress_code:'unsynced',
      progress_label:'未同步', parcel:'B', root_number:3,
    })
  })

  it('keeps original write safety for eligible, blocked, conflict, verified and readonly records', async () => {
    const blocked={...base,id:11,can_sync:false,sync_block_reason:'订单尚未完成发货'}
    const failed={...base,id:12,can_sync:false,can_reconcile:true,progress:{code:'failed' as const,label:'同步失败',reason:'官网冲突'}}
    const synced={...base,id:13,can_sync:false,progress:{code:'synced' as const,label:'已同步',reason:null}}
    const notified={...base,id:14,can_sync:false,progress:{code:'notified' as const,label:'已通知',reason:null}}
    const viewer={...base,id:15,can_operate:false,can_sync:false,can_reconcile:false}
    const { component } = await grid(props([base,blocked,failed,synced,notified,viewer]))
    const columns = component.props('columns') as Array<{colId:string,cellRendererParams?:{actionsFor?:(row:Record<string,unknown>)=>Array<{label:string,onClick?:()=>void}>}}>
    const actionsFor=columns.find(c=>c.colId==='actions')!.cellRendererParams!.actionsFor!
    const rows=component.props('rows') as Array<Record<string,unknown>>
    expect(rows.map(r=>actionsFor(r).map(a=>a.label))).toEqual([
      ['查看','同步网站'], ['查看','同步网站','核对官网'], ['查看','同步网站','核对官网','原因'],
      ['查看'], ['查看'], ['查看'],
    ])
    actionsFor(rows[1]).find(a=>a.label==='同步网站')?.onClick?.()
    expect(window.alert).toHaveBeenCalledWith('订单尚未完成发货')
    expect(post).not.toHaveBeenCalled()
    actionsFor(rows[0]).find(a=>a.label==='同步网站')?.onClick?.()
    expect(post).toHaveBeenCalledWith('/sync/orders/10/tracking-outbound')
  })

  it('server paging, search, sorting and source filter use bounded query parameters and keep canary enabled', async () => {
    const { wrapper,component }=await grid()
    component.vm.$emit('page-change',3)
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({global_grid:'1',page:3,per_page:50}), expect.any(Object))
    component.vm.$emit('page-size-change',100)
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({global_grid:'1',page:1,per_page:100}), expect.any(Object))
    component.vm.$emit('search-change','SRC-10')
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({global_grid:'1',search:'SRC-10',page:1}), expect.any(Object))
    component.vm.$emit('sort-change',{colId:'order_number',direction:'asc'})
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({sort:'order_number',direction:'asc',global_grid:'1'}), expect.any(Object))
    await wrapper.find('select[aria-label="AG Grid 订单来源"]').setValue('woocommerce')
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({source:'woocommerce',global_grid:'1'}), expect.any(Object))
    component.vm.$emit('page-size-change','all')
    expect(get).toHaveBeenCalledTimes(5)
  })

  it('preserves explicit old-table fallback across pagination after default rollout', async () => {
    const { wrapper } = await grid({
      ...props(), globalGridPilotEnabled: false, globalGridPilotAvailable: true,
    })
    expect(wrapper.find('.overview-table').exists()).toBe(true)
    await wrapper.find('.page-controls button:last-child').trigger('click')
    expect(get).toHaveBeenCalledWith('/sync/orders',
      expect.objectContaining({ global_grid: '0', page: 2 }),
      expect.any(Object))
  })

  it('exposes explicit old-table fallback and can return to the default Grid', async () => {
    const { wrapper: normal }=await grid({
      ...props(), globalGridPilotEnabled:false,globalGridPilotAvailable:false,
    })
    expect(normal.findComponent({name:'GlobalGrid'}).exists()).toBe(false)
    expect(normal.find('.overview-table').exists()).toBe(true)
    expect(normal.find('[data-testid="sync-global-grid-toggle"]').exists()).toBe(false)
    const { wrapper: canary }=await grid({
      ...props(), globalGridPilotEnabled:false,globalGridPilotAvailable:true,
    })
    await canary.find('[data-testid="sync-global-grid-toggle"]').trigger('click')
    expect(get).toHaveBeenCalledWith('/sync/orders',expect.objectContaining({global_grid:'1',page:1}),expect.any(Object))
  })
})
