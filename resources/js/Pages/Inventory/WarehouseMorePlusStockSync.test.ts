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
    expect(formPost).toHaveBeenCalledWith('/inventory/us-la/provider-stock/preview', expect.objectContaining({ preserveScroll: true }))
    const other = mount(WarehouseShow, { props: { ...props, warehouse } })
    expect(other.text()).not.toContain('同步仓库库存')
    const viewer = mount(WarehouseShow, { props: { ...props, canOperate: false } })
    expect(viewer.text()).not.toContain('同步仓库库存')
  })
  it('shows safety summary and disables write until independent release flag is enabled', () => {
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: preview } })
    expect(wrapper.text()).toContain('仓库库存差异预览')
    expect(wrapper.text()).toContain('待复核')
    const apply = wrapper.findAll('button').find(button => button.text().includes('正式写入待授权'))
    expect(apply?.attributes('disabled')).toBeDefined()
  })
  it('requires confirmation before applying the checksum-bound preview', async () => {
    formPost.mockClear()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(WarehouseShow, { props: { ...props, moreplusInventoryPreview: { ...preview, apply_enabled: true } } })
    await wrapper.findAll('button').find(button => button.text().includes('确认同步到 ERP'))!.trigger('click')
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(formPost).toHaveBeenCalledWith('/inventory/us-la/provider-stock/apply', expect.objectContaining({ preserveScroll: true }))
    confirm.mockRestore()
  })
})
