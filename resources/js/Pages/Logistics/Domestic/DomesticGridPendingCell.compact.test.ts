import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Cell from './DomesticGridPendingCell.vue'

const source = {
  order_id: 42, review_status: 'approved', shipment_status: 'pending',
  carrier: '4PX', service: 'SD经济挂号-带电', carrier_ready: true,
  tracking_number: null,
}
const params = () => ({
  data: { id: 42, order_number: '007-OLD-42', review_status: 'approved', shipment_status: 'pending' },
  compactActions: true, view: 'pending',
  canOperate: true, canReview: true, canTrackingSync: false,
  carrierOptions: [{ value: '4PX', label: '速达', services: [{ value: 'SD经济挂号-带电', label: '带电' }] }],
  source: () => source, carrierBusy: () => false, trackingBusy: () => false,
  feedback: () => '', saveCarrier: vi.fn(), saveTracking: vi.fn(),
  confirmShip: vi.fn(), reopenPending: vi.fn(), rejectPending: vi.fn(), reviewBusy: () => false,
  syncEligible: () => false, syncBusy: () => false, syncTracking: vi.fn(),
})

describe('G-P1 compact cumulative Speeda history actions', () => {
  afterEach(() => { document.querySelectorAll('.domestic-pending-compact-menu,.domestic-pending-popover').forEach(n => n.remove()) })
  it('saves horizontal space and reuses the guarded inline shipment editor', async () => {
    const p = params()
    const view = mount(Cell, { props: { params: p }, attachTo: document.body })
    expect(view.findAll('button')).toHaveLength(1)
    await view.get('button[aria-label="操作"]').trigger('click')
    expect(document.querySelector('.domestic-pending-compact-menu')).not.toBeNull()
    const edit = [...document.querySelectorAll('.domestic-pending-compact-menu button')]
      .find(b => b.textContent === '填写物流') as HTMLButtonElement
    expect(edit).toBeTruthy()
    edit.click()
    await view.vm.$nextTick()
    expect(document.querySelector('.domestic-pending-compact-menu')).toBeNull()
    const editor = document.querySelector('.domestic-pending-popover') as HTMLElement
    expect(editor).toBeTruthy()
    const ship = [...editor.querySelectorAll('button')].find(b => b.textContent === '确认发货') as HTMLButtonElement
    expect(ship.disabled).toBe(true)
    expect(p.confirmShip).not.toHaveBeenCalled()
    const cancel = [...editor.querySelectorAll('button')].find(b => b.textContent === '取消') as HTMLButtonElement
    cancel.click()
    await view.vm.$nextTick()
    expect(document.querySelector('.domestic-pending-popover')).toBeNull()
    view.unmount()
  })
  it('adds commercial reject in direct and compact pending actions, even before review and with staged Tracking', async () => {
    for (const compactActions of [false, true]) {
      for (const review_status of ['pending', 'approved']) {
        const p = { ...params(), compactActions, source: () => ({ ...source, review_status, tracking_number: 'STAGED-1' }) }
        const wrapper = mount(Cell, { props: { params: p }, attachTo: document.body })
        if (compactActions) await wrapper.get('button[aria-label="操作"]').trigger('click')
        const reject = compactActions
          ? [...document.querySelectorAll('.domestic-pending-compact-menu button')].find(button => button.textContent === '驳回') as HTMLButtonElement
          : wrapper.get('button.pending-reject').element as HTMLButtonElement
        expect(reject, `${review_status} compact=${compactActions}`).toBeTruthy()
        reject.click()
        expect(p.rejectPending).toHaveBeenCalledWith(42)
        expect(p.confirmShip).not.toHaveBeenCalled()
        wrapper.unmount()
      }
    }
  })
  it('hides commercial reject without authority or after shipping', () => {
    for (const options of [
      { canReview: false, review_status: 'approved', shipment_status: 'pending' },
      { canReview: true, review_status: 'stockout', shipment_status: 'pending' },
      { canReview: true, review_status: 'approved', shipment_status: 'shipped' },
    ]) {
      const p = { ...params(), canReview: options.canReview,
        source: () => ({ ...source, review_status: options.review_status, shipment_status: options.shipment_status }) }
      const wrapper = mount(Cell, { props: { params: p } })
      expect(wrapper.find('.pending-reject').exists()).toBe(false)
      wrapper.unmount()
    }
  })
  it('limits the new reject action to the pending view without expanding all-domestic controls', () => {
    const p = { ...params(), view: 'all' }
    const wrapper = mount(Cell, { props: { params: p } })
    expect(wrapper.find('.pending-reject').exists()).toBe(false)
    wrapper.unmount()
  })
  it('never bypasses overseas MorePlus proof requirement via compact or direct actions', async () => {
    for(const compactActions of [false,true]){
      const p={...params(),compactActions,source:()=>({...source,tracking_number:'CF123456789DE'}),
        requiresOutboundReference:()=>true}
      const view=mount(Cell,{props:{params:p},attachTo:document.body})
      if(compactActions){
        await view.get('button[aria-label="操作"]').trigger('click')
        const menu=document.querySelector('.domestic-pending-compact-menu')!
        const ship=[...menu.querySelectorAll('button')].find(b=>b.textContent==='确认发货') as HTMLButtonElement
        expect(ship.disabled).toBe(true)
        const edit=[...menu.querySelectorAll('button')].find(b=>b.textContent==='填写物流') as HTMLButtonElement
        edit.click()
      }else await view.find('button.pending-edit').trigger('click')
      await view.vm.$nextTick()
      const editor=document.querySelector('.domestic-pending-popover')!
      expect(editor.querySelector('input[aria-label="真实出库单号"]')).not.toBeNull()
      const confirm=[...editor.querySelectorAll('button')].find(b=>b.textContent==='确认发货') as HTMLButtonElement
      expect(confirm.disabled).toBe(true)
      expect(p.confirmShip).not.toHaveBeenCalled()
      view.unmount()
    }
  })
  it('opens shared create form from the shipment popover and keeps save/ship explicit', async()=>{
    const p={...params(),compactActions:false}
    type TestCarrier={value:string;label:string;services:{value:string;label:string}[]}
    const catalog=ref<TestCarrier[]>([...p.carrierOptions])
    const onCreated=vi.fn<(carrier:TestCarrier)=>void>()
    const openCarrierCreate=vi.fn((callback:(carrier:TestCarrier)=>void)=>{
      onCreated.mockImplementation(created=>{catalog.value=[...catalog.value,created];callback(created)})
    })
    const view=mount(Cell,{props:{params:{...p,getCarrierOptions:()=>catalog.value,openCarrierCreate}},attachTo:document.body})
    await view.get('button.pending-edit').trigger('click')
    const selector=document.querySelector('.domestic-pending-popover select[aria-label="物流商"]') as HTMLSelectElement
    expect(Array.from(selector.options).at(-1)?.text).toContain('添加物流商')
    selector.value='__add_carrier__'
    selector.dispatchEvent(new Event('change',{bubbles:true}))
    await view.vm.$nextTick()
    expect(openCarrierCreate).toHaveBeenCalledOnce()
    expect(p.saveCarrier).not.toHaveBeenCalled()
    expect(p.confirmShip).not.toHaveBeenCalled()
    onCreated({value:'德邦物流',label:'德邦物流',services:[{value:'默认',label:'默认'}]})
    await view.vm.$nextTick()
    expect(selector.value).toBe('德邦物流')
    expect((document.querySelector('.domestic-pending-popover select[aria-label="服务类型"]') as HTMLSelectElement).value).toBe('默认')
    const confirm=document.querySelector('.domestic-pending-popover button:not([disabled])') as HTMLButtonElement
    confirm.click()
    expect(p.saveCarrier).toHaveBeenCalledWith(42,'德邦物流','默认')
    expect(p.confirmShip).not.toHaveBeenCalled()
    view.unmount()
  })
  it('with no permission and no sync-eligible shipment renders only a dash', () => {
    const p = { ...params(), canOperate: false, canReview: false }
    const view = mount(Cell, { props: { params: p } })
    expect(view.text()).toContain('—')
    expect(view.find('button').exists()).toBe(false)
    view.unmount()
  })
  it('retains the original unmodified direct actions on daily and pending views', () => {
    const view = mount(Cell, { props: { params: { ...params(), compactActions: false } } })
    expect(view.find('button[aria-label="操作"]').exists()).toBe(false)
    expect(view.findAll('button').map(b => b.text())).toContain('确认发货')
    view.unmount()
  })
})

describe('Domestic Shipping Hold V1 history isolation',()=>{
  it('cannot expose carrier, shipping or review mutation on a held history row',()=>{
    const p={...params(),view:'all',isHeld:()=>true}
    const wrapper=mount(Cell,{props:{params:p}})
    expect(wrapper.find('button').exists()).toBe(false)
    wrapper.unmount()
  })
})
