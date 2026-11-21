import type { TabDefinition } from '@vben/types';

import { useTabbarStore } from '@vben/stores';
import { message } from 'ant-design-vue';

export const DEV_PIN_LIMIT = 3;
export const DEV_TAB_LIMIT = 30;

// Workspaces are cheap navigation bookmarks, not mounted AG Grid instances.
// A pinned tab survives both automatic LRU removal and accidental close.
export function canPinDevTab(
  tabs: Array<{ path: string; meta: { affixTab?: boolean } }>, targetPath: string,
) {
  const current = tabs.find((tab) => tab.path === targetPath);
  if (current?.meta.affixTab) return true;
  return tabs.filter((tab) => !!tab.meta.affixTab).length < DEV_PIN_LIMIT;
}
type LightTab = { key?: string; path: string; meta: { affixTab?: boolean } };
const tabKey = (tab: LightTab) => tab.key ?? tab.path;
export function pickDevLruVictim(tabs: LightTab[], lastSeen: Map<string, number>) {
  const ordinary = tabs.filter((tab) => !tab.meta.affixTab);
  let oldest: LightTab | undefined;
  let oldestAt = Infinity;
  for (const tab of ordinary) {
    const seen = lastSeen.get(tabKey(tab)) ?? 0;
    if (!oldest || seen < oldestAt) { oldest = tab; oldestAt = seen; }
  }
  return oldest ?? null;
}

let installed = false;
export function installDevPinLimit() {
  if (installed || import.meta.env.VITE_ERP_CURRENT_DEV_ENABLED !== '1') return;
  installed = true;
  const tabbar = useTabbarStore();
  const originalPin = tabbar.pinTab.bind(tabbar);
  tabbar.pinTab = async (tab: TabDefinition) => {
    if (!canPinDevTab(tabbar.tabs, tab.path)) {
      message.warning('最多固定 3 个工作标签，请先取消其他标签的固定');
      return;
    }
    await originalPin(tab);
  };
  const originalAdd = tabbar.addTab.bind(tabbar);
  const lastSeen = new Map<string, number>();
  tabbar.addTab = (tab: TabDefinition) => {
    const key = tab.key ?? tab.fullPath ?? tab.path;
    const hasTab = tabbar.tabs.some((item) => tabKey(item) === key);
    if (!hasTab && tabbar.tabs.length >= DEV_TAB_LIMIT) {
      const victim = pickDevLruVictim(tabbar.tabs, lastSeen);
      if (victim) {
        const index = tabbar.tabs.findIndex((item) => tabKey(item) === tabKey(victim));
        if (index >= 0) tabbar.tabs.splice(index, 1);
        lastSeen.delete(tabKey(victim));
      }
    }
    const added = originalAdd(tab);
    lastSeen.set(tabKey(added), Date.now());
    return added;
  };
}