# 会员中心主题开发

## 1. 普通 SPA

使用 React、Vue 或原生前端均可。交付正常构建的 `index.html` 和静态资源，入口固定为 `console/<type>/index.html`，无需在清单重复声明路径。系统根据后台选择及设备提供HTML，不要求挂载生命周期接口。

构建资源地址必须为相对地址，Vite设置 `base: './'`。在 `/console/account/profile` 等深层页面，系统向head加入固定版本的 `<base href="/theme-assets/.../console/pc/">`，相对脚本、样式、图标因此仍指向正确资源。HTML须有head，不能自行声明base或 `console-theme-config` 节点。路由basename固定为 `/console`，不能用静态资源base作为业务路由前缀。

## 2. 配置读取

HTML内有不执行脚本的JSON节点，渲染入口时注入当前选用版本的公开资料：

```js
const config = JSON.parse(document.getElementById('console-theme-config').textContent)
```

| 字段 | 含义 |
|---|---|
| contract | 配置契约 `1` |
| basePath | `/console`，业务路由前缀 |
| apiBase | `/api/console/v1`，会员 API 前缀 |
| assetBase | 当前版本的静态资源目录 |
| theme | 包ID、版本、pc/mobile类型 |
| site | 平台名称name、Logo、favicon、copyright、icp_record、public_security_record |
| settings | 此版本此类型的公开展示配置 |
| capabilities | 当前宿主提供的能力标识 |

开发服务器也可通过 `GET /api/console/v1/bootstrap` 获取相同配置，不需要先登录；响应不包含会员资料或密钥。正式HTML已经注入配置，无需为启动再增加一次请求。

### 品牌与浏览器标题

`site.name`、版权和备案来自后台“系统设置”；Logo与favicon复用“官网管理 → 基础设置”的品牌图片。公开配置每次请求读取当前设置，不随主题构建固化，不包含SEO配置或密钥。

SPA在路由变化时设置`document.title`为“页面名称 - 平台名称”，例如“会员登录 - 示例平台”“会员注册 - 示例平台”“账户设置 - 示例平台”。不将平台名称硬编码在HTML或路由组件中。官方模板返回浏览器标签页时刷新品牌资料，保留当前页面和未提交表单；第三方模板可通过bootstrap获取更新。

页脚版权按后台配置原样作为文本展示；版权或备案为空时不展示，不拼接固定品牌或年份。Logo与favicon为空时可以使用主题内置图片。

## 3. 页面与API

当前会员中心不提供后台主题配置项，不必声明 `settings.schema.json`。后台主题配置只编辑官网；组件级配置 API 与存储契约仅保留未来扩展边界。平台名称、Logo、页脚等由宿主公开配置提供，不重复放入会员主题展示配置。

必须实现登录 `/login`、注册 `/register`、总览 `/overview`、资料 `/account/profile`，在清单console.routes声明其语义名称。其他页面按当前会员API能力开发，业务请求使用同源 Cookie：

```js
const response = await fetch(config.apiBase + '/auth/me', { credentials: 'same-origin' })
```

请求路径、字段和响应以 `/api-docs#console` 及 `contracts/openapi/console-*.v1.yaml` 为准，可选HTTP助手 `themeRequest` 支持 GET/POST/PUT/DELETE 等标准 RequestInit 方法。浏览器不保存登录令牌，不通过后台 API 或可修改的会员ID访问他人资料。401清空本人缓存；退出成功后清除身份、余额、查询等状态。

官方模板已实现匿名购买 `/purchase/cloud-server?offer=<配置ID>`、本人订单 `/finance/orders` 和详情 `/finance/orders/<ID>`。购买页不能被登录保护提前拦截；目录、选项、报价使用 `/api/public/v1/products`，创建订单才要求会员身份。匿名继续时保留公开选配，登录后重新报价并要求再次确认，不自动下单。草稿不能保存密码、令牌或价格，失效产品及配置应重新确认，见 [官网展示与会员购买](../development/member-purchase.md)。

注册方式及验证要求来自服务端设置；不能凭主题配置开启业务功能。图片、播放器和网络监听仍须遵循所用框架的正常页面生命周期。

## 4. 设备选择

没有console目录时使用完整default会员组件。有console时，必须提供pc，mobile可选；不支持responsive或仅mobile。PC模板仍可做响应式布局。

后台在主题卡片直接“启用”，当前主题卡片展示“移动端模板”开关，操作即时保存。切换整套主题默认关闭移动端，管理员可在新主题卡片明确开启；没有额外的选择表单或保存按钮。系统识别实际会员主题（含default整组件回退）是否包含有效mobile入口，无入口时开关禁用，API拒绝开启。

| 开关 | PC访问 | 手机访问 |
|---|---|---|
| 关闭（仅PC主题只能关闭） | PC模板 | PC模板 |
| 开启（必须提供移动模板） | PC模板 | 移动端模板 |

开启时，按User-Agent及显式设备偏好选择入口；关闭时始终提供PC，不能通过Cookie强行使用移动端。窗口变化不会替换整套页面。不向default借用已提供会员组件的移动端入口。

仅在移动端开关开启时返回 `theme.presentation.split` 能力，模板可据此展示切换显示方式的菜单。主动选择使用展示Cookie `console_presentation=pc|mobile|auto; Path=/; SameSite=Lax`，HTTPS加Secure；刷新前提醒保存表单，偏好不改变业务权限。

## 5. 构建与验收

官方示例使用常规 Vite 应用构建，运行 `pnpm --dir frontend/console build:template` 同时得到 `backend/themes/default/console/pc/` 和 `backend/themes/default/console/mobile/` 两套独立构建。更新可修改版本号后重新打包上传，同ID包更新原主题；版本号仅作为包资料，相同版本号的内容更新也允许。

验收匿名重定向及恢复原URL、真实登录/退出、刷新深层路由、相对资源路径、401缓存清理、窄屏布局、设备偏好、开启/关闭开关及无移动模板的拒绝、缺省入口，以及同ID主题更新、配置保留和失败后继续运行。接口或页面尚未提供的业务能力应明确提示。


## 6. 默认官方移动端模板

官方主题提供 `console/pc/index.html` 和 `console/mobile/index.html`。移动端使用 [Arco Design Mobile React](https://arco.design/mobile/react/arco-design/pc/#/doc/readme)，不是 PC 组件缩小后的界面。后台“内容管理 → 主题管理”在默认官方主题卡片点击“启用”，再开启卡片中的“移动端模板”，手机访问 `/console/` 自动使用移动入口。操作即时保存；更新主题保留现有开关值，不擅自开启。

### 源码与职责

| 路径 | 职责 |
|---|---|
| `frontend/console/src/mobile/main.tsx` | 独立移动入口、Arco Mobile 全局上下文和 React 19 适配 |
| `src/mobile/MobileApp.tsx` | 登录保护、路由、动态标题、顶部导航与底部四入口 |
| `src/mobile/AuthPages.tsx` | 密码/短信登录、按后台配置注册、找回密码与 OAuth 回调 |
| `src/mobile/MemberPages.tsx` | 总览、本人余额、账户资料、消息阅读和退出 |
| `src/mobile/ui.tsx`、`feedback.ts` | 移动原生表单、Toast、公开品牌与页脚 |
| `src/mobile/useChallenge.ts` | 验证码账号绑定、过期与重发冷却，仅保存于内存 |
| `src/mobile/style.less`、`rootPixel.ts` | 官方公共 Less 变量、按需样式、rem 与安全区域 |
| `src/routePaths.ts` | PC 与移动共用 URL；不导入 PC 页面和样式 |
| `src/api/`、`src/auth/` | 两套入口共用 API、Cookie 会话及本人查询缓存 |

### 开发、构建与发布

```bash
pnpm --dir frontend/console dev:mobile --host 0.0.0.0 --port 5177 --strictPort
# 开发地址 http://127.0.0.1:5177/console/
pnpm --dir frontend/console build:template:mobile  # 仅生成移动组件
pnpm --dir frontend/console build:template:pc      # 仅生成 PC 组件
pnpm --dir frontend/console build:template         # 发行时构建两套入口
cd backend
php webman website:publish                        # 更新当前本地官方主题
```

开发入口用于直接开发移动界面，正式访问必须经过宿主设备选择和移动端开关；端口 5177 不属于交付地址。发行无需服务器安装 Node.js。主题 ZIP 包含构建后的目录，不包含 node_modules、会员数据或构建工具。构建自动携带新增 Mobile 依赖的许可证 `console/mobile/licenses.json`，不能手动修改产物。

新安装和升级通过前向迁移 `20261011001400` 登记当前内置发行包，保留默认主题 ID、官网展示配置、第三方主题选择和移动端开关。打包发行前必须执行上述两端构建，不修改已执行迁移或历史归档主题。

移动导航为总览、服务、消息、我的；资金账户从总览或我的进入。登录反馈统一使用 Mobile Toast，格式、密码确认和协议勾选使用 Mobile Form 字段提示。页脚、Logo、favicon、平台名称和浏览器标题遵循本文件品牌契约。

现阶段总览余额、资金账户、本人资料、站内消息和已有注册/密码登录接口使用现有会员 API。服务、订单等 PC 主题中尚未接入的业务继续明确提示待接入；短信登录、第三方登录及找回密码也遵循现有接口约定，接口未实现时提示原因，不模拟成功或伪造业务数据。

浏览器回归：先启动 `dev:mobile`，再执行 `HOWSTACK_PLAYWRIGHT_MODULE=<已安装的 playwright 模块> node frontend/console/tests/mobile-browser.mjs`。该脚本使用模拟 API，覆盖布局、登录返回路径、注册开关、验证码挑战、消息阅读、退出失败及会话失效；不能替代真实通道发送和财务验收。
