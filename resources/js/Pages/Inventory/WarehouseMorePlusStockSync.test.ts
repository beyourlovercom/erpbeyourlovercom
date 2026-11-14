import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import WarehouseShow from './WarehouseShow.vue'

const formPost = vi.hoisted(() => vi.fn())

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), patch: vi.fn(), get: vi.fn() },
  useForm: (initial: Record<string, any>) => reactive({
    ...initial,
    processing: false,
    errors: {},
    post: formPost,
    patch: vi.fn(),
    delete: vi.fn(),
    reset: vi.fn(),
    defaults: vi.fn(),
    transform(fn: (data: any) => any) { Object.assign(this, fn(this)); return this },
  }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))
vi.mock('./components/InventorySkuCombobox.vue', () => ({
  default: { template: '<input class="inventory-sku-stub" />' },
}))

const warehouse = {
  id: 7, slug: 'us-3sm', public_slug: 'us-3sm', code: 'US-SM', name: '美国3世迈仓',
  name_en: 'US SM', role_label: '海外履约仓', bill_import: null, bill_import_onboarding: null,
  is_fulfillment_warehouse: true, is_inventory_only_warehouse: false, country_code: 'US',
  address_line1: null, address_line2: null, city: null, state_region: null, postal_code: null, timezone: null,
  type: 'warehouse', ownership_type: 'external' as const, is_domestic: false, is_active: true, priority: 10,
  fulfillment_available_on: null, fulfillment_state: 'ready' as const, fulfillment_state_label: '可履约',
  replenishment_lead_time_days: null, replenishment_safety_stock_days: null, replenishment_target_cover_days: null,
  billing_markdown: null, service_regions: ['US'], last_inventory_sync_at: null, inventories: [],
}

const moreplus = { ...warehouse, id: 2, slug: 'us-la', public_slug: 'us-la', code: 'US-LA', name: '美西 MorePlus 仓' }
const props = { warehouse: moreplus, clearanceProducts: [], clearanceCandidates: [], costCenter: { months: [] }, canOperate: true, canManage: false }
const preview = {
  checksum: 'a'.repeat(64), source_sku_count: 1, ready: 1, unchanged: 0, blocked: 0,
  apply_enabled: false, rows: [{
    provider_sku: 'MP-SKU', canonical_sku: 'MP-SKU',
    provider_physical: 9, provider_available: 8, local_qoh: 2, local_reserved: 1,
    status: 'ready' as const, reason: null,
  }],
}

describe('MorePlus inventory stock sync in Warehouse 360', () => {
  it('shows button only for an authorized US-LA warehouse operator', async () => {
    formPost.mockClear()
    const wrapper = mount(WarehouseShow, { props })
    const button = wrapper.findAll('button').find(button => button.text().includes('同步仓库库存'))
    expect(button?.exists()).toBe(true)
    await button!.trigger('click')
    expect(formPost).toHaveBeenCalledWith('/inventory/us-la/provider-stock/sync', expect.objectContaining({ preserveScroll: true }))
    const other = mount(WarehouseShow, { props: { ...props, warehouse } })
    expect(other.text()).not.toContain('同步仓库库存')
    const viewer = mount(WarehouseShow, { props: { ...props, canOperate: false } })
    expect(viewer.text()).not.toContain('同步仓库库存')
  })
  it('shows read-only accounting summary independent of the actual sync button', async () => {
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: preview } })
    expect(wrapper.text()).toContain('仓库库存差异预览')
    expect(wrapper.text()).toContain('待复核')
    expect(wrapper.find('button[aria-label="关闭库存预览"]').exists()).toBe(true)
    await wrapper.findAll('button').find(button => button.text().includes('查看明细'))!.trigger('click')
    expect(wrapper.text()).toContain('库存差异只读核对')
  })
  it('shows MorePlus SKU as primary and preserves Woo canonical identity without SKU editing', async () => {
    const migrated = { ...moreplus, inventories: [{
      id: 101, product: { id: 1346, sku: 'BY489A-Lubes', provider_sku: 'BY489A', name: 'BEYOURLOVER LUBRICANT 35ML' },
      quantity_on_hand: 701, quantity_reserved: 0, quantity_available: 701,
      stock_status: 'in_stock' as const, stock_status_label: '有货',
      inbound_at: '2026-01-06', stay_days: 300, location: null,
      standard_qoh: null, expected_restock_qty: 0, updated_at: '2026-11-04T08:36:11Z',
      product_updated_at: '2026-09-05T10:53:51Z',
      replenishment: { policy_status: 'healthy' as const, policy_label: '正常', shipped_90d_qty: 0,
        days_of_cover: null, recommended_restock_qty: 0 },
    }] }
    const wrapper = mount(WarehouseShow, { props: { ...props, warehouse: migrated, canEditProducts: true } })
    await wrapper.findAll('button').find(button => button.text().trim().startsWith('库存'))!.trigger('click')
    await wrapper.findAll('button').find(button => button.text().trim().startsWith('当前库存'))!.trigger('click')
    const row = wrapper.find('.inventory-table tbody tr')
    expect(row.exists()).toBe(true)
    expect(row.find('.identity-cell').text()).toContain('BY489A')
    expect(row.find('.identity-cell').text()).toContain('Woo: BY489A-Lubes')
    expect(row.find('button[aria-label="编辑 SKU"]').exists()).toBe(false)
    await wrapper.find('input[placeholder="搜索 SKU、产品或库位"]').setValue('BY489A')
    expect(wrapper.findAll('.inventory-table tbody tr')).toHaveLength(1)
  })

  it('one-click sync posts immediately without preview confirmation or a write feature flag', async () => {
    formPost.mockClear()
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: preview } })
    await wrapper.findAll('button').find(b => b.text().includes('同步仓库库存'))!.trigger('click')
    expect(formPost).toHaveBeenCalledWith('/inventory/us-la/provider-stock/sync', expect.objectContaining({ preserveScroll: true }))
    expect(wrapper.findAll('button').some(b => b.text().includes('正式写入待授权'))).toBe(false)
  })
  it('shows FIFO candidate unit totals and lot dates as a read-only drilldown', async () => {
    const fifo = {
      checksum: 'b'.repeat(64), ready_fifo: 1, ready_units: 3, blocked: 1, apply_enabled: false,
      rows: [{provider_sku:'MP-TEST',canonical_sku:'MP-TEST',qoh:10,physical:7,delta:3,
        aging_count:8,status:'ready_fifo' as const,reason:null,
        lot_changes:[{date:'2026-01-06',before:6,after:3}]},
        {provider_sku:'MP-HOLD',canonical_sku:'MP-HOLD',qoh:9,physical:1,delta:8,
        aging_count:8,status:'blocked' as const,reason:'PROVIDER_AVAILABLE_BELOW_ERP_PROJECTED_AVAILABLE',lot_changes:[]}],
    }
    const wrapper = mount(WarehouseShow, { props: {...props, moreplusInventoryPreview: preview, moreplusFifoPreview:fifo} })
    expect(wrapper.text()).toContain('FIFO 候选 1 款 / 3 件')
    await wrapper.findAll('button').find(button => button.text().includes('查看明细'))!.trigger('click')
    expect(wrapper.text()).toContain('FIFO / 库龄账务差异 · 只读')
    expect(wrapper.text()).toContain('供历史分析 1 个 SKU / 3 件')
    expect(wrapper.text()).toContain('另有 1 个须单独复核')
    expect(wrapper.find('details').text()).toContain('2026-01-06')
    expect(wrapper.find('details').text()).toContain('6 → 3')
    expect(wrapper.text()).toContain('仓库占用与 ERP 预留不一致')
    expect(wrapper.findAll('button').some(b => b.text().includes('执行 FIFO'))).toBe(false)
  })
  it('shows absent provider SKU corrections before sync and preserves historical reservations', async () => {
    const missing = {
      ...preview,
      absent_count: 2,
      absent_qoh: 155,
      absent_zero_eligible: true,
      absent_rows: [
        { sku: 'MF414A', local_qoh: 153, local_reserved: 2 },
        { sku: 'VX736A', local_qoh: 2, local_reserved: 0 },
      ],
    }
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: missing } })
    expect(wrapper.text()).toContain('缺席库存 SKU 2')
    await wrapper.findAll('button').find(b => b.text() === '查看明细')!.trigger('click')
    expect(wrapper.text()).toContain('MF414A')
    expect(wrapper.text()).toContain('153')
    expect(wrapper.text()).toContain('ERP 实物/可用归零，保留历史')
  })
  it('uses a one-line summary and never shows the full 45-SKU table by default', async () => {
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: preview } })
    expect(wrapper.find('.moreplus-compact-bar').exists()).toBe(true)
    expect(wrapper.find('.moreplus-compact-preview .table-shell').exists()).toBe(false)
    expect(wrapper.find('.moreplus-compact-bar').text()).toContain('待复核')
    await wrapper.findAll('button').find(b => b.text() === '查看明细')!.trigger('click')
    expect(wrapper.find('.moreplus-compact-preview .table-shell').exists()).toBe(true)
    await wrapper.findAll('button').find(b => b.text() === '收起明细')!.trigger('click')
    expect(wrapper.find('.moreplus-compact-preview .table-shell').exists()).toBe(false)
  })
  it('closes the preview on demand and when switching warehouse tabs', async () => {
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: preview } })
    await wrapper.find('button[aria-label="关闭库存预览"]').trigger('click')
    expect(wrapper.find('.moreplus-compact-preview').exists()).toBe(false)
    await wrapper.findAll('button').find(b => b.text() === '仅查看差异')!.trigger('click')
    expect(wrapper.find('.moreplus-compact-preview').exists()).toBe(true)
    await wrapper.findAll('.warehouse-tabs button').find(b => b.text() === '库存')!.trigger('click')
    expect(wrapper.find('.moreplus-compact-preview').exists()).toBe(false)
  })
  it('replaces only the MorePlus Standard QoH column with an independent read-only stock reference', async () => {
    const item = {
      id: 311, product: { id: 988, sku: 'MP-REFERENCE', provider_sku: 'MP-REFERENCE', name: 'Reference QA Product' },
      quantity_on_hand: 90, quantity_reserved: 0, quantity_available: 89,
      stock_status: 'in_stock' as const, stock_status_label: '有货',
      inbound_at: null, stay_days: null, location: null,
      standard_qoh: 999, expected_restock_qty: 0,
      updated_at: '2026-11-14T10:00:00Z',
      product_updated_at: '2026-11-14T10:00:00Z',
      last_read: {
        quantity: 77, raw_quantity: 80, adjustment_qty: 3,
        read_at: '2026-11-14 10:00:00', source: 'sync', status: 'synced', error_code: null,
      },
      replenishment: { policy_status: 'healthy' as const, policy_label: '正常',
        shipped_90d_qty: 0, days_of_cover: null, recommended_restock_qty: 0 },
    }
    const wrapper = mount(WarehouseShow, { props: { ...props, warehouse: { ...moreplus, inventories: [item] } } })
    await wrapper.findAll('.warehouse-tabs button').find(b => b.text() === '库存')!.trigger('click')
    expect(wrapper.find('.inventory-table thead').text()).toContain('上次读取库存数')
    expect(wrapper.find('.inventory-table thead').text()).not.toContain('Standard QoH')
    const row = wrapper.find('.inventory-table tbody tr')
    expect(row.findAll('td')[2].text()).toContain('90')
    const lastReadCell = row.findAll('td')[6]
    expect(lastReadCell.text()).toContain('77')
    expect(lastReadCell.attributes('title')).toContain('最近读取原始库存：80')
    expect(lastReadCell.attributes('title')).toContain('读取后已确认发货：3')
    expect(lastReadCell.attributes('title')).toContain('ERP 当前库存')
    expect(lastReadCell.find('button').exists()).toBe(false)
    const other = mount(WarehouseShow, { props: { ...props, warehouse: { ...warehouse, inventories: [item] } } })
    await other.findAll('.warehouse-tabs button').find(b => b.text() === '库存')!.trigger('click')
    expect(other.find('.inventory-table thead').text()).toContain('Standard QoH')
    expect(other.find('.inventory-table tbody tr').findAll('td')[6].text()).toContain('999')
  })

  it('distinguishes unread, explicit zero, main-sync failure and historical read failure', async () => {
    const base = {
      id: 312, product: { id: 988, sku: 'MP-FAIL-REF', provider_sku: 'MP-FAIL-REF', name: 'Failure QA' },
      quantity_on_hand: 15, quantity_reserved: 0, quantity_available: 15,
      stock_status: 'in_stock' as const, stock_status_label: '有货',
      inbound_at: null, stay_days: null, location: null,
      standard_qoh: null, expected_restock_qty: 0,
      updated_at: '2026-11-14T10:00:00Z',
      product_updated_at: '2026-11-14T10:00:00Z',
      replenishment: { policy_status: 'healthy' as const, policy_label: '正常',
        shipped_90d_qty: 0, days_of_cover: null, recommended_restock_qty: 0 },
    }
    const refs = [
      { ...base, id: 312, last_read: null },
      { ...base, id: 313, last_read: { quantity: 0, raw_quantity: 0, adjustment_qty: 0,
        read_at: '2026-11-14 10:00:00', source: 'api_read', status: 'read_success', error_code: null } },
      { ...base, id: 314, last_read: { quantity: 80, raw_quantity: 80, adjustment_qty: 0,
        read_at: '2026-11-14 10:00:00', source: 'sync', status: 'read_success_apply_failed', error_code: null } },
      { ...base, id: 315, last_read: { quantity: 79, raw_quantity: 80, adjustment_qty: 1,
        read_at: '2026-11-14 10:00:00', source: 'api_read', status: 'read_failed_last_known', error_code: 'PROVIDER_READ_FAILED' } },
    ]
    const wrapper = mount(WarehouseShow, { props: { ...props, warehouse: { ...moreplus, inventories: refs } } })
    await wrapper.findAll('.warehouse-tabs button').find(b => b.text() === '库存')!.trigger('click')
    const rows = wrapper.findAll('.inventory-table tbody tr')
    expect(rows).toHaveLength(4)
    expect(rows[0].findAll('td')[6].text()).toContain('—')
    expect(rows[1].findAll('td')[6].text()).toContain('0')
    expect(rows[2].findAll('td')[6].text()).toContain('同步失败 · 已保留')
    expect(rows[3].findAll('td')[6].text()).toContain('读取失败 · 历史值')
  })

})
