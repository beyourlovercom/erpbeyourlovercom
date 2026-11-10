import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import GridBatchFooterControls from './GridBatchFooterControls.vue'

const options = [
  {value:'approve', label:'批量通过'},
  {value:'reject', label:'批量驳回', reasonRequired:true},
  {value:'archive', label:'批量归档'},
]
describe('Global Grid V1.2 footer-only batch controls',()=>{
  it('renders nothing when no rows are selected',()=>{
    const wrapper=mount(GridBatchFooterControls,{props:{selectedCount:0,options}})
    expect(wrapper.find('[data-testid=grid-batch-footer]').exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
  it('shows selection count and dropdown but no Apply until action chosen',async()=>{
    const wrapper=mount(GridBatchFooterControls,{props:{selectedCount:3,options}})
    expect(wrapper.text()).toContain('已选 3 条')
    expect(wrapper.findAll('button')).toHaveLength(0)
    await wrapper.get('select').setValue('archive')
    expect(wrapper.get('button').text()).toBe('应用')
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({action:'archive',reason:''})
  })
  it('reject needs valid inline reason and never opens another form',async()=>{
    const wrapper=mount(GridBatchFooterControls,{props:{selectedCount:1,options}})
    await wrapper.get('select').setValue('reject')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    await wrapper.get('input').setValue('地址')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    await wrapper.get('input').setValue('地址信息错误')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({action:'reject',reason:'地址信息错误'})
    await wrapper.setProps({selectedCount:0})
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
