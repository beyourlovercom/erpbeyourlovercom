import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import WarehouseShow from './WarehouseShow.vue'

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { reload, post: vi.fn(), patch: vi.fn() },
  useForm: (initial: Record<string, unknown>) => ({
    ...initial, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reset: vi.fn(),
  }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('./components/InventorySkuCombobox.vue', () => ({ default: { template: '<input />' } }))

const warehouse: any = {
  id: 1, slug: 'us-la', public_slug: 'us-la', code: 'US-LA', name: 'Synthetic Warehouse',
  name_en: 'Synthetic', role_label: 'Overseas', bill_import: null,
  is_fulfillment_warehouse: true, is_inventory_only_warehouse: false, country_code: 'US',
  address_line1: null, address_line2: null, city: null, state_region: null,
  postal_code: null, timezone: null, type: 'warehouse', ownership_type: 'owned',
  is_domestic: false, is_active: true, priority: 10, fulfillment_available_on: null,
  fulfillment_state: 'ready', fulfillment_state_label: 'Ready',
  replenishment_lead_time_days: null, replenishment_safety_stock_days: null,
  replenishment_target_cover_days: null, billing_markdown: null, service_regions: [],
  last_inventory_sync_at: null, inventories: [],
}

describe('R3-C warehouse hidden tab status GET policy', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    reload.mockReset()
  })

  it('does not poll a hidden tab and performs one catch-up read on visibility', () => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const wrapper = mount(WarehouseShow, { props: {
      warehouse, clearanceProducts: [], clearanceCandidates: [], costCenter: { months: [] },
      billImportPreview: null, canOperate: false, canManage: false,
      latestRestockLabelJob: { id: 24, is_terminal: false, status: 'running' } as any,
    } })
    vi.advanceTimersByTime(10_000)
    expect(reload).not.toHaveBeenCalled()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    expect(reload).toHaveBeenCalledTimes(1)
    expect(reload).toHaveBeenCalledWith({ only: ['latestRestockLabelJob'] })
    wrapper.unmount()
    document.dispatchEvent(new Event('visibilitychange'))
    expect(reload).toHaveBeenCalledTimes(1)
    vi.restoreAllMocks()
  })
})
