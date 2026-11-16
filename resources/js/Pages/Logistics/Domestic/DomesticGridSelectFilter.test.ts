import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DomesticGridSelectFilter from './DomesticGridSelectFilter.vue'

describe('unified domestic server-side select filter', () => {
  it('offers real carrier options and emits canonical values instead of filtering the current page', async () => {
    const onServerFilter=vi.fn()
    const wrapper=mount(DomesticGridSelectFilter,{props:{params:{
      filterValue:'4px',placeholder:'全部物流商',onServerFilter,
      options:[{value:'4px',label:'速达'},{value:'Weship',label:'纬狮'},{value:'Deppon',label:'德邦'}],
      column:{getColDef:()=>({headerName:'物流商'})},
    }}})
    expect(wrapper.get('select').attributes('aria-label')).toBe('筛选列 物流商')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('4px')
    await wrapper.get('select').setValue('Weship')
    expect(onServerFilter).toHaveBeenCalledWith('Weship')
    await wrapper.get('select').setValue('')
    expect(onServerFilter).toHaveBeenCalledWith('')
  })
})
