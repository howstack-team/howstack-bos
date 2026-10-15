import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { createRequire } from 'node:module'
import { dirname } from 'node:path'
import { readFileSync } from 'node:fs'

const require = createRequire(import.meta.url)
const mobilePackage = dirname(require.resolve('@arco-design/mobile-react/package.json'))
const mobileUtils = dirname(require.resolve('@arco-design/mobile-utils/package.json', { paths: [mobilePackage] }))

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const mobile = mode === 'mobile' || mode === 'template-mobile'
  const template = mode === 'template' || mode === 'template-mobile'
  return {
    base: template ? './' : '/console/',
    publicDir: 'public',
    // Mobile 的 Less 使用官方 Webpack 风格的 ~ 导入，Vite 显式解析该公共包。
    resolve: { alias: { '~@arco-design/mobile-utils': mobileUtils } },
    css: { preprocessorOptions: { less: { javascriptEnabled: true } } },
    plugins: [react(), {
      name: 'console-licenses',
      generateBundle() {
        const packages = ['qrcode', 'dijkstrajs', ...(mobile ? ['@arco-design/mobile-react', '@arco-design/mobile-utils', 'react-transition-group'] : [])]
        const notices = packages.map(name => {
          const directory = dirname(require.resolve(`${name}/package.json`, name === 'dijkstrajs' ? { paths: [dirname(require.resolve('qrcode/package.json'))] } : undefined))
          const manifest = JSON.parse(readFileSync(`${directory}/package.json`, 'utf8'))
          return { name, version: manifest.version, notice: readFileSync(`${directory}/${name === 'dijkstrajs' ? 'LICENSE.md' : 'LICENSE'}`, 'utf8') }
        })
        this.emitFile({ type: 'asset', fileName: 'licenses.json', source: JSON.stringify({ dependencies: notices }, null, 2) })
      },
    }, {
      name: 'console-mobile-entry',
      transformIndexHtml: { order: 'pre', handler(html) {
        return mobile ? html.replace('/src/main.tsx', '/src/mobile/main.tsx').replace('initial-scale=1.0', 'initial-scale=1.0, viewport-fit=cover') : html
      } },
    }, {
      name: 'redirect-legacy-console-path',
      configureServer(server) {
        server.middlewares.use((request, response, next) => {
          const url = request.url
          if (!url || !/^\/app\/console(?=\/|\?|$)/.test(url)) return next()
          response.writeHead(308, { Location: url.replace(/^\/app\/console/, '/console') })
          response.end()
        })
      },
    }],
    server: {
      host: '0.0.0.0',
      proxy: {
        '/api': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false },
        '/products': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false },
        '/theme-assets': { target: process.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989', changeOrigin: false },
      },
    },
    preview: {
      host: '0.0.0.0',
    },
    build: {
      outDir: template ? `../../backend/themes/default/console/${mobile ? 'mobile' : 'pc'}` : '../../backend/public/console',
      emptyOutDir: true,
      copyPublicDir: !template,
    },
  }
})
