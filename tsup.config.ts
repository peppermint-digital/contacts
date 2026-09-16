import { defineConfig } from 'tsup'

export default defineConfig({
    entry: ['resources/js/src/index.ts'],
    format: ['esm'],
    dts: true,
    clean: true,
    outDir: 'dist',
})
