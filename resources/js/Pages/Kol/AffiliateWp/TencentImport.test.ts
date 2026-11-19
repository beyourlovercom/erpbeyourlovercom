import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TencentImport from './TencentImport.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))
vi.mock('axios', () => ({ default: { post: postMock } }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const mountPage = () => mount(TencentImport, { props: { batches: [] } })
const choose = async (wrapper: ReturnType<typeof mountPage>) => {
  const input = wrapper.find('input[type="file"]')
  const file = new File(['synthetic'], 'tencent.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  Object.defineProperty(input.element, 'files', { value: [file] })
  await input.trigger('change')
}

describe('Tencent XLSX admin import', () => {
  beforeEach(() => postMock.mockReset())

  it('shows mapping, owner verification, safety notice and disabled submit without file', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('腾讯网红表导入')
    expect(wrapper.text()).toContain('网红业务负责人')
    expect(wrapper.text()).toContain('不是物流跟单/发货负责人')
    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
  })

  it('blocks unconfirmed business owner mapping before sending data', async () => {
    const wrapper = mountPage()
    await choose(wrapper)
    const fields = wrapper.findAll('input.erp-input')
    await fields[5].setValue('负责人')
    await wrapper.find('button').trigger('click')
    expect(wrapper.text()).toContain('请先确认所选列是网红业务负责人')
    expect(postMock).not.toHaveBeenCalled()
  })

  it('sends only explicit column mappings and shows the review path', async () => {
    postMock.mockResolvedValue({ data: { batch: {
      id: 7, original_filename: 'tencent.xlsx', checksum: 'sha',
      total_rows: 1, distinct_source_identities: 1, summary: {},
      rows: [{ source_row_number: 2, identity: { email: 'sample@example.test' },
        kol: null, affiliate_account_id: 2179, status: 'unresolved', message: 'review' }],
    } } })
    const wrapper = mountPage()
    await choose(wrapper)
    const fields = wrapper.findAll('input.erp-input')
    await fields[5].setValue('业务负责人')
    await wrapper.find('input[type="checkbox"]').setValue(true)
    await wrapper.find('button').trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/affiliatewp/tencent-import/preview', expect.any(FormData))
    const payload = postMock.mock.calls[0][1] as FormData
    expect(payload.get('sheet_name')).toBe('网红-KOL')
    expect(payload.get('owner_column')).toBe('业务负责人')
    expect(payload.get('owner_confirmed')).toBe('1')
    expect(wrapper.text()).toContain('Affiliate 快照')
    expect(wrapper.text()).toContain('2179')
    expect(wrapper.find('a[href="/kol/owner-mapping"]').exists()).toBe(true)
  })
})
