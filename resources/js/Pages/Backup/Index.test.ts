import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

afterEach(() => vi.unstubAllGlobals())

describe('Manual database backup', () => {
  it('only shows the single manual action to authorized staff', () => {
    const wrapper = mount(Index, { props: { canDownloadDatabase: true } })
    expect(wrapper.findAll('button')).toHaveLength(1)
    expect(wrapper.text()).toContain('一键备份并下载到电脑')
    expect(wrapper.text()).toContain('最多保留 3 份')
    expect(wrapper.text()).not.toContain('自动备份')
    expect(wrapper.text()).not.toContain('GitHub')
  })

  it('does not render a download control for view-only staff', () => {
    const wrapper = mount(Index, { props: { canDownloadDatabase: false } })
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toContain('仅管理员')
  })

  it('starts a local file download only after a successful gzip response', async () => {
    const filename = 'erp-db-20261126-140000-000000-abcd1234.sql.gz'
    const blob = new Blob(['synthetic gzip'], { type: 'application/gzip' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, headers: new Headers({
      'content-type': 'application/gzip', 'content-disposition': 'attachment; filename="' + filename + '"',
    }), blob: async () => blob }))
    const create = vi.fn().mockReturnValue('blob:synthetic-test')
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: vi.fn() })
    const clicked = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe(filename)
    })
    const wrapper = mount(Index, { props: { canDownloadDatabase: true } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(create).toHaveBeenCalledWith(blob)
    expect(clicked).toHaveBeenCalledOnce()
    expect(wrapper.get('[role=status]').text()).toContain('已发起浏览器下载')
    clicked.mockRestore()
    wrapper.unmount()
  })

  it('reports failure and unlocks the button without downloading an error response', async () => {
    const request = vi.fn().mockResolvedValue({ ok: false })
    vi.stubGlobal('fetch', request)
    const wrapper = mount(Index, { props: { canDownloadDatabase: true } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(request).toHaveBeenCalledWith('/settings/backup/download', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }))
    expect(wrapper.get('[role=alert]').text()).toContain('失败')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
  })
})
