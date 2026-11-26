import { createInertiaApp, router } from '@inertiajs/vue3'
import { createApp, h } from 'vue'
import type { DefineComponent } from 'vue'
import { installAssetRecovery } from './lib/assetRecovery'

import { handleHttpFeedback } from './lib/httpFeedback'

installAssetRecovery()
router.on('httpException', event => handleHttpFeedback(event.detail.response))

// Load only the requested ERP page. Importing all pages eagerly made every
// first navigation pay for unrelated order, warehouse, accounting and admin UI.
const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue')

createInertiaApp({
    resolve: (name) => {
        const load = pages[`./Pages/${name}.vue`]
        if (!load) throw new Error('ERP page component not found: ' + name)
        return load().then((module) => module.default)
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el)
    },
})
