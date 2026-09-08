import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import Fx from './Fx.vue'

const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (value:any) => reactive({ ...value, processing:false, recentlySuccessful:false, errors:{}, put }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const rows = [
  { currency:'USD', label:'美元', ecbRate:7.09, manualRate:7.12, effectiveRate:7.12, source:'manual', manualEditable:true },
  { currency:'EUR', label:'欧元', ecbRate:7.8, manualRate:null, effectiveRate:7.8, source:'ecb', manualEditable:true },
  { currency:'PHP', label:'菲律宾比索', ecbRate:0.122, manualRate:null, effectiveRate:0.122, source:'ecb', manualEditable:true },
  { currency:'CNY', label:'人民币', ecbRate:1, manualRate:null, effectiveRate:1, source:'fixed', manualEditable:false },
] as any
const reference = { source:'ECB', available:true, referenceDate:'2026-09-07', stale:false, refreshHours:12, policy:'manual_first' }

describe('Settings FX', () => {
  it('renders automatic reference and manual-first effective rate', () => {
    const wrapper = mount(Fx, { props:{ rows, reference } })
    expect(wrapper.text()).toContain('手动汇率设置')
    expect(wrapper.text()).toContain('手动优先')
    expect(wrapper.text()).toContain('7.1200')
    expect(wrapper.text()).toContain('ECB 自动参考汇率')
  })

  it('clears a manual rate before saving and submits the settings form', async () => {
    const wrapper = mount(Fx, { props:{ rows, reference } })
    const restore = wrapper.findAll('button').find((button) => button.text() === '恢复自动')!
    await restore.trigger('click')
    expect(wrapper.text()).toContain('7.0900')
    const save = wrapper.findAll('button').find((button) => button.text() === '保存全部汇率')!
    await save.trigger('click')
    expect(put).toHaveBeenCalledWith('/settings/fx', { preserveScroll:true })
  })
})
