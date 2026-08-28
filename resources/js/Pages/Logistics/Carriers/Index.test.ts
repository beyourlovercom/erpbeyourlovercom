import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Carrier index workspace', () => {
  it('renders carrier aggregates and canonical detail links', () => {
    const wrapper = mount(Index, { props: { carriers: [{
      name: 'DHL', shipment_count: 3, chargeable_weight_kg: 4.25, chargeable_weight_coverage: 2,
      costs_by_currency: [{ currency: 'USD', total: 42, average: 21, shipments: 2 }],
      this_month_count: 2, last_shipped_at: '2026-08-28T08:00:00Z',
    }] } })
    expect(wrapper.text()).toContain('Carrier Authority Workspace')
    expect(wrapper.text()).toContain('DHL')
    expect(wrapper.text()).toContain('USD 42.00')
    expect(wrapper.find('a[href="/logistics/carriers/DHL"]').exists()).toBe(true)
  })
})
