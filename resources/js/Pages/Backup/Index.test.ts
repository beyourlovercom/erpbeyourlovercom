import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const props = {
  actions: [
    { key: 'create', label: '立即备份', available: false },
    { key: 'download', label: '下载备份', available: false },
    { key: 'encrypted_download', label: '加密并下载', available: false },
    { key: 'publish_github', label: '加密并发布到 GitHub', available: false },
  ],
  history: [], storageUsage: null,
  policy: { format: 'backup-format-v1', includedRoots: ['storage/app/private', 'storage/app/public'], workspace: 'storage/app/backups', status: '待 M1.2 引擎接入' },
  restore: { local: { available: false, status: '待 M1.4 恢复功能接入' }, github: { available: false, status: '待 M1.3/M1.4 接入' } },
}

describe('Backup index pre-engine state', () => {
  it('shows approved labels and keeps every operation unavailable', () => {
    const wrapper = mount(Index, { props })
    for (const label of ['立即备份', '下载备份', '加密并下载', '加密并发布到 GitHub', '备份历史', '存储使用情况', '备份策略', '本地恢复', 'GitHub 恢复']) expect(wrapper.text()).toContain(label)
    expect(wrapper.findAll('button').every(button => button.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.text()).toContain('暂无备份记录。系统不会在引擎接入前生成示例记录。')
  })
})
