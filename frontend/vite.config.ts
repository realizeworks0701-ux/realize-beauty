import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// バックエンドの接続先。Sail(ポート80)が既定。`composer dev`（php artisan serve）
// を使う場合は VITE_API_PROXY_TARGET=http://localhost:8000 を指定する。
const apiTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
      },
      '/storage': {
        target: apiTarget,
        changeOrigin: true,
      },
    },
  },
})
