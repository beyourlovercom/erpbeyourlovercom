type RecoveryDependencies = {
    target: EventTarget
    storage: Pick<Storage, 'getItem' | 'setItem'>
    reload: () => void
    now: () => number
}

const RELOAD_KEY = 'erp:asset-recovery:last-reload:v1'
const RETRY_WINDOW_MS = 60_000

// A stale, already-open tab may request a hashed Vue page chunk removed by a release.
// Retry once with a fresh document, while preserving unsaved workspace drafts.
export function installAssetRecovery({
    target = window,
    storage,
    reload = () => window.location.reload(),
    now = () => Date.now(),
}: Partial<RecoveryDependencies> = {}): void {
    let draftDirty = false
    target.addEventListener('erp:workspace-dirty', (event) => {
        draftDirty = !!(event as CustomEvent<{ dirty?: boolean }>).detail?.dirty
    })

    target.addEventListener('vite:preloadError', (event) => {
        // Without preventDefault, Vite leaves an unhandled import rejection.
        event.preventDefault()
        if (draftDirty) return

        try {
            const current = now()
            const markerStore = storage ?? window.sessionStorage
            const previous = Number(markerStore.getItem(RELOAD_KEY) || 0)
            if (previous > 0 && Math.abs(current - previous) < RETRY_WINDOW_MS) {
                return // A persistent 404 must never cause a reload loop.
            }
            markerStore.setItem(RELOAD_KEY, String(current))
        } catch {
            return // No reliable retry marker: never risk an infinite loop.
        }

        reload()
    })
}
