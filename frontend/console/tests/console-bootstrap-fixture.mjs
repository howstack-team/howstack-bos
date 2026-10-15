/** 认证界面夹具使用公开启动配置；不依赖真实主题安装、数据库或会员会话。 */
export const consoleBootstrap = {
  contract: '1', basePath: '/console', apiBase: '/api/console/v1', assetBase: '/console/',
  theme: { id: 'default', version: '1.0.0', type: 'pc' },
  settings: {}, site: { name: '皓量云擎', logo: '/console/logo.png', favicon: '', copyright: '© 2026 测试平台', icp_record: '', public_security_record: '' }, capabilities: [],
}
