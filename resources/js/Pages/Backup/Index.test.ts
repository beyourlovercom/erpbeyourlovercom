import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
afterEach(() => vi.unstubAllGlobals())
const file = {filename:'erp-db-20261126-140000-000000-abcd1234.sql.gz',size:1234}
const ok = (data: unknown) => ({ok:true,json:async()=>data})
describe('Two-stage manual backup', () => {
  it('creates backup without downloading and exposes a repeatable file link', async () => {
    const request=vi.fn().mockResolvedValueOnce(ok({files:[]})).mockResolvedValueOnce(ok({success:true,backup:file,files:[file]}))
    vi.stubGlobal('fetch',request)
    const w=mount(Index,{props:{canDownloadDatabase:true}})
    await flushPromises()
    expect(w.find('a[download]').exists()).toBe(false)
    await w.get('button').trigger('click');await flushPromises()
    expect(request).toHaveBeenCalledTimes(2)
    expect(request).toHaveBeenLastCalledWith('/settings/backup/create',expect.objectContaining({method:'POST'}))
    expect(w.get('[role=status]').text()).toContain('数据库备份成功')
    expect(w.get('a[download]').attributes('href')).toBe('/settings/backup/file?filename='+file.filename)
  })
  it('lists existing backup after reopening page, with no new creation',async()=>{
    const request=vi.fn().mockResolvedValue(ok({files:[file]}));vi.stubGlobal('fetch',request)
    const w=mount(Index,{props:{canDownloadDatabase:true}});await flushPromises()
    expect(w.get('a[download]').attributes('download')).toBe(file.filename)
    expect(request).toHaveBeenCalledTimes(1)
  })
  it('does not access files for non-authorized staff',async()=>{
    const request=vi.fn();vi.stubGlobal('fetch',request)
    const w=mount(Index,{props:{canDownloadDatabase:false}});await flushPromises()
    expect(w.find('button').exists()).toBe(false);expect(request).not.toHaveBeenCalled()
  })
  it('does not claim success on a rejected create',async()=>{
    const request=vi.fn().mockResolvedValueOnce(ok({files:[file]})).mockResolvedValueOnce({ok:false}).mockResolvedValueOnce(ok({files:[file]}));vi.stubGlobal('fetch',request)
    const w=mount(Index,{props:{canDownloadDatabase:true}});await flushPromises()
    await w.get('button').trigger('click');await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('未确认')
    expect(w.find('a[download]').exists()).toBe(true)
  })
})
