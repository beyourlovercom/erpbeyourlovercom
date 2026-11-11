import { defineComponent, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { useWorkspaceDraftGuard } from './useWorkspaceDraftGuard'

describe('R3-C real form dirty event contract', () => {
  const events: boolean[] = []
  const handler = (event: Event) => events.push(!!(event as CustomEvent<{ dirty?: boolean }>).detail?.dirty)
  beforeEach(() => {
    events.length = 0
    window.addEventListener('erp:workspace-dirty', handler)
  })
  afterEach(() => window.removeEventListener('erp:workspace-dirty', handler))

  it('notifies mount, dirty edits, saved state and unmount without carrying form values', async () => {
    const draft = ref(false)
    const Component = defineComponent({
      setup() {
        useWorkspaceDraftGuard(() => draft.value)
        return () => null
      },
    })
    const wrapper = mount(Component)
    expect(events).toEqual([false])
    draft.value = true
    await nextTick()
    expect(events).toEqual([false, true])
    draft.value = false
    await nextTick()
    expect(events).toEqual([false, true, false])
    wrapper.unmount()
    expect(events).toEqual([false, true, false, false])
  })
  it('does not leak edit content, only booleans', async () => {
    const draftText = ref('')
    const listener = vi.fn()
    window.addEventListener('erp:workspace-dirty', listener)
    const Component = defineComponent({
      setup() { useWorkspaceDraftGuard(() => draftText.value.length > 0); return () => null },
    })
    const wrapper = mount(Component)
    draftText.value = 'confidential field in form'
    await nextTick()
    expect(JSON.stringify(listener.mock.calls)).not.toContain('confidential field')
    expect(listener.mock.calls.at(-1)?.[0].detail).toEqual({ dirty: true })
    wrapper.unmount()
    window.removeEventListener('erp:workspace-dirty', listener)
  })
})
