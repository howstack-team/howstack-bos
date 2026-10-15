# 后台布局组件

- `index.tsx`：按 `classic` / `double-column` 选择布局。
- `ClassicLayout.tsx`：默认经典单列导航，宽度 220px，折叠后 48px。
- `DoubleColumnLayout.tsx`：一级分类列 88px，二级菜单默认 168px，折叠后保留一级列。
- `LayoutFrame.tsx`：共用顶部、侧栏容器、面包屑、主内容和底部。
- `NavigationItems.tsx` / `NavigationIcon.tsx`：共用树形菜单与一级图标；只接收经过权限过滤的路由，不自行获取业务数据。
- `LayoutSelector.tsx` / `preferences.ts`：页面配置入口与当前浏览器的布局偏好，未知值回退经典布局。
- `types.ts` / `styles/`：公开属性与各布局的样式。

`src/layout.tsx` 保留认证、权限过滤、路由加载、导航状态、更新提醒和水印编排。切换布局不改变 URL、HTTP 接口或页面权限，不重新请求官网更新信息。

默认配置位于 `src/settings.json`。`layout` 默认为 `classic`，`classicMenuWidth` 管理经典侧栏宽度，`menuWidth` 管理双列的二级菜单宽度。布局偏好保存为本地 `admin-layout-style`，仅影响展示。

移动端共用 768px 断点、遮罩和 Escape 关闭行为，保留可点击遮罩区域。普通表格仍由页面负责纵向滚动。
