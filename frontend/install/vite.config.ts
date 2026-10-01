import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  base: '/install-assets/app/',
  plugins: [react()],
  build: {
    outDir: '../../backend/public/install-assets/app',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    proxy: {
      '/api': 'http://127.0.0.1:8989',
    },
  },
  preview: {
    host: '0.0.0.0',
  },
})
