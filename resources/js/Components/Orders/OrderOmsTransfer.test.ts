import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OrderOmsTransfer from './OrderOmsTransfer.vue'

vi.mock('axios', () => ({ default: { post: vi.fn(), isAxiosError: vi.fn(() => false) } }))
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() } }))

const props = {
  exportUrl: '/orders/oms/export',
  canImport: true,
  canConfirmImport: false,
  canExport: true,
  total: 8,
  selectedOrderIds: [] as number[],
}

beforeEach(() => {
  vi.restoreAllMocks()
  vi.mocked(axios.post).mockReset()
  vi.mocked(router.reload).mockReset()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

async function readyForConfirm() {
  const file = new File(['synthetic xlsx bytes'], 'p3b-test.xlsx', {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  const wrapper = mount(OrderOmsTransfer, {
    props: { ...props, canConfirmImport: true },
    global: { stubs: { Teleport: true } },
  })
  await wrapper.get('.oms-transfer-actions button').trigger('click')
  await wrapper.get('#oms-import-type').setValue('website')
  const fileInput = wrapper.get('input[type="file"]')
  Object.defineProperty(fileInput.element, 'files', { configurable: true, value: [file] })
  await fileInput.trigger('change')
  vi.mocked(axios.post).mockResolvedValueOnce({ data: {
    count: 1, row_count: 2, valid_count: 1, errors: [], token: 'signed-preview-token',
    preview: [{ row: 2, order_number: 'P3B-TEST-001', customer: 'Synthetic Buyer', country: 'US', sku_detail: 'MF414A × 2', total_quantity: 2 }],
  } } as never)
  const inspectButton = wrapper.findAll('button').find(button => button.text().includes('上传并预检'))
  if (!inspectButton) throw new Error('Upload preflight button is missing')
  await inspectButton.trigger('click')
  await flushPromises()
  return { wrapper, file }
}

describe('OMS P3 preview and confirm production gates', () => {
  it('offers template and XLSX preflight but never offers a confirm-create control', async () => {
    const wrapper = mount(OrderOmsTransfer, {
      props,
      global: { stubs: { Teleport: true } },
    })
    await wrapper.get('button').trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.get('a.oms-template-link').attributes('href')).toBe('/orders/oms/import/template')
    expect(wrapper.find('input[type="file"]').attributes('accept')).toContain('.xlsx')
    expect(wrapper.get('button').text()).toContain('导入')
    expect(wrapper.find('button.oms-import-commit').exists()).toBe(false)
    expect(wrapper.text()).toContain('预检模式：创建订单未开放')
    expect(wrapper.text()).toContain('预检不会创建订单')
  })

  it('only reveals confirm-create control after a separately authorized write gate', async () => {
    const wrapper = mount(OrderOmsTransfer, {
      props: { ...props, canConfirmImport: true },
      global: { stubs: { Teleport: true } },
    })
    await wrapper.get('button').trigger('click')
    expect(wrapper.find('button.oms-import-commit').exists()).toBe(true)
    expect(wrapper.get('button.oms-import-commit').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('预检模式：创建订单未开放')
  })

  it('keeps the import modal completely hidden when P3-A preview is OFF', () => {
    const wrapper = mount(OrderOmsTransfer, {
      props: { ...props, canImport: false, canConfirmImport: false },
      global: { stubs: { Teleport: true } },
    })
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('posts the exact previewed file only after the explicit browser confirmation', async () => {
    const { wrapper, file } = await readyForConfirm()
    expect(axios.post).toHaveBeenCalledTimes(1)
    expect(axios.post).toHaveBeenNthCalledWith(1, '/orders/oms/import/preview', expect.any(FormData))
    const previewPayload = vi.mocked(axios.post).mock.calls[0]?.[1] as FormData
    expect(previewPayload.get('file')).toBe(file)
    expect(previewPayload.get('import_type')).toBe('website')

    vi.mocked(axios.post).mockResolvedValueOnce({ data: {
      created: 1, orders: [{ id: 901, erp_order_id: '007-1106-P3B01' }],
    } } as never)
    await wrapper.get('button.oms-import-commit').trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('确定批量创建 1 个真实 ERP 网站订单吗？'))
    expect(axios.post).toHaveBeenNthCalledWith(2, '/orders/oms/import/confirm', expect.any(FormData))
    const confirmPayload = vi.mocked(axios.post).mock.calls[1]?.[1] as FormData
    expect(confirmPayload.get('file')).toBe(file)
    expect(confirmPayload.get('token')).toBe('signed-preview-token')
    expect(confirmPayload.get('import_type')).toBe('website')
    expect(confirmPayload.get('confirm')).toBe('1')
    expect(wrapper.text()).toContain('批量创建完成：1 个订单。')
    expect(router.reload).toHaveBeenCalledWith({ only: ['orders'] })
  })

  it('requires an explicit import type and invalidates the proof when it changes', async () => {
    const wrapper = mount(OrderOmsTransfer, {
      props: { ...props, canConfirmImport: true },
      global: { stubs: { Teleport: true } },
    })
    await wrapper.get('.oms-transfer-actions button').trigger('click')
    expect(wrapper.get('#oms-import-type').element).toBeTruthy()
    const uploadButton = wrapper.findAll('button').find(button => button.text().includes('上传并预检'))
    expect(uploadButton?.attributes('disabled')).toBeDefined()
    await wrapper.get('#oms-import-type').setValue('kol')
    expect((wrapper.get('#oms-import-type').element as HTMLSelectElement).value).toBe('kol')
    const fileInput = wrapper.get('input[type="file"]')
    Object.defineProperty(fileInput.element, 'files', { configurable: true, value: [new File(['fake'], 'test.xlsx')] })
    await fileInput.trigger('change')
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { count: 1, row_count: 1, valid_count: 1, errors: [], token: 'signed', preview: [] } } as never)
    const activeUpload = wrapper.findAll('button').find(button => button.text().includes('上传并预检'))
    expect(activeUpload?.attributes('disabled')).toBeUndefined()
    await activeUpload?.trigger('click')
    await flushPromises()
    expect((vi.mocked(axios.post).mock.calls[0]?.[1] as FormData).get('import_type')).toBe('kol')
    expect(wrapper.get('button.oms-import-commit').attributes('disabled')).toBeUndefined()
    await wrapper.get('#oms-import-type').setValue('manual_other')
    expect(wrapper.get('button.oms-import-commit').attributes('disabled')).toBeDefined()
    expect(axios.post).toHaveBeenCalledTimes(1)
  })

  it('does not call the confirm endpoint when the browser confirmation is cancelled', async () => {
    const { wrapper } = await readyForConfirm()
    vi.mocked(window.confirm).mockReturnValue(false)
    await wrapper.get('button.oms-import-commit').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalled()
    expect(axios.post).toHaveBeenCalledTimes(1)
    expect(axios.post).toHaveBeenCalledWith('/orders/oms/import/preview', expect.any(FormData))
  })
})
