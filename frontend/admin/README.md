# 运营后台前端

本应用以 [Arco Design Pro React Vite 模板](https://github.com/arco-design/arco-design-pro/tree/main/arco-design-pro-vite)为基础。源码按官方 `gen:vite` 脚本的合并规则，从 `arco-design-pro-vite` 与 `arco-design-pro-next` 组装；保留官方布局、登录页、设置面板、路由和权限工具，并接入皓量云擎的后台页面与 Webman API。上游模板采用较早的 React 17、Vite 2，本项目将核心依赖更新为 React 18、Vite 8、TypeScript 6、Arco Design React 2.66；路由保留官方 React Router 5 API 并更新到 5.3.4。上游模板许可见 [ARCO_DESIGN_PRO_LICENSE](./ARCO_DESIGN_PRO_LICENSE)。

## 开发与构建

```bash
pnpm install
pnpm dev --host 0.0.0.0 --port 5175 --strictPort
pnpm lint
pnpm build
```

开发入口为 `/admin/`，开发服务将 `/api` 代理至 `VITE_BACKEND_TARGET`，默认 `http://127.0.0.1:8989`。生产构建写入 `backend/public/admin/`，由 Webman 的动态后台路径提供入口。默认入口为 `/admin`；安装时自定义的后台首级路径继续有效，静态资源统一由 `/admin/assets/` 提供。会员控制台与安装器仍独立构建。

开发服务监听所有网卡。同一局域网设备使用 `http://<开发机局域网 IP>:5175/admin/` 访问，本机仍可使用 `127.0.0.1`。启动时不要用 `--host 127.0.0.1` 覆盖监听地址。后端代理目标是开发服务器连接的地址，保持本机后端地址即可。

## 目录与接线

- `src/layout.tsx`：路由、权限及导航状态编排；`src/layouts/`：共用框架、经典布局、双列布局和配置入口，默认经典布局。顶部、底部和页面配置继续使用 `src/components/NavBar/`、`src/components/Footer/`、`src/components/Settings/`。
- `src/routes.ts`：使用官方树形路由和 `requiredPermissions` 控制菜单、页面与默认路由。Webman 权限码转换为 Arco 的 `resource/actions` 映射。
- `src/components/PermissionWrapper/`：官方元素权限组件，读取当前 Webman 登录态。
- `src/auth/`、`src/api/`、`src/access/`：HttpOnly Cookie 登录、统一请求和管理员、部门、角色 API。
- `src/pages/`：官方登录页及后台业务页面；工作台、系统设置、审计日志和业务管理页面通过已有 API 接入 Webman。

前端权限负责隐藏无权菜单和页面；服务端仍需对每次请求执行授权。保留 Arco Pro 的布局组件，内容区取消固定最小宽度；窄屏菜单使用遮罩展开，返回桌面时恢复桌面侧栏状态。表格、表单和详情按视口宽度自适应。

### 云服务器配置编辑容器

云服务器工作台保留四个主分区，资源新建/编辑、地域与可用区管理、资源对应、库存及高级参数均使用抽屉，不切换到独立编辑页。编辑入口仍通过 `edit`、`mapping`、`manage` URL 参数保存，可刷新恢复，关闭时保留当前分区与查询条件。

地域/可用区管理抽屉内的新建与编辑使用固定 Modal（`FormDialog mode="modal"`），不得根据高度升级为第二层抽屉。取消子 Modal 保留父抽屉；保存成功后更新父列表并关闭子 Modal。资源表单即时保存，参与购买的范围及规则继续由工作台顶部“保存配置”统一保存。

### 运营工作台

`/dashboard` 保留既有页面权限，展示欢迎卡片、业务摘要、待办事项、最近五笔订单、快捷操作与产品服务。`workplaceApi` 复用已有业务列表的服务端 `total`，只请求当前管理员有查看权限的数据源；订单总数与最近订单共用一次按创建时间倒序的查询。

会员、订单统计为当前可查看总数；产品仅统计 `active` 已开通实例，不代表实时运行状态；待办分别统计 `open` 工单、`fulfilling` 订单、`pending` 实名申请及 `pending_review` 退款、发票、提现。入口携带相同状态条件跳转对应列表，不在工作台直接修改业务数据。没有按日经营接口时不显示今日销售、趋势或成功率，也不将当前分页金额汇总为全站收入。

各模块独立加载；单项失败显示 `--`，有效零值显示 `0`。刷新重新查询，账号或权限变化立即隐藏旧范围数据并丢弃旧请求结果；服务端继续承担每次请求的授权。

浏览器验证：开发服务启动后执行 `node tests/workplace-browser.mjs`，通过 `HOWSTACK_PLAYWRIGHT_MODULE` 指定可用的 Playwright 模块，或使用当前环境提供的 `playwright`。脚本使用接口响应夹具验证查询、权限、失败恢复、跳转、响应式及深色主题，不能代替真实 Webman、数据库与生产验收。

快捷操作参考 Arco Pro 官方工作台的三列小图标布局：默认六个业务入口，查看更多展开当前权限允许的管理入口。待办使用两列紧凑行，窄屏改为单列；产品服务统一对齐名称与已开通实例数，整行链接支持键盘访问和新标签打开。工作台不再单独展示常用管理卡片。

### 经营分析

`/analysis` 位于「总览 → 经营分析」，只使用 `admin.page.analysis.view` 查看权限与 `GET /api/admin/v1/business-analysis` 聚合接口，不读取客户、订单或财务明细。详细口径见 [经营分析文档](../../docs/development/business-analysis.md)。

页面提供日期范围、日/月趋势、八项指标与同长度前期对比、实付/退款/净实付折线图、订单类型表和状态环图。图表按需加载 VChart，趋势另提供可展开的数据表。金额以整数分接收，显示人民币元；负净实付、有效零值和前期零值分别展示，不生成销售演示数据。

已应用查询保存到 URL，支持刷新及前进/后退恢复。非法日期、未来结束日期、超过包含 366 天的区间和未知粒度阻止请求；接口字段错误保留在查询区，失败提供重试。旧请求取消，查询或权限变化隐藏不匹配的统计。

验证命令：`node --test tests/business-analysis.test.ts tests/permission-tree.test.ts`；开发服务启动后执行 `node tests/business-analysis-browser.mjs`。浏览器脚本沿用 `HOWSTACK_PLAYWRIGHT_MODULE` 与 `ADMIN_BASE_URL`，使用接口响应夹具覆盖独立授权、查询恢复、负值/零值、异常响应、角色权限入口、桌面/窄屏与深色图表；夹具验证不能代替真实登录态与数据库联调。

### 官网与主题

`/website` 位于「内容管理 → 官网与主题」，接入现有 `/api/admin/v1/website` 接口。页面查看使用 `admin.page.website.view`，配置保存和图片上传使用 `admin.page.website.update`；权限树按后端目录提供查看、修改、安装、切换和预览五项独立权限。没有修改权限时，不请求上传可用性接口，配置字段只读。

「官网设置」管理 Logo、站点图标、顶部导航和页脚链接。顶部导航和页脚链接使用可直接编辑的表格；导航目标使用核心页面或主题页面的稳定标识，外链仅允许 HTTP/HTTPS，支持新增、删除和排序。网站名称、SEO、版权与备案仍由系统设置维护。

「主题配置」按服务端 Schema 生成首页、全局和自定义页面字段，支持文本、颜色、数字、开关、选项、图片和列表。展示内容、服务介绍等对象列表使用可编辑表格，图片列显示紧凑缩略图；主题色使用 Arco ColorPicker 的纯色、六位 HEX 模式，关闭透明度。官网继续由 Webman 渲染，保存成功后配置生效。

「主题管理」展示全部已安装主题，安装、切换和独立预览分别使用 `admin.page.website.install`、`admin.page.website.activate`、`admin.page.website.preview` 权限。安装上传不超过 10MiB 的 ZIP，不自动启用；切换前读取目标主题配置与页面，展示将移除的导航链接，以官网修订、目标主题修订和包摘要提交。当前配置未保存时阻止切换；409 不自动重试，切换成功重新读取完整配置和导航。各主题内容独立保留。

重新加载与访问官网入口位于当前主题卡片内。当前主题和已安装主题列表提供图片展示位；现有接口尚未返回公开图片地址，暂时显示「暂无主题图片」，不猜测主题包资源路径。后续公开图片地址接入后可使用原生图片预览。

当前主题预览携带完整未保存配置，其他主题预览使用保存值；预览不保存、不切换。前端展示有到期时间的独立预览链接，开发环境按 `VITE_BACKEND_TARGET` 解析后端路径，生产环境使用当前站点。预览凭证只驻留当前组件，不写入 URL、浏览器存储或日志。

两组配置分别完整保存并携带独立修订号。字段错误显示在对应位置；遇到修订冲突保留当前输入并要求重新加载，不自动覆盖。重新加载与离开页面时提示未保存改动，`?tab=site|theme|manage` 支持刷新恢复分区。图片上传使用文件存储接口，只保存图片 ID，通过稳定公开入口预览；上传过程中暂停配置保存，清空仅取消引用。

验证命令：`node --test tests/website-schema.test.ts tests/permission-tree.test.ts`；开发服务启动后执行 `node tests/website-browser.mjs`。浏览器脚本沿用 `HOWSTACK_PLAYWRIGHT_MODULE` 与 `ADMIN_BASE_URL`，用接口响应夹具验证编辑表格与 ColorPicker、独立修订号、冲突和字段错误、导航、图片上传、主题安装/切换/预览、独立操作权限、深色与双列布局，以及桌面和 320/390px 窄屏；真实管理员登录、ZIP 解包和文件存储需另行联调。
