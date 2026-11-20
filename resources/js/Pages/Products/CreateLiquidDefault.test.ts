import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ProductCreate from './Create.vue'

const { postSpy } = vi.hoisted(() => ({ postSpy: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { post: postSpy },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('../../Components/Products/ProductSectionRail.vue', () => ({ default: { template: '<div />' } }))

describe('Product create liquid default', () => {
  it('preselects only exact liquid SKU variants and permits manual override', async () => {
    postSpy.mockClear()
    const wrapper = mount(ProductCreate)
    const sku = wrapper.get('#create-sku')
    const liquidButtons = wrapper.findAll('[aria-label="液体属性"] button')
    await sku.setValue('bv339a-p')
    expect(liquidButtons[0].classes()).toContain('selected')
    await sku.setValue('BV339A-Y')
    expect(liquidButtons[0].classes()).toContain('selected')
    await sku.setValue('BV339A-Z')
    expect(liquidButtons[1].classes()).toContain('selected')
    await sku.setValue('BY489A')
    await liquidButtons[1].trigger('click')
    expect(liquidButtons[1].classes()).toContain('selected')
  })
})
