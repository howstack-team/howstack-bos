# 运营后台前端补充规范

本文件适用于 `frontend/admin/`，继承仓库根目录及 `frontend/AGENTS.md` 的通用要求。与 Ant Design 专项规则冲突时，以本文件的 Arco Design React 规则为准。

- 使用 React 18、TypeScript、Vite 8、Arco Design React 和 Arco Design Pro 主题。只在运营后台使用此技术栈；会员控制台与安装器保持各自独立构建。
- 布局和登录页以 Arco Design Pro Vite 官方模板为基础，保留默认 `Navbar`、`Sider`、`Menu`、`Breadcrumb`、`Content`、`Footer` 结构和样式；不要另行设计后台框架。树形路由、菜单与 `requiredPermissions` 集中在 `src/routes.ts`，由 Webman 权限码转换为 Arco Pro 的 `resource/actions` 映射。
- 后台 API 继续遵循 Webman 的 `/api/admin/v1` 契约、统一响应外层结构与 HttpOnly Cookie；前端菜单过滤不是服务端鉴权替代。
- 每新增一个页面，必须同步增加对应的后端权限码、Arco Pro 路由 `requiredPermissions` 和角色权限分配入口；页面内的新增、编辑、删除等操作还需各自的权限控制及服务端校验。验收至少覆盖允许访问、无权限拒绝，以及只有查看权限时不能修改。
- 业务页面使用 Arco 的 `Table`、`Form`、`Modal`、`Drawer` 等组件，不引入 Ant Design 或 ProComponents。生产构建写入 `backend/public/app/admin/`。
- 运营后台所有图表统一使用 VChart，包括趋势、分布、占比和关系等数据可视化；不要使用 Ant Design Charts、ECharts、Chart.js、Recharts，也不要手写 Canvas、SVG 或用静态图片模拟图表。单个数值或状态展示按 Arco 组件和页面排版处理。本规则仅适用于运营后台，会员控制台与安装器仍遵循各自的图表规范。
- 表格操作列统一使用 Arco `Space size="mini"` 排列 `Link` 操作，不用文字按钮。按权限过滤后的实际操作项不超过 3 个时全部直接显示；达到 4 个时直接显示前 3 个，从第 4 个起放入由 `<IconMore />` 图标 `Link` 触发的下拉菜单。需要折叠时优先将删除、停用、重置凭据、分配权限等敏感操作排在“更多”中；不足 4 项时不单独为敏感操作显示“更多”，执行前仍保留必要的二次确认。操作列需保持单行，并为图标入口提供可访问名称。
- 表格状态列若只有“启用 / 禁用”两种状态，统一调用 `src/components/EnabledStatus.tsx`，不用 `Tag`。组件以 Arco 的 `IconCheckCircleFill` 配成功色展示启用、`IconMinusCircleFill` 配警告色展示禁用，并附文字。遵循当前 Webman 契约：`1` 为启用、`0` 为禁用；不能照搬其他示例中的 `2`，也不能将未知值直接当作禁用。其他业务状态不套用此组件。
- 表格中名称、描述、地址等内容可能超过列宽时，为对应列设置合理宽度及 Arco `Table` 列的 `ellipsis: true`，保持单元格省略显示；需要阅读完整内容时提供悬停提示或详情入口，不直接截断原始数据。短内容列和操作列不机械添加省略。
- 表格数据单元格为 `null`、`undefined` 或空白字符串时统一显示半角 `-`；`0` 和 `false` 是有效值，不能当作空值。具有明确业务含义的文案（如“待分配”）保留原样。操作列没有可执行操作时留空，不显示占位符。
- 普通列表表格默认每页展示 10 条，可切换为 20、50 或 100 条；服务端分页切换条数时重置到第 1 页并按新条数重新请求。部门树、权限树和不足 10 条的固定展示表格保持完整展示，避免拆散层级或添加无意义分页。
- Modal 表单中的状态字段若只有“启用 / 禁用”，使用 Arco `Switch` 并显示对应文字；通过表单值转换保持接口的 `1`（启用）和 `0`（禁用）契约，编辑回填与提交均不得变成布尔值。
- 保留当前品牌蓝 `#165DFF` 与矩形 `2px` 圆角要求；业务自定义样式避免阴影、渐变、拟态玻璃和悬浮抬升。官方模板的原生布局样式保持默认，不覆盖组件私有 DOM。
- 至少验证桌面端与 390px 移动端，覆盖菜单、表格横向滚动、弹窗和抽屉。
- 每次改动运行 `pnpm lint` 与 `pnpm build`；接口流程还需验证真实 Webman 服务、数据库和权限状态。
