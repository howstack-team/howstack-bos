const documentationSources = [{"slug":"admin","title":"运营后台 API","url":"/api-docs/specs/admin.json","default":true},{"slug":"console","title":"会员控制台 API","url":"/api-docs/specs/console.json","default":false},{"slug":"public","title":"公开与安装 API","url":"/api-docs/specs/public.json","default":false}];
const notice = document.getElementById('documentation-error');
const download = document.getElementById('documentation-download');

function updateDownload(slug) {
  const source = documentationSources.find(source => source.slug === slug);
  if (!source) return;
  download.href = source.url;
  download.download = `${source.slug}.openapi.json`;
  download.setAttribute('aria-label', `下载${source.title}的 OpenAPI JSON`);
}

updateDownload(location.hash.slice(1).split('/')[0] || documentationSources[0]?.slug);

async function localFetch(input, init) {
  const address = new URL(input instanceof Request ? input.url : input, location.href);
  if (address.origin !== location.origin) throw new Error('文档仅允许访问当前系统的接口');
  const response = await fetch(input, { ...init, credentials: 'same-origin' });
  if (address.pathname.startsWith('/api-docs/specs/') && !response.ok) {
    notice.hidden = false;
    notice.textContent = response.status === 401 ? '登录状态已失效，请返回后台重新登录后刷新文档。' :
      response.status === 403 ? '当前账号没有 API 文档访问权限，请联系管理员。' : '文档暂时无法加载，请稍后重试。';
    throw new Error(`文档加载失败 (${response.status})`);
  }
  return response;
}

try {
  Scalar.createApiReference('#api-documentation', {
    sources: documentationSources.map(source => ({ ...source, agent: { disabled: true } })),
    localization: { locale: 'zh-CN' },
    servers: [{ url: location.origin }],
    // 浏览器自动发送 HttpOnly Cookie；不要求用户复制令牌到调试器。
    authentication: { preferredSecurityScheme: [] },
    theme: 'default',
    layout: 'modern',
    documentDownloadType: 'none',
    // 常用语言直接显示，其余仍可从“更多”选择。
    featuredClients: ['shell/curl', 'php/curl', 'js/fetch'],
    defaultHttpClient: { targetKey: 'shell', clientKey: 'curl' },
    onLoaded: updateDownload,
    persistAuth: false,
    telemetry: false,
    withDefaultFonts: false,
    agent: { disabled: true },
    mcp: { disabled: true },
    showDeveloperTools: 'never',
    hideClientButton: true,
    hideDarkModeToggle: false,
    customFetch: localFetch,
    customCss: ':root { --scalar-color-accent: #165dff; --scalar-font: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", sans-serif; --scalar-font-code: ui-monospace, SFMono-Regular, monospace; }',
  });
} catch {
  notice.hidden = false;
  notice.textContent = '文档未能初始化，请刷新页面或联系管理员。';
}
