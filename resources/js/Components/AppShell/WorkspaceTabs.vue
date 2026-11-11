<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import type { Page } from '@inertiajs/core'
import { workspaceHotCache, workspaceHotStatus, WORKSPACE_HOT_FRESH_MS } from '../../lib/workspaceHotCache'
import { workspaceViewState } from '../../lib/workspaceViewState'
import {
  closeWorkspaceTab, restoreWorkspaceTabs, storageKeyForUser,
  visitWorkspaceTab, workspaceKey, toggleWorkspacePin, type WorkspaceTab, WORKSPACE_TAB_LIMIT, WORKSPACE_PIN_LIMIT,
} from '../../lib/workspaceTabs'

const props = defineProps<{ currentUrl: string; currentTitle: string; userId: number }>()
const page = usePage()
const hotStatus = workspaceHotStatus
const hotSize = ref(workspaceHotCache.size)
const tabs = ref<WorkspaceTab[]>([])
const ready = ref(false)
const notice = ref('')
const dirty = ref(false)
const strip = ref<HTMLElement | null>(null)
const activeKey = computed(() => workspaceKey(props.currentUrl))
const keyForStorage = computed(() => storageKeyForUser(props.userId))

// D5 stores only scroll offsets for the exact server-backed URL. Search, sort,
// filters and page numbers stay owned by existing ERP route query parameters.
const captureViewState = () => {
  if (typeof document === 'undefined') return
  if (workspaceViewState.capture(props.currentUrl, props.userId, document, window))
    workspaceViewState.save(props.userId, window.sessionStorage)
}
let restoreFrame: number | null = null
let mounted = false
const currentBrowserRoute = () => window.location.pathname + window.location.search + window.location.hash
const restoreViewState = () => {
  // A prior tab can complete an async reload after this Shell has unmounted.
  // It must NEVER apply that tab's scroll positions onto the newly active page.
  if (!mounted || currentBrowserRoute() !== props.currentUrl) return
  if (restoreFrame !== null) cancelAnimationFrame(restoreFrame)
  const route = props.currentUrl
  const snapshot = workspaceViewState.snapshot(route, props.userId)
  if (!snapshot) return
  let attempts = 0
  const attempt = () => {
    if (!mounted || props.currentUrl !== route || currentBrowserRoute() !== route) return
    const gridSelectors = Object.keys(snapshot.scrolls).filter(selector => selector.includes('.ag-'))
    const gridAttached = gridSelectors.every(selector => document.querySelector(selector))
    // OmsGrid deliberately restores account column layout asynchronously.
    // Do not apply horizontal offsets while it is still rendering hidden:
    // its own initial layout calculation would reset our scroll to zero.
    const gridLayoutPending = !!document.querySelector('.oms-grid-layout-loading')
    if ((!gridAttached || gridLayoutPending) && attempts++ < 90) {
      restoreFrame = requestAnimationFrame(attempt)
      return
    }
    workspaceViewState.restore(route, props.userId, document, window)
    restoreFrame = null
  }
  restoreFrame = requestAnimationFrame(attempt)
}

const save = () => {
  if (!ready.value) return
  try { window.sessionStorage.setItem(keyForStorage.value, JSON.stringify(tabs.value)) }
  catch { /* private mode: navigation still works without persistence */ }
}
const visitCurrent = () => {
  if (!ready.value) return
  const result = visitWorkspaceTab(tabs.value, props.currentUrl, props.currentTitle)
  tabs.value = result.tabs
  notice.value = result.evicted ? '已休眠并移除最久未访问的非固定标签' : ''
  save()
  void nextTick(() => strip.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({
    inline: 'nearest', block: 'nearest',
  }))
}
const recordCurrent = () => {
  if (!ready.value) return
  workspaceHotCache.capture(page as unknown as Page, props.userId)
  hotSize.value = workspaceHotCache.size
}
const reloadForUser = () => {
  workspaceViewState.hydrate(props.userId, window.sessionStorage)
  let saved: string | null = null
  try { saved = window.sessionStorage.getItem(keyForStorage.value) } catch { /* optional */ }
  tabs.value = restoreWorkspaceTabs(saved)
  ready.value = true
  visitCurrent()
  recordCurrent()
  void nextTick(restoreViewState)
}
watch(() => props.currentUrl, (next, prev) => {
  if (next !== prev) {
    dirty.value = false
    approvedTabNavigation = null
    visitCurrent()
    recordCurrent()
    void nextTick(restoreViewState)
  }
})
watch(() => props.currentTitle, () => visitCurrent())
watch(() => props.userId, () => {
  ready.value = false
  tabs.value = []
  dirty.value = false
  workspaceHotCache.clear()
  workspaceViewState.clear()
  hotStatus.value = 'idle'
  reloadForUser()
})
const allowLeave = (): boolean => {
  if (!dirty.value) return true
  return window.confirm('本页面有未保存的编辑，切换工作标签可能丢失内容。确定继续吗？')
}
let approvedTabNavigation: string | null = null
// A page opts in by publishing erp:workspace-dirty. Guard sidebar/Inertia
// GET navigation too, not only clicks inside the tab strip. Form POSTs still work.
const beforeRouteChange = router.on('before', event => {
  const next = event.detail.visit.url
  const target = next.pathname + next.search + next.hash
  // Inertia's silent authoritative reload uses the *same route*. The new
  // Grid has not restored its scroll yet; capturing that interim 0 would
  // destroy the outgoing snapshot. Hot navigation already captured explicitly.
  if (target === props.currentUrl || hotStatus.value === 'validating') return
  captureViewState()
  if (!dirty.value || event.detail.visit.method !== 'get') return
  if (approvedTabNavigation === target) {
    approvedTabNavigation = null
    return
  }
  if (!allowLeave()) event.preventDefault()
})
const open = (tab: WorkspaceTab) => {
  if (tab.key === activeKey.value && tab.href === props.currentUrl) return
  if (hotStatus.value !== 'idle') { notice.value = '正在核对订单最新数据，请先完成核对'; return }
  if (!allowLeave()) return
  captureViewState()
  approvedTabNavigation = tab.href
  const hot = workspaceHotCache.get(tab.href, props.userId, page.version)
  hotSize.value = workspaceHotCache.size
  if (hot) {
    // No server request for the initial frame. This is a response snapshot,
    // not a live cached AG Grid instance. Server validation still gates actions.
    // Any operational table must be validated even if it was visited moments
    // ago; only Dashboard may reuse a 5-second read-only snapshot unchanged.
    const needsRefresh = hot.component !== 'Dashboard/Index' || Date.now() - hot.capturedAt > WORKSPACE_HOT_FRESH_MS
    if (needsRefresh) hotStatus.value = 'validating'
    workspaceHotCache.markClientRestore(hot.key)
    router.push({
      url: hot.href, component: hot.component, props: hot.props,
      preserveScroll: true, preserveState: false,
      onSuccess: () => {
        if (!needsRefresh) return
        window.requestAnimationFrame(() => {
          router.reload({
            showProgress: false,
            onSuccess: () => { hotStatus.value = 'idle'; recordCurrent(); void nextTick(restoreViewState) },
            onError: () => { workspaceHotCache.evict(hot.key); hotStatus.value = 'failed' },
            onNetworkError: () => { hotStatus.value = 'failed' },
            onCancel: () => { hotStatus.value = 'failed' },
          })
        })
      },
      onError: () => { hotStatus.value = 'failed' },
    })
    return
  }
  router.visit(tab.href, { preserveState: false, preserveScroll: false })
}
const close = (tab: WorkspaceTab) => {
  if (tab.pinned) { notice.value = '请先取消固定，再关闭该标签'; return }
  if (hotStatus.value !== 'idle') return
  workspaceHotCache.evict(tab.key)
  workspaceViewState.evict(tab.key)
  workspaceViewState.save(props.userId, window.sessionStorage)
  hotSize.value = workspaceHotCache.size
  if (tab.key === activeKey.value && !allowLeave()) return
  const result = closeWorkspaceTab(tabs.value, tab.key, activeKey.value)
  if (result.nextHref && dirty.value) approvedTabNavigation = result.nextHref
  tabs.value = result.tabs
  save()
  if (result.nextHref) router.visit(result.nextHref, { preserveState: false, preserveScroll: false })
}
const togglePin = (tab: WorkspaceTab) => {
  const result = toggleWorkspacePin(tabs.value, tab.key)
  if (result.blocked) { notice.value = '最多只能固定 ' + WORKSPACE_PIN_LIMIT + ' 个标签'; return }
  tabs.value = result.tabs
  notice.value = tabs.value.find(x => x.key === tab.key)?.pinned ? '标签已固定' : '标签已取消固定'
  save()
}
const sleepOthers = () => {
  workspaceHotCache.sleepOthers(activeKey.value)
  hotSize.value = workspaceHotCache.size
  notice.value = '其他页面已休眠；固定入口仍保留'
}
const retryValidation = () => {
  if (hotStatus.value !== 'failed') return
  hotStatus.value = 'validating'
  router.reload({
    showProgress: false,
    onSuccess: () => { hotStatus.value = 'idle'; recordCurrent() },
    onError: () => { hotStatus.value = 'failed' },
    onNetworkError: () => { hotStatus.value = 'failed' },
  })
}
const onGridLayoutReady = () => {
  // AG Grid can rehydrate columns after the route is mounted. Restore the
  // scroll once its own layout is ready; ignore stale/unmounted tab events.
  if (!mounted) return
  void nextTick(restoreViewState)
}
const onDirtyEvent = (event: Event) => {
  const detail = (event as CustomEvent<{ dirty?: boolean }>).detail
  dirty.value = detail?.dirty === true
}
const onBeforeUnload = (event: BeforeUnloadEvent) => {
  // Browser refresh has no Inertia "before" event; save only viewport offsets.
  captureViewState()
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
let stopNavigationListener: (() => void) | undefined
onMounted(() => {
  mounted = true
  reloadForUser()
  window.addEventListener('erp:workspace-dirty', onDirtyEvent)
  window.addEventListener('erp:workspace-grid-layout-ready', onGridLayoutReady)
  const stop = router.on('navigate', event => {
    if (event.detail.page && event.detail.page.url === page.url) {
      recordCurrent()
      void nextTick(restoreViewState)
    }
  })
  stopNavigationListener = stop
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  mounted = false
  // Do NOT capture on component unmount: during client-side Inertia push the
  // URL prop can already refer to the incoming page while DOM is still old.
  // That would overwrite the target's saved scroll with zero. The "before"
  // hook and explicit hot-tab navigation capture the outgoing view instead.
  if (restoreFrame !== null) cancelAnimationFrame(restoreFrame)
  beforeRouteChange()
  stopNavigationListener?.()
  window.removeEventListener('erp:workspace-dirty', onDirtyEvent)
  window.removeEventListener('erp:workspace-grid-layout-ready', onGridLayoutReady)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<template>
  <nav v-if="ready && tabs.length" class="erp-workspace-tabs" aria-label="ERP 工作标签">
    <div ref="strip" class="erp-workspace-tab-strip">
      <div
        v-for="tab in tabs" :key="tab.key" class="erp-workspace-tab"
        :class="{ 'is-active': tab.key === activeKey }"
      >
        <button
          type="button" class="erp-workspace-tab-open"
          :title="tab.title" :aria-current="tab.key === activeKey ? 'page' : undefined"
          @click="open(tab)"
        >{{ tab.title }}</button>
        <button
          type="button" class="erp-workspace-tab-pin" :class="{ 'is-pinned': tab.pinned }"
          :aria-label="(tab.pinned ? '取消固定：' : '固定标签：') + tab.title"
          :title="tab.pinned ? '取消固定' : '固定（最多 3 个）'"
          @click="togglePin(tab)"
        >{{ tab.pinned ? '●' : '○' }}</button>
        <button
          type="button" class="erp-workspace-tab-close" :disabled="tab.pinned"
          :aria-label="'关闭工作标签：' + tab.title" :title="'关闭：' + tab.title"
          @click="close(tab)"
        >×</button>
      </div>
    </div>
    <button type="button" class="erp-workspace-sleep" title="释放其他页面缓存，保留固定标签和导航记录" @click="sleepOthers">休眠其他</button>
    <span class="erp-workspace-tab-count" :title="'工作标签 ' + tabs.length + '/' + WORKSPACE_TAB_LIMIT + '；热缓存 ' + hotSize + '/3'">{{ tabs.length }}/{{ WORKSPACE_TAB_LIMIT }}</span>
    <span v-if="hotStatus === 'validating'" class="erp-workspace-validation" role="status">正在校验最新数据…</span>
    <button v-if="hotStatus === 'failed'" class="erp-workspace-retry" type="button" @click="retryValidation">校验失败 · 重试</button>
    <span class="sr-only" role="status" aria-live="polite">{{ notice }}</span>
  </nav>
</template>

<style scoped>
.erp-workspace-tabs {
  display: flex;
  align-items: center;
  min-height: 38px;
  min-width: 0;
  padding: 0 12px;
  gap: 8px;
  background: var(--byl-surface, #fff);
  border-bottom: 1px solid var(--byl-border-subtle, #e6e9ef);
}
.erp-workspace-tab-strip {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 1;
  overflow-x: auto;
  scrollbar-width: thin;
  gap: 4px;
  padding: 4px 0;
}
.erp-workspace-tab {
  display: inline-flex;
  align-items: center;
  flex: 0 0 auto;
  max-width: 230px;
  border: 1px solid var(--byl-border-subtle, #e6e9ef);
  border-radius: 6px;
  background: var(--byl-canvas, #f7f8fa);
}
.erp-workspace-tab.is-active {
  background: var(--byl-surface, #fff);
  border-color: #28847b;
}
.erp-workspace-tab-open,
.erp-workspace-tab-pin,.erp-workspace-tab-close {
  background: transparent;
  color: var(--byl-text, #293744);
  border: 0;
  font: inherit;
  cursor: pointer;
  min-height: 27px;
}
.erp-workspace-tab-open {
  max-width: 185px;
  padding: 3px 4px 3px 10px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
}
.erp-workspace-tab-close {
  width: 27px;
  font-size: 16px;
  opacity: .67;
  line-height: 1;
}
.erp-workspace-tab-open:focus-visible,
.erp-workspace-tab-pin:focus-visible,.erp-workspace-tab-close:focus-visible { outline: 2px solid #28847b; outline-offset: 2px; }
.erp-workspace-tab-close:disabled { opacity: .3; cursor: not-allowed; }
.erp-workspace-tab-pin.is-pinned { color: #28847b; }
.erp-workspace-tab-pin:hover { color: #28847b; opacity: 1; }
.erp-workspace-sleep,.erp-workspace-retry { flex:0 0 auto; font:inherit; font-size:11px; border:1px solid #d8e0e7; background:transparent; border-radius:5px; padding:4px 6px; cursor:pointer; }
.erp-workspace-validation { font-size:11px; color:#576a79; white-space:nowrap; }
.erp-workspace-retry { color:#a33333; }
.erp-workspace-tab-close:hover { opacity: 1; color: #a33333; }
.erp-workspace-tab-count { flex: 0 0 auto; color: var(--byl-text-muted, #687987); font-size: 11px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
@media (max-width: 800px) {
  .erp-workspace-tabs { min-height: 35px; padding: 0 6px; }
  .erp-workspace-tab-open { max-width: 145px; }
}
</style>
