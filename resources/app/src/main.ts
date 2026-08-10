import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { useDocumentStore } from './stores/document'

const pinia = createPinia()
createApp(App).use(pinia).mount('#magna-builder')

// The store, reachable from the console and the E2E suite. An admin-only
// page inspecting its own state exposes nothing new — the same data is one
// Vue devtools click away — and it turns "why did that gesture do nothing"
// from archaeology into a one-line probe.
declare global {
    interface Window {
        __magnaBuilder?: { store: ReturnType<typeof useDocumentStore> }
    }
}
window.__magnaBuilder = { store: useDocumentStore(pinia) }
