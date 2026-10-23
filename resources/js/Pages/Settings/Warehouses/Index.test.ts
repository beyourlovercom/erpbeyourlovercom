import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Warehouse Settings OpenAPI landing', () => {
  it('puts one-time provider setup under Settings, not the Logistics execution module', () => {
    const wrapper = mount(Index, {
      props: { integrations: [{
        id: 'xlwms', label: '领星 OMS · 世迈仓 OpenAPI',
        url: '/settings/warehouses/xlwms', capability: 'read_only_probe',
        configured: false, auto_dispatch_enabled: false,
      }] },
    })
    expect(wrapper.text()).toContain('仓库设置')
    expect(wrapper.text()).toContain('OpenAPI 集成')
    expect(wrapper.text()).toContain('自动发货：关闭')
    expect(wrapper.find('a[href="/settings/warehouses/xlwms"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/logistics/integrations/xlwms"]').exists()).toBe(false)
  })
})
