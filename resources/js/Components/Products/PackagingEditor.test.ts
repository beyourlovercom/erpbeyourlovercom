import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import PackagingEditor from './PackagingEditor.vue'

const calls = vi.hoisted(() => ({
  put: vi.fn(), post: vi.fn(), destroy: vi.fn(), confirm: vi.fn(() => true), alert: vi.fn(),
}))
vi.mock('@inertiajs/vue3', () => ({
  router: { post: calls.post, delete: calls.destroy },
  useForm: (initial: Record<string, unknown>) => reactive({
    ...initial, processing: false, errors: {},
    put: calls.put,
  }),
}))

const product = { id: 17, sku: 'UAT-PACK-17', name: 'UAT Product' }
const presets = [
  { key: 'carton', label: '纸箱' },
  { key: 'mailer', label: '信封' },
]
const profile = {
  id: 4, preset_key: 'carton', method: '纸箱', scenario: 'UAT only',
  version: 'v1', units_per_carton: 4, inherits_global_template: true,
  rendered_image: { id: 50, name: 'rendered.jpg', url: '/products/packaging/media/50' },
  default_image: { id: 51, name: 'default.jpg', url: '/products/packaging/media/51' },
  examples: [{ id: 52, name: 'sample.jpg', url: '/products/packaging/media/52' }],
}
beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('confirm', calls.confirm)
  vi.stubGlobal('alert', calls.alert)
})

describe('shared packaging editor used by Product360 and legacy URL', () => {
  it('embeds all prior full-edit sections without a second-page return link', () => {
    const w = mount(PackagingEditor, { props: { product, presets, packagingProfile: profile, embedded: true } })
    const text = w.text()
    for (const name of ['包装方式', '方案名称', '几个/箱', '产品专属主示意图', '真实包装案例图', '产品默认示意图覆盖']) {
      expect(text).toContain(name)
    }
    expect((w.find('textarea').element as HTMLTextAreaElement).value).toBe('UAT only')
    expect(w.findAll('.preset-option')).toHaveLength(3)
    expect(w.findAll('img')).toHaveLength(3)
    expect(w.find('a[href="/products/17"]').exists()).toBe(false)
    expect(w.find('.packaging-admin-embedded').exists()).toBe(true)
    expect(w.findAll('.case-grid figure')).toHaveLength(1)
  })

  it('keeps old endpoint writes for existing profile, without changing canonical product', async () => {
    const w = mount(PackagingEditor, { props: { product, presets, packagingProfile: profile, embedded: true } })
    await w.findAll('.preset-option')[1].trigger('click')
    await w.find('.admin-heading button').trigger('click')
    expect(calls.put).toHaveBeenCalledWith('/products/17/packaging', { preserveScroll: true })
    await w.findAll('.preset-option')[2].trigger('click')
    expect(w.find('.meta-grid input').attributes('disabled')).toBeUndefined()
    const deleteButtons = w.findAll('button.text-danger')
    expect(deleteButtons.length).toBeGreaterThan(0)
    await deleteButtons[0].trigger('click')
    expect(calls.destroy).toHaveBeenCalledWith('/products/packaging/media/50', { preserveScroll: true })
  })

  it('preserves compatibility standalone return link and blocks photo upload until profile saved', () => {
    const legacy = mount(PackagingEditor, { props: { product, presets, packagingProfile: profile } })
    expect(legacy.find('a[href="/products/17"]').exists()).toBe(true)
    const fresh = mount(PackagingEditor, { props: { product, presets, packagingProfile: null, embedded: true } })
    const imageInput = fresh.find('.upload-btn input[type=file]')
    Object.defineProperty(imageInput.element, 'files', {
      configurable: true,
      value: [new File(['test'], 'photo.jpg', { type: 'image/jpeg' })],
    })
    imageInput.trigger('change')
    expect(calls.alert).toHaveBeenCalledWith('请先保存包装方案，再上传图片')
    expect(calls.post).not.toHaveBeenCalled()
  })
})
