import { describe, expect, it, vi } from 'vitest'
import { installAssetRecovery } from './assetRecovery'

function fixture(clock = 1700000000000) {
    const target = new EventTarget()
    const values = new Map<string, string>()
    const reload = vi.fn()
    const storage = {
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => { values.set(key, value) },
    }
    installAssetRecovery({ target, storage, reload, now: () => clock })
    const failure = () => {
        const event = new Event('vite:preloadError', { cancelable: true })
        target.dispatchEvent(event)
        return event
    }
    return { target, values, reload, failure }
}

describe('ERP P0 stale frontend bundle recovery', () => {
    it('reloads a stale tab once, preventing unhandled chunk rejection and reload loops', () => {
        const f = fixture()
        expect(f.failure().defaultPrevented).toBe(true)
        expect(f.reload).toHaveBeenCalledTimes(1)
        f.failure()
        expect(f.reload).toHaveBeenCalledTimes(1)
    })
    it('never discards unsaved workspace edits during failed navigation', () => {
        const f = fixture()
        f.target.dispatchEvent(new CustomEvent('erp:workspace-dirty', { detail: { dirty: true } }))
        expect(f.failure().defaultPrevented).toBe(true)
        expect(f.reload).not.toHaveBeenCalled()
        f.target.dispatchEvent(new CustomEvent('erp:workspace-dirty', { detail: { dirty: false } }))
        f.failure()
        expect(f.reload).toHaveBeenCalledTimes(1)
    })

    it('does not enter a reload loop even if the previous marker is from the future', () => {
        const f = fixture()
        f.values.set('erp:asset-recovery:last-reload:v1', '1700000020000')
        f.failure()
        expect(f.reload).not.toHaveBeenCalled()
    })

    it('fails closed if browser storage is unavailable', () => {
        const target = new EventTarget()
        const reload = vi.fn()
        installAssetRecovery({
            target, reload, now: () => 1700000000000,
            storage: { getItem: () => { throw new Error('blocked') }, setItem: vi.fn() },
        })
        target.dispatchEvent(new Event('vite:preloadError', { cancelable: true }))
        expect(reload).not.toHaveBeenCalled()
    })
})
