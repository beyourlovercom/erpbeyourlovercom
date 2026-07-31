import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Foundation from './Foundation.vue'

describe('Architecture foundation page', () => {
    it('renders typed props and updates basic UI state', async () => {
        const wrapper = mount(Foundation, { props: { title: 'BYL ERP', status: 'SYSTEM ONLINE' } })
        expect(wrapper.text()).toContain('BYL ERP')
        expect(wrapper.text()).toContain('SYSTEM ONLINE')
        await wrapper.get('button').trigger('click')
        expect(wrapper.text()).toContain('Foundation 已确认')
    })
})
