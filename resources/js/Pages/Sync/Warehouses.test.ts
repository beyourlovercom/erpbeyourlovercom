import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Warehouses from './Warehouses.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<div />' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const warehouse = {
  id: 4, code: 'DE', name: '德国天运仓', country_code: 'DE', is_active: true,
  connector_status: 'configured', can_sync: true,
  last_sync_at: '2026-11-21T02:00:00Z', last_applied_at: null,
  last_status_at: '2026-11-21T02:00:00Z', last_sync_status: 'read_only',
  last_error_code: null, active_run_id: null,
  sync_counts: { provider: 34, matched: 0, unmatched: 34, blocked: 0 },
}
const other = {
  ...warehouse, id: 1, code: 'US-SM', name: '美国 MorePlus 仓',
  connector_status: 'not_configured', can_sync: false, last_sync_at: null,
  last_status_at: null, last_sync_status: 'never',
  sync_counts: { provider: 0, matched: 0, unmatched: 0, blocked: 0 },
}
const response = (data:object, ok=true) => ({ ok, status:ok?200:409, json:async()=>data })

describe('Warehouse sync operator UI', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    window.history.replaceState({}, '', '/')
  })

  it('shows read-only snapshot and counts, with a rightmost action only enabled for DE', () => {
    const wrapper=mount(Warehouses, {props:{warehouses:[warehouse,other]}})
    expect(wrapper.findAll('thead th').at(-1)?.text()).toBe('同步操作')
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toBe('读取成功 · 未更新 ERP')
    expect(wrapper.text()).toContain('OMS 产品 34 · 匹配 0 · 未匹配 34')
    expect(wrapper.get('[data-testid="warehouse-sync-button-DE"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="warehouse-sync-button-US-SM"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('尚未同步')
    wrapper.unmount()
  })

  it('distinguishes an actual ERP apply from a successful OMS read', () => {
    const applied={...warehouse, last_sync_status:'applied', last_applied_at:'2026-11-21T02:00:00Z'}
    const wrapper=mount(Warehouses,{props:{warehouses:[applied]}})
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toBe('ERP 库存已同步')
    wrapper.unmount()
  })

  it('starts one DE manual refresh and polls its persisted read-only result', async () => {
    const fetchMock=vi.mocked(fetch)
    fetchMock.mockResolvedValueOnce(response({
      run_id:77,status:'queued',mode:'read_only',erp_stock_updated:false,
    }) as Response).mockResolvedValueOnce(response({
      run_id:77,status:'read_only',mode:'read_only',erp_stock_updated:false,
      provider_sku_count:34,mapped_sku_count:0,unmatched_sku_count:34,
      blocked_sku_count:0,error_code:null,finished_at:'2026-11-21T03:00:00Z',
    }) as Response)
    const wrapper=mount(Warehouses,{props:{warehouses:[warehouse]}})
    await wrapper.get('[data-testid="warehouse-sync-button-DE"]').trigger('click')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/sync/warehouses/4/sync',expect.objectContaining({method:'POST'}))
    expect(fetchMock).toHaveBeenCalledWith('/sync/warehouses/4/sync/77',expect.objectContaining({method:'GET'}))
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toBe('读取成功 · 未更新 ERP')
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).not.toBe('ERP 库存已同步')
    wrapper.unmount()
  })

  it('shows a safe error without treating a rejected request as a successful sync', async () => {
    vi.mocked(fetch).mockResolvedValue(response({error_code:'XLWMS_DE_SYNC_ALREADY_RUNNING'},false) as Response)
    const wrapper=mount(Warehouses,{props:{warehouses:[warehouse]}})
    await wrapper.get('[data-testid="warehouse-sync-button-DE"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('已有同步任务执行中')
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toBe('读取成功 · 未更新 ERP')
    wrapper.unmount()
  })

  it('opens read-only detail with 34 unmatched SKUs, reasons and retry history', async () => {
    const unmatchedRows=Array.from({length:34},(_,i)=>({
      provider_sku:'DE-STOCK-'+String(i+1).padStart(3,'0'),erp_sku:null,
      resolution_status:'unmatched',oms_total:5,oms_available:5,
      oms_locked:0,oms_in_transit:0,mismatch_reason:'OMS_SKU_NOT_VERIFIED_ERP_IDENTITY',
    }))
    const detail={
      snapshot:{id:1,status:'blocked',fetched_at:'2026-11-21T09:41:00Z',
        applied_at:null,erp_stock_updated:false,provider_sku_count:64,
        mapped_sku_count:0,unmatched_sku_count:34,blocked_sku_count:0},
      counts:{matched:0,unmatched:34,blocked:0,catalog_only:30},
      rows:unmatchedRows,pagination:{page:1,per_page:50,total:34},
      runs:[
        {id:2,status:'blocked',mode:'read_only',snapshot_id:1,
          provider_sku_count:64,mapped_sku_count:0,unmatched_sku_count:34,
          blocked_sku_count:0,error_code:null,created_at:'2026-11-21T09:41:00Z',
          finished_at:'2026-11-21T09:41:10Z'},
        {id:1,status:'failed',mode:'read_only',snapshot_id:null,
          provider_sku_count:0,mapped_sku_count:0,unmatched_sku_count:0,
          blocked_sku_count:0,error_code:'XLWMS_DE_SYNC_TIMEOUT',
          created_at:'2026-11-21T09:00:00Z',finished_at:'2026-11-21T09:05:00Z'},
      ],
    }
    const fetchMock=vi.mocked(fetch)
    fetchMock.mockResolvedValueOnce(response(detail) as Response)
      .mockResolvedValueOnce(response({
        ...detail,rows:[],pagination:{page:1,per_page:50,total:0},
      }) as Response)
    const wrapper=mount(Warehouses,{props:{warehouses:[{...warehouse,last_sync_status:'blocked'}]}})
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toContain('待处理 SKU 差异')
    await wrapper.get('[data-testid="warehouse-sync-details-DE"]').trigger('click')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/sync/warehouses/4/sync-details?status=unmatched&page=1',
      expect.objectContaining({method:'GET'}))
    expect(wrapper.get('[data-testid="de-sync-summary"]').text()).toContain('34')
    expect(wrapper.get('[data-testid="de-sync-summary"]').text()).toContain('未执行')
    expect(wrapper.findAll('[data-testid="de-sku-row"]')).toHaveLength(34)
    expect(wrapper.get('[data-testid="de-sku-row"]').text()).toContain('ERP 尚无经过验证的对应 SKU')
    expect(wrapper.findAll('[data-testid="de-run-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('后台同步超时')
    await wrapper.get('[data-testid="de-status-filter"]').setValue('matched')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/sync/warehouses/4/sync-details?status=matched&page=1',
      expect.objectContaining({method:'GET'}))
    expect(wrapper.findAll('[data-testid="de-sku-row"]')).toHaveLength(0)
    expect(fetchMock.mock.calls.every(([,options])=>(options as RequestInit).method==='GET')).toBe(true)
    wrapper.unmount()
  })

  it('shows a safe read-only detail request failure and does not mark a sync successful', async () => {
    vi.mocked(fetch).mockResolvedValue(response({error_code:'XLWMS_DE_SYNC_TIMEOUT'},false) as Response)
    const wrapper=mount(Warehouses,{props:{warehouses:[{...warehouse,last_sync_status:'blocked'}]}})
    await wrapper.get('[data-testid="warehouse-sync-details-DE"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('后台同步超时')
    expect(wrapper.get('[data-testid="warehouse-sync-status-DE"]').text()).toContain('待处理 SKU 差异')
    wrapper.unmount()
  })

  it('auto-opens the DE read-only detail for a prepared Owner UAT URL', async () => {
    window.history.replaceState({}, '', '/sync/warehouses?details=DE')
    vi.mocked(fetch).mockResolvedValue(response({
      snapshot:null,counts:{matched:0,unmatched:0,blocked:0,catalog_only:0},
      rows:[],pagination:{page:1,per_page:50,total:0},runs:[],
    }) as Response)
    const wrapper=mount(Warehouses,{props:{warehouses:[warehouse]}})
    await flushPromises()
    expect(wrapper.find('[data-testid="de-sync-details-panel"]').exists()).toBe(true)
    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      '/sync/warehouses/4/sync-details?status=unmatched&page=1',
      expect.objectContaining({method:'GET'}),
    )
    wrapper.unmount()
  })
})
