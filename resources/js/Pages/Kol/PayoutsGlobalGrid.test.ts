import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Payouts from './Payouts.vue'

const { postMock, requestMock } = vi.hoisted(() => ({ postMock: vi.fn(), requestMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { post: postMock },
  useForm: (initial: Record<string, unknown>) => ({
    ...initial, processing: false, errors: {}, post: requestMock,
  }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
const gridStub = {
  name: 'KolModuleGrid',
  props: ['namespace','title','rows','columns','rowKey','enableSelection','selectedIds'],
  emits: ['selection-change'],
  template: '<div data-testid="grid-stub"></div>',
}
const eligible = [
  {referral_id:11,affiliate_id:7,kol_id:5,amount:'4.25',currency:'USD',order_number:'007-9001',source_order_number:'9001',order_reference:'9001',referral_date:null},
  {referral_id:12,affiliate_id:7,kol_id:5,amount:'5.75',currency:'USD',order_number:'007-9002',source_order_number:'9002',order_reference:'9002',referral_date:null},
  {referral_id:13,affiliate_id:8,kol_id:6,amount:'3.00',currency:'EUR',order_number:'007-9003',source_order_number:'9003',order_reference:'9003',referral_date:null},
]
const create = (canRequest=true) => mount(Payouts, {
  props: {connectionKey:'beyourlover.com',eligible,requests:[],canRequest,canApprove:false,canManage:false},
  global: {stubs:{KolModuleGrid:gridStub}},
})
describe('R3 KOL Global Grid exact referral selection safety', () => {
  beforeEach(() => {postMock.mockReset();requestMock.mockReset()})
  it('selects exact eligible referral ids and keeps the amount based on the authorized rows', async () => {
    const wrapper=create()
    const grid=wrapper.findComponent({name:'KolModuleGrid'})
    expect(grid.exists()).toBe(true)
    expect(grid.props('rowKey')).toBe('referral_id')
    expect(grid.props('enableSelection')).toBe(true)
    grid.vm.$emit('selection-change',[{referral_id:12},{referral_id:11}])
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('USD 10.00')
    await wrapper.findAll('button').find(b=>b.text()==='提交审批')!.trigger('click')
    expect(requestMock).toHaveBeenCalledWith('/kol/payouts',expect.objectContaining({preserveScroll:true,onSuccess:expect.any(Function)}))
  })
  it('blocks multi affiliate/currency selection and invisible ids', async () => {
    const wrapper=create()
    const grid=wrapper.findComponent({name:'KolModuleGrid'})
    grid.vm.$emit('selection-change',[{referral_id:11},{referral_id:13}])
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('button').find(b=>b.text()==='提交审批')?.attributes('disabled')).toBeDefined()
    grid.vm.$emit('selection-change',[{referral_id:11},{referral_id:9999999}])
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('button').find(b=>b.text()==='提交审批')?.attributes('disabled')).toBeDefined()
    expect(requestMock).not.toHaveBeenCalled()
  })
  it('removes selection rights without KOL payout permission', () => {
    const wrapper=create(false)
    expect(wrapper.findAll('button').some(b=>b.text()==='提交审批')).toBe(false)
    expect(wrapper.find('[data-testid="grid-stub"]').exists()).toBe(false)
  })
})
