import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  base: '/app/console/',
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    proxy: { '/api': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false } },
  },
  preview: {
    host: '0.0.0.0',
  },
  build: {
    outDir: '../../backend/public/app/console',
    emptyOutDir: true,
  },
})
