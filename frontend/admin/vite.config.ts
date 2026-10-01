import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/app/admin/',
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  plugins: [react()],
  css: { preprocessorOptions: { less: { javascriptEnabled: true } } },
  server: {
    host: '0.0.0.0',
    proxy: { '/api': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false } },
  },
  preview: { host: '0.0.0.0' },
  build: { outDir: '../../backend/public/app/admin', emptyOutDir: true },
});
