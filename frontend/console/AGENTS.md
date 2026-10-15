# 会员控制台补充规范

本文件继承仓库根目录及 `frontend/AGENTS.md`，适用于 `frontend/console/`。

- 使用 React 19、TypeScript、Vite；PC 使用 Arco Design React、官方 React 19 adapter、本地 CSS 和中文语言包，独立移动端使用 Arco Design Mobile React 并通过 ContextProvider 传入 createRoot；不使用 Ant Design 或 ProComponents。
- 样式以运营后台当前实现为基线：使用相同 Arco Pro 主题、品牌资源、居中登录面板、60px 顶栏、220px 侧栏（收起为 48px）、业务内容间距、卡片边框与 40px 页脚。优先遵循前端通用规范，不复制后台中违反通用规范的私有 DOM 样式或历史图表代码；移动导航使用原生 Arco Drawer 保留焦点与关闭行为。
- 两端共同使用的依赖声明及锁定版本保持一致，包括 Arco、Pro 主题、Axios、React Router、Vite、TypeScript、Oxlint 和 Node 类型；更新时先对照后台版本，不单独升级会员控制台的共同依赖。React、React DOM 及其类型按本目录 React 19 规范保留，React Query 用于会员服务端状态；不机械添加后台独有依赖。
- 页面入口为 `/console`，登录入口为 `/console/login`，资源与 Vite base 均使用 `/console/`；构建输出为 `backend/public/console/`。
- 所有 UI 开关使用 `Switch type="round"`，应用根部通过 `ConfigProvider` 设置相同的默认值。
- API 使用 `/api/console/v1`，会话使用后端签发的 HttpOnly Cookie；登录后恢复原访问路径，退出或会话失效时同时更新界面与 URL。
- 会员只能访问自己的资料、余额和业务对象；界面控制不能替代服务端对象归属校验。
- 通过 React Query 管理服务端状态，退出后清理本人的业务查询缓存；表单校验使用 Arco `Form` 原生字段提示，不将校验异常重复展示为全局业务错误。
- 每次修改运行 `pnpm lint`、`pnpm build`，并按实际风险验证登录、退出、错误反馈、桌面及 390px 布局；模拟接口验证不得宣称为真实财务或支付验收。

- 会员注册入口为 `/console/register`；手机号/邮箱方式及验证码要求由公开注册配置确定，不在前端绕过验证。会员公开 ID 为九位纯数字字符串，兼容展示历史八位 ID，不能将内部主键回退为对外编号；个人资料兼容仅绑定邮箱或仅绑定手机号。密码和验证码仅用于提交，不持久化。

- 官方会员主题构建使用 `build:template`，同时输出 `backend/themes/default/console/pc` 和 `console/mobile`；PC模板可以自适应布局。独立mobile模板为可选能力，宿主关闭移动端开关时始终加载PC，显示方式切换只在公开capabilities包含theme.presentation.split时展示。

- 移动端入口及 UI 位于 `src/mobile/`，复用 `src/api/`、`src/auth/` 和公开品牌上下文；不得导入 PC 页面、PC Arco 样式或登录大图。Mobile Form 保留字段校验，接口反馈使用 Mobile Toast。执行移动端构建和 `tests/mobile-browser.mjs`，独立移动模板应验证 320px、390px 和安全区域。
