import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

const PRODUCTION_API_URL = 'https://clothing-store-production-9717.up.railway.app/api'
const LOCAL_API_URL = 'http://localhost:8000/api'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const apiUrl = mode === 'production' ? PRODUCTION_API_URL : LOCAL_API_URL

  return {
    plugins: [vue()],
    define: {
      'import.meta.env.VITE_API_URL': JSON.stringify(apiUrl),
    },
    resolve: {
      alias: {
        vue: 'vue/dist/vue.esm-bundler.js',
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
  }
})
