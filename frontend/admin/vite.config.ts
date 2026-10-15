import { fileURLToPath } from 'node:url';
import { createReadStream, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname } from 'node:path';
const require = createRequire(import.meta.url);
import { realpath, stat } from 'node:fs/promises';
import { sep, resolve } from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
  base: '/admin/',
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  plugins: [react(), {
    name: 'admin-mfa-licenses',
    generateBundle() {
      const dependencies = ['qrcode', 'dijkstrajs'].map(name => {
        const directory = dirname(require.resolve(`${name}/package.json`, name === 'dijkstrajs' ? { paths: [dirname(require.resolve('qrcode/package.json'))] } : undefined));
        const manifest = JSON.parse(readFileSync(`${directory}/package.json`, 'utf8'));
        return { name, version: manifest.version, notice: readFileSync(`${directory}/${name === 'dijkstrajs' ? 'LICENSE.md' : 'LICENSE'}`, 'utf8') };
      });
      this.emitFile({ type: 'asset', fileName: 'licenses-mfa.json', source: JSON.stringify({ dependencies }, null, 2) });
    },
  }, viteStaticCopy({ targets: [
    // 与 @js-preview/pdf 内置 PDF.js 4.7.76 匹配；只交付字符映射与字体数据。
    { src: 'node_modules/pdfjs-dist/cmaps/*', dest: 'document-preview/pdf/cmaps' },
    { src: 'node_modules/pdfjs-dist/standard_fonts/*', dest: 'document-preview/pdf/standard_fonts' },
  ] }), {
    name: 'redirect-legacy-admin-path',
    configureServer(server) {
      // 本地文档的大体积预构建资源直接流式提供，避免开发代理截断上游静态响应。
      const docsAssets = fileURLToPath(new URL('../../backend/public/api-docs/assets/', import.meta.url));
      server.middlewares.use(async (request, response, next) => {
        if (!request.url?.startsWith('/api-docs/assets/')) return next();
        if (request.method !== 'GET' && request.method !== 'HEAD') {
          response.writeHead(405); response.end(); return;
        }
        try {
          const pathname = decodeURIComponent(request.url.split('?')[0].slice('/api-docs/assets/'.length));
          const file = await realpath(resolve(docsAssets, pathname));
          const metadata = await stat(file);
          if (!file.startsWith(docsAssets.replace(/\/$/, '') + sep) || !metadata.isFile()) throw new Error('非公开文档资源');
          response.writeHead(200, { 'Content-Length': metadata.size,
            'Content-Type': file.endsWith('.css') ? 'text/css; charset=utf-8' : file.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/plain; charset=utf-8',
            'X-Content-Type-Options': 'nosniff' });
          if (request.method === 'HEAD') response.end();
          else createReadStream(file).on('error', () => response.destroy()).pipe(response);
        } catch {
          response.writeHead(404); response.end();
        }
      });
      server.middlewares.use((request, response, next) => {
        const url = request.url;
        if (!url || !/^\/app\/admin(?=\/|\?|$)/.test(url)) return next();
        response.writeHead(308, { Location: url.replace(/^\/app\/admin/, '/admin') });
        response.end();
      });
    },
  }],
  css: { preprocessorOptions: { less: { javascriptEnabled: true } } },
  server: {
    host: '0.0.0.0',
    proxy: {
      '/theme-assets': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false },
      '/api-docs': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false,
        configure(proxy) {
          proxy.on('proxyRes', incoming => {
            // 开发环境后台固定为 /admin/，生产环境仍由后端跳往客户设置的入口。
            if (incoming.statusCode === 302 && incoming.headers.location?.endsWith('/login')) incoming.headers.location = '/admin/login';
          });
        } },
      '/api': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false },
    },
  },
  preview: { host: '0.0.0.0' },
  build: { outDir: '../../backend/public/admin', emptyOutDir: true },
});
