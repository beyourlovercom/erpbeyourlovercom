import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head:{template:'<span />'}, Link:{props:['href'],template:'<a :href="href"><slot /></a>'} }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default:{template:'<div><slot /></div>'} }))
describe('Release changelog', () => {
  it('renders publishable release records rather than commit rows', () => {
    const wrapper=mount(Index,{props:{canManage:true,releaseNotes:[{id:3,version:'v1.6.0',title:'ERP 1.6.0',summary:'Release summary',status:'published',released_on:'2026-08-28',published_at:'2026-08-28'}]}})
    expect(wrapper.text()).toContain('v1.6.0'); expect(wrapper.text()).toContain('ERP 1.6.0'); expect(wrapper.find('a[href="/guides/changelog/3"]').exists()).toBe(true); expect(wrapper.text()).not.toContain('commit SHA')
  })
})
