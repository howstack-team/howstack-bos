# 在线安装器开发规范

本文件继承根目录与 `frontend/AGENTS.md`，适用于 `frontend/install/`。

- 使用 React 19、TypeScript、Vite 与 Arco Design React 原生组件；不引入 Ant Design 或 ProComponents。
- 按锁定版本的官方 API 使用组件，入口加载官方 React 19 adapter 和本地 Arco CSS，使用中文 `ConfigProvider`。
- 保留品牌色 `#165DFF` 与矩形圆角 `2px`，通过官方 CSS 变量和公开 Props 配置；不覆盖组件私有 DOM 或 `.arco-*` 内部样式。
- 保留六步安装流程与 `/api/install` 契约。跨步骤完整配置通过 `Form` 的公开 `getFields()` 获取；连接配置改变后使旧测试结果失效。
- 软件协议必须由安装人主动确认；密码、数据库凭证与安装令牌仅在页面内存和受控请求中使用，不写入持久存储或日志。
- UI 验收使用独立测试安装环境或隔离模拟接口；不清除现有系统安装标记，不向现有数据库执行测试安装。
- JavaScript 和 CSS 由 `pnpm build` 生成至 `backend/public/install-assets/app/`，不手改构建产物，不使用运行时 CDN。
- 修改后运行 `pnpm lint`、`pnpm build`，核对桌面与 390px 展示、表单校验、连接测试、执行进度和成功/失败结果；明确区分模拟交互验收与真实安装验收。
