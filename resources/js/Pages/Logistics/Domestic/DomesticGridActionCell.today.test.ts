import {mount} from '@vue/test-utils'
import {describe,expect,it,vi} from 'vitest'
import Cell from './DomesticGridActionCell.vue'

const base={
  data:{id:42,review_status:'已通过',shipment_status:'shipped',tracking_sync_code:'failed'},
  view:'today', canReview:false,canOperate:false,busy:false,canTrackingSync:true,
  syncEligible:vi.fn(()=>true),syncBusy:vi.fn(()=>false),syncTracking:vi.fn(),
  native:vi.fn(),approve:vi.fn(),stockout:vi.fn(),restock:vi.fn(),reopen:vi.fn(),reject:vi.fn(),
}
describe('R4-4 today fixed-right original operations parity',()=>{
  it('shows and calls existing retry for authorized sync-eligible shipment only',async()=>{
    const p={...base,syncTracking:vi.fn()}
    const wrapper=mount(Cell,{props:{params:p}})
    const b=wrapper.get('button')
    expect(b.text()).toBe('重新同步')
    await b.trigger('click')
    expect(p.syncTracking).toHaveBeenCalledOnce()
    expect(p.syncTracking).toHaveBeenCalledWith(42)
    expect(p.native).not.toHaveBeenCalled()
  })
  it('does not expose shipping or sync operations to a viewer',()=>{
    const wrapper=mount(Cell,{props:{params:{...base,canTrackingSync:false,canOperate:false}}})
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toContain('—')
  })
  it('retains the reviewer recheck plus authorized sync in a single pinned cell',async()=>{
    const p={...base,canReview:true,
      data:{...base.data,shipment_status:'pending',tracking_sync_code:'pending'},
      reopen:vi.fn(),syncTracking:vi.fn()}
    const wrapper=mount(Cell,{props:{params:p}})
    expect(wrapper.findAll('button').map(x=>x.text())).toEqual(['重新审核','同步官网物流'])
    await wrapper.findAll('button')[0].trigger('click')
    await wrapper.findAll('button')[1].trigger('click')
    expect(p.reopen).toHaveBeenCalledOnce()
    expect(p.syncTracking).toHaveBeenCalledWith(42)
  })
  it('blocks an in-flight retry and excludes invalid tracking rows',async()=>{
    const busy=mount(Cell,{props:{params:{...base,syncBusy:()=>true}}})
    expect(busy.get('button').attributes('disabled')).toBeDefined()
    const ineligible=mount(Cell,{props:{params:{...base,syncEligible:()=>false}}})
    expect(ineligible.find('button').exists()).toBe(false)
  })
  it('does not change native operation fallback for the remaining views',async()=>{
    const p={...base,view:'4px',canOperate:true,native:vi.fn()}
    const wrapper=mount(Cell,{props:{params:p}})
    expect(wrapper.get('button').text()).toBe('原操作')
    await wrapper.get('button').trigger('click')
    expect(p.native).toHaveBeenCalledOnce()
  })
})
