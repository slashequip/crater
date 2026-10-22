import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/scripts/main.js'], refresh: true }),
    vue(),
  ],
  publicDir: 'resources/static',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources', import.meta.url)),
      'vue-i18n': 'vue-i18n/dist/vue-i18n.cjs.js',
    },
  },
  build: { manifest: 'manifest.json' },
})
