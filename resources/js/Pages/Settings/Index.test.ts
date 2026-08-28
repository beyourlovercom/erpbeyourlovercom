import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head:{template:'<span />'}, Link:{props:['href'],template:'<a :href="href"><slot /></a>'} }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{template:'<div><slot /></div>'} }))

describe('Settings release summary', () => {
  it('renders current release, history link, and management action', () => {
    const wrapper = mount(Index, { props: { canManage: true, currentRelease: { id: 7, version: 'v1.6.0', title: 'ERP 1.6.0', summary: 'Release summary', released_on: '2026-08-28' }, recentReleases: [{ id: 7, version: 'v1.6.0', title: 'ERP 1.6.0', summary: 'Release summary', released_on: '2026-08-28' }] } })
    expect(wrapper.text()).toContain('v1.6.0')
    expect(wrapper.text()).toContain('Release summary')
    expect(wrapper.find('a[href="/settings/changelog"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings/changelog/create"]').exists()).toBe(true)
  })
})
