import { defineConfig } from 'vitest/config'

export default defineConfig({
    test: {
        // Die Bausteine rendern React in den DOM; die Tests fragen danach, was
        // dasteht. Ohne jsdom gibt es kein document, in das gerendert werden
        // koennte.
        environment: 'jsdom',
    },
})
