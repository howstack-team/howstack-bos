import assert from 'node:assert/strict'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright/test')
const browser = await chromium.launch({ headless: true })
try {
  for (const kind of ['admin', 'console']) {
    const admin = kind === 'admin', origin = admin ? 'http://127.0.0.1:5175' : 'http://127.0.0.1:5176'
    const context = await browser.newContext(), page = await context.newPage()
    page.on('pageerror', error => console.error(kind, error.message))
    page.on('requestfailed', request => console.error(kind, request.url(), request.failure()?.errorText))
    const prefix = admin ? '/admin' : '/console'
    const transformed = await (await fetch(`${origin}${prefix}/src/auth/AuthProvider.tsx`)).text()
    const version = transformed.match(/react\.js\?v=([a-zA-Z0-9]+)/)?.[1]
    const query = version ? `?v=${version}` : ''
    let reads = 0, actor = 1
    const source = `<!doctype html><html><body><div id="root"></div><script type="module">
      import RefreshRuntime from '${prefix}/@react-refresh';
      RefreshRuntime.injectIntoGlobalHook(window); window.$RefreshReg$=()=>{}; window.$RefreshSig$=()=>type=>type; window.__vite_plugin_react_preamble_installed__=true;
      const React=(await import('${prefix}/node_modules/.vite/deps/react.js${query}')).default;
      const ReactDOM=await import('${prefix}/node_modules/.vite/deps/react-dom_client.js${query}');
      const authModule=await import('${prefix}/src/auth/AuthProvider.tsx');
      const authContext=await import('${prefix}/src/auth/AuthContext.ts');
      const AuthProvider=${admin ? 'authModule.AuthProvider' : 'authModule.default'};
      function Probe(){const auth=${admin ? 'authContext.useAdminAuth()' : 'authContext.useMemberAuth()'};
        const [draft,setDraft]=React.useState(''); const member=${admin ? 'auth.user' : 'auth.member'};
        if(${admin ? "auth.status !== 'authenticated'" : 'auth.loading || !member'}) return React.createElement('p',null,'等待会话');
        return React.createElement('main',null,React.createElement('span',{id:'actor'},String(member.id)),
          React.createElement('input',{id:'draft',value:draft,onChange:e=>setDraft(e.target.value)}));}
      let app=React.createElement(AuthProvider,null,React.createElement(Probe));
      ${admin ? '' : `const Query=await import('${prefix}/node_modules/.vite/deps/@tanstack_react-query.js${query}');
        const {createMemberQueryClient}=await import('${prefix}/src/api/queryClient.ts');
        app=React.createElement(Query.QueryClientProvider,{client:createMemberQueryClient()},app);`}
      (ReactDOM.createRoot??ReactDOM.default.createRoot)(document.getElementById('root')).render(app);
    </script></body></html>`
    await context.route(`${origin}${prefix}/session-focus-fixture`, route => route.fulfill({ contentType: 'text/html', body: source }))
    await context.route(`**/api/${kind}/v1/**`, route => {
      reads++
      const user = admin ? { id: actor, username: `fixture-${actor}`, nickname: '测试管理员' } : { id: `72846193${actor}`, mobile: '13800138001', email: null, nickname: '测试会员' }
      return route.fulfill({ json: { code: 0, message: 'ok', data: admin ? { user, permissions: [] } : { user }, request_id: 'focus-regression' } })
    })
    await page.goto(`${origin}${prefix}/session-focus-fixture`)
    await expect(page.locator('#draft')).toBeVisible()
    const before = reads
    await page.locator('#draft').fill('未保存的配置草稿')
    await expect(page.locator('#draft')).toHaveValue('未保存的配置草稿')
    assert.equal(reads, before, '字段聚焦不能请求认证或重挂载表单')
    await page.evaluate(() => window.dispatchEvent(new Event('focus')))
    await expect.poll(() => reads).toBeGreaterThan(before)
    await expect(page.locator('#draft')).toBeVisible()
    await expect(page.locator('#draft')).toHaveValue('未保存的配置草稿')
    actor = 2
    await page.evaluate(kind => window.dispatchEvent(new Event(kind === 'admin' ? 'admin:access-changed' : 'member:session-changed')), kind)
    await expect(page.locator('#actor')).toHaveText(admin ? '2' : '728461932')
    await expect(page.locator('#draft')).toHaveValue('')
    await context.close()
    console.log(`通过：${kind} 内部焦点不触发认证，同身份恢复保留草稿，切换账号清除草稿`)
  }
} finally { await browser.close() }
