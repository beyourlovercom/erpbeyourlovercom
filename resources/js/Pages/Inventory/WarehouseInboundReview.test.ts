import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WarehouseShow from './WarehouseShow.vue'

const { patch } = vi.hoisted(() => ({ patch: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { patch, reload: vi.fn() },
  useForm: (data: Record<string, unknown>) => ({
    ...data, processing: false, errors: {},
    post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(),
    defaults: vi.fn(), transform() { return this },
  }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))
vi.mock('./components/InventorySkuCombobox.vue', () => ({
  default: {
    emits: ['update:modelValue', 'update:productId'],
    template: '<button class="sku-select" @click="$emit(\'update:modelValue\', \'PILLOW-GREEN\'); $emit(\'update:productId\', 33)">选择正式 SKU</button>',
  },
}))

const warehouse = {
  id: 1, slug: 'us-3sm', public_slug: 'us-3sm', code: 'US-SM', name: '美国世迈仓',
  name_en: 'US SM', role_label: '海外仓', is_fulfillment_warehouse: true,
  is_inventory_only_warehouse: false, country_code: 'US', address_line1: null,
  address_line2: null, city: null, state_region: null, postal_code: null, timezone: null,
  type: 'warehouse', ownership_type: 'external' as const, is_domestic: false, is_active: true, priority: 1,
  fulfillment_available_on: null, fulfillment_state: 'awaiting_inventory' as const,
  fulfillment_state_label: '待库存激活', replenishment_lead_time_days: null,
  replenishment_safety_stock_days: null, replenishment_target_cover_days: null,
  billing_markdown: null, service_regions: [], last_inventory_sync_at: null, inventories: [],
}

const item = {
  id: 9, updated_at: '2026-10-26T09:00:00+00:00', product_id: null,
  source_sku: null, canonical_sku: null, source_product_name: 'Pillow mixed',
  product_name: 'Pillow mixed', quantity: 20, evidence_type: 'actual_manifest',
  match_status: 'review', note: null,
}
const inboundProjection = {
  summary: { batch_count: 1, in_transit_batches: 0, in_transit_units: 0, in_transit_cartons: 0, received_batches: 1, ready_batches: 0 },
  statuses: [
    { key: 'preparing', label: '准备中' }, { key: 'factory_packing', label: '工厂打包' },
    { key: 'in_transit', label: '运输中' }, { key: 'port_arrived', label: '港口已到货' },
    { key: 'received', label: '仓库已入库' }, { key: 'ready', label: '正常发货' },
  ],
  batches: [{
    id: 7, reference: 'IB027260912RS-1', transport_mode: 'sea', status: 'received', status_label: '仓库已入库',
    next_status: 'ready', next_status_label: '激活库存并进入正常发货', activation_blocked_count: 1,
    carton_count: 18, quantity_total: 131, gross_weight_kg: '194.1', volume_cbm: '1.065',
    shipped_on: null, eta_date: '2026-09-30', eta_is_estimated: true,
    received_on: '2026-10-26', source_filename: 'actual_manifest.xlsx', note: null, items: [item],
  }],
}

const mountWarehouse = (canOperate: boolean) => mount(WarehouseShow, {
  props: { warehouse, clearanceProducts: [], clearanceCandidates: [], canOperate, canManage: false, inboundProjection: inboundProjection as any },
})

describe('Warehouse inbound SKU review P0', () => {
  it('makes unresolved rows reviewable but keeps activation disabled until server confirms', async () => {
    const wrapper = mountWarehouse(true)
    await wrapper.find('.warehouse-tabs button:nth-child(3)').trigger('click')
    expect(wrapper.text()).toContain('还有 1 个待复核 SKU')
    expect(wrapper.find('.inbound-action-row button').attributes('disabled')).toBeDefined()
    await wrapper.find('.inbound-review-trigger').trigger('click')
    expect(wrapper.find('.inbound-review-form').exists()).toBe(true)
    expect(wrapper.text()).toContain('实际运输单 20 件不变')
    await wrapper.find('.sku-select').trigger('click')
    await wrapper.find('.inbound-review-form textarea').setValue('Checked physical carton labels')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await wrapper.find('.inbound-review-actions .primary').trigger('click')
    expect(patch).toHaveBeenCalledWith(
      '/inventory/us-3sm/inbound/7/items/9/review',
      { product_id: 33, expected_updated_at: item.updated_at, reason: 'Checked physical carton labels' },
      expect.objectContaining({ preserveScroll: true }),
    )
    expect(wrapper.find('.inbound-action-row button').attributes('disabled')).toBeDefined()
  })

  it('hides review writes from view-only users', async () => {
    const wrapper = mountWarehouse(false)
    await wrapper.find('.warehouse-tabs button:nth-child(3)').trigger('click')
    expect(wrapper.find('.inbound-review-trigger').exists()).toBe(false)
    expect(wrapper.find('.inbound-review-form').exists()).toBe(false)
    expect(wrapper.text()).toContain('待复核')
  })
})
