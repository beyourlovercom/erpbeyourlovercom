import { initPreferences, updatePreferences } from '@vben/preferences';
import { unmountGlobalLoading } from '@vben/utils';

import { overridesPreferences, preferencesExtension } from './preferences';

/** Bootstrap ERP2 while preserving per-user theme switches after first migration. */
async function initApplication() {
  const env = import.meta.env.PROD ? 'prod' : 'dev';
  const appVersion = import.meta.env.VITE_APP_VERSION;
  const namespace = `${import.meta.env.VITE_APP_NAMESPACE}-${appVersion}-${env}`;

  await initPreferences({
    extension: preferencesExtension,
    namespace,
    overrides: overridesPreferences,
  });

  // Vben ships with a dark default, and cached preferences outrank overrides.
  // Apply Owner-approved Light default ONCE for existing ERP2 Dev browsers.
  // Thereafter user-selected dark/light remains their own persistent preference.
  const key = `${namespace}-erp2-light-default-v1`;
  try {
    if (localStorage.getItem(key) !== '1') {
      updatePreferences({ theme: { mode: 'light' } });
      localStorage.setItem(key, '1');
    }
  } catch {
    updatePreferences({ theme: { mode: 'light' } });
  }

  const { bootstrap } = await import('./bootstrap');
  await bootstrap(namespace);
  unmountGlobalLoading();
}

void initApplication();
