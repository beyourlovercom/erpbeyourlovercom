import { createInertiaApp } from '@inertiajs/vue3'
import { createApp, h } from 'vue'
import type { DefineComponent } from 'vue'
import { registerInertiaHttpExceptionHandler } from './lib/inertiaErrorInterception'

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`] as { default: DefineComponent }
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el)
    },
    progress: {
        color: '#60a5fa',
    },
})

// Intercept Inertia httpException events (5xx during navigation).
// Prevents the default white Inertia invalid-response modal on 5xx;
// keeps the current page mounted and shows a non-blocking global error.
registerInertiaHttpExceptionHandler()
