import { onBeforeUnmount, onMounted, watch, type WatchSource } from 'vue'

/**
 * Small opt-in contract with the existing AuthenticatedLayout workspace.
 * No form values, passwords or drafts are persisted: only a dirty boolean.
 * Existing form submit/validation semantics remain owned by each page.
 */
export function useWorkspaceDraftGuard(isDirty: WatchSource<boolean>): void {
  let active = false
  const publish = (dirty: boolean) => {
    if (!active || typeof window === 'undefined') return
    window.dispatchEvent(new CustomEvent('erp:workspace-dirty', { detail: { dirty } }))
  }
  const stop = watch(isDirty, dirty => publish(!!dirty), { flush: 'sync' })
  onMounted(() => {
    active = true
    publish(!!(typeof isDirty === 'function' ? isDirty() : isDirty.value))
  })
  onBeforeUnmount(() => {
    publish(false)
    active = false
    stop()
  })
}
