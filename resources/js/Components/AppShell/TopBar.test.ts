import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TopBar from './TopBar.vue'

vi.mock('../UI/UiButton.vue', () => ({
  default: { props: ['class', 'tone', 'size', 'ariaExpanded', 'ariaLabel'], template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
}))

describe('TopBar breadcrumbs', () => {
  it('renders plain string breadcrumbs as spans', () => {
    const wrapper = mount(TopBar, { props: { title: 'Test', breadcrumbs: ['BYL ERP', '产品'], mobileExpanded: false } })
    const spans = wrapper.findAll('.shell-breadcrumbs span')
    expect(spans).toHaveLength(2)
    expect(spans[0].text()).toBe('BYL ERP')
    expect(spans[1].text()).toBe('产品')
  })

  it('renders breadcrumb objects with href as links', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '列表']
    const wrapper = mount(TopBar, { props: { title: '列表', breadcrumbs, mobileExpanded: false } })
    const links = wrapper.findAll('.shell-breadcrumbs a')
    expect(links).toHaveLength(2)
    expect(links[0].attributes('href')).toBe('/dashboard')
    expect(links[0].text()).toBe('BYL ERP')
    expect(links[1].attributes('href')).toBe('/products')
  })

  it('renders last breadcrumb as non-navigable span', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, 'Order 123']
    const wrapper = mount(TopBar, { props: { title: 'Order', breadcrumbs, mobileExpanded: false } })
    const spans = wrapper.findAll('.shell-breadcrumbs span')
    expect(spans).toHaveLength(1)
    expect(spans[0].text()).toBe('Order 123')
  })
})
