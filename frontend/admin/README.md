# 运营后台前端

本应用以 [Arco Design Pro React Vite 模板](https://github.com/arco-design/arco-design-pro/tree/main/arco-design-pro-vite)为基础。源码按官方 `gen:vite` 脚本的合并规则，从 `arco-design-pro-vite` 与 `arco-design-pro-next` 组装；保留官方布局、登录页、设置面板、路由和权限工具，并接入皓量云擎的后台页面与 Webman API。上游模板采用较早的 React 17、Vite 2，本项目将核心依赖更新为 React 18、Vite 8、TypeScript 6、Arco Design React 2.66；路由保留官方 React Router 5 API 并更新到 5.3.4。上游模板许可见 [ARCO_DESIGN_PRO_LICENSE](./ARCO_DESIGN_PRO_LICENSE)。

## 开发与构建

```bash
pnpm install
pnpm dev
pnpm lint
pnpm build
```

开发服务将 `/api` 代理至 `VITE_BACKEND_TARGET`，默认 `http://127.0.0.1:8989`。生产构建写入 `backend/public/app/admin/`，由 Webman 的动态后台路径提供入口。会员控制台与安装器仍独立构建。

## 目录与接线

- `src/layout.tsx`、`src/components/NavBar/`、`src/components/Footer/`、`src/components/Settings/`、`src/style/`：Arco Pro 默认框架。
- `src/routes.ts`：使用官方树形路由和 `requiredPermissions` 控制菜单、页面与默认路由。Webman 权限码转换为 Arco 的 `resource/actions` 映射。
- `src/components/PermissionWrapper/`：官方元素权限组件，读取当前 Webman 登录态。
- `src/auth/`、`src/api/`、`src/access/`：HttpOnly Cookie 登录、统一请求和管理员、部门、角色 API。
- `src/pages/`：官方登录页及后台业务页面；工作台经营指标仍为演示数据，系统设置、审计日志和业务管理页面通过 API 接入 Webman。

前端权限负责隐藏无权菜单和页面；服务端仍需对每次请求执行授权。保留 Arco Pro 的布局组件，内容区取消固定最小宽度；窄屏菜单使用遮罩展开，返回桌面时恢复桌面侧栏状态。表格、表单和详情按视口宽度自适应。
