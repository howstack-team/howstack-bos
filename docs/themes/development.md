# 第三方主题开发指南

官网产品展示与会员选配分工：宿主核心产品页 `/products/cloud-server` 可由 `website/pages/products.html.twig` 定制，模板缺失时使用已安装的官方模板。不要将该路由加入 `website/pages.json`，也不能声明保留标识 `products`。官网首页及产品页的 `page.products` 提供可售目录、公开说明和 `purchase_url`，模板只负责展示与跳转，不计算价格或写订单。会员购买、匿名恢复及订单接口见 [官网展示与会员购买](../development/member-purchase.md) 和 [会员模板开发文档](console.md)。

主题以一套主题统一启用，每个主题ID只有一条当前安装记录。开发者可以只开发官网、只开发会员中心，或同时提供两者；未提供的整个组件使用系统 `default` 主题。新主题安装不自动启用；同ID包更新现有主题，已启用主题更新后直接生效，不提供独立预览。

## 1. 固定目录

```text
example/
├── theme.json
├── cover.svg
├── website/                       可选：官网，统一自适应
│   ├── index.html.twig             首页入口
│   ├── layout.html.twig            可选公共布局
│   ├── pages.json                  可选自定义页面声明
│   ├── settings.schema.json        可选配置规则及默认值
│   ├── pages/                      自定义 Twig 页面
│   ├── partials/                   公共片段
│   ├── errors/404.html.twig         404入口
│   └── assets/                     CSS、JS、图片和字体
└── console/                        可选：普通 SPA 构建
    ├── pc/index.html               必备：PC入口，可自行做响应式布局
    └── mobile/index.html           可选：独立移动端入口
```

至少提供一个组件。无需空目录或重复的组件入口清单，根目录只保存整套主题资料。官网页面、配置和素材全部放在 `website/`。新上传主题不接受旧 `template/` 目录；已有安装记录通过系统前向迁移转换，保留配置与旧资源。会员中心各类型资源放在各自类型目录；当前会员中心不提供后台主题配置项。

```json
{
  "id": "example",
  "name": "示例主题",
  "version": "1.0.0",
  "theme_api": "1",
  "requires": {"core": ">=0.1.0 <1.0.0"},
  "cover": "cover.svg",
  "console": {"routes": ["auth.login", "auth.register", "console.overview", "account.profile"]}
}
```

只有官网时省略 `console`。会员中心没有声明 routes 时使用上述四个必需页面的声明；声明能力不代表页面已经实现，开发者须实际验收。入口由固定目录识别，不在清单重复指定路径。

## 2. PC与移动端、组件回退

- 官网只有一套自适应 Twig 页面，不区分PC和移动目录。
- 没有 `console/`：会员中心整体使用当前绑定的完整 default 版本。
- 有 `console/`：必须提供 `pc/index.html`，`mobile/` 可选。只有移动端的包不能安装。
- 不支持 `responsive/` 类型。PC模板可以自行采用响应式布局，手机访问PC模板不代表禁止手机访问。
- 提供 `mobile/index.html` 并通过校验后，后台“启用独立移动端模板”开关才可开启。未提供时开关禁用，并提示当前会员主题未提供移动端模板。
- 关闭开关时，所有设备使用本主题PC模板；开启时，PC使用PC模板，手机使用移动端模板。后台直接在主题卡片启用整套主题，切换后默认关闭移动端；当前主题卡片提供移动端开关，管理员操作即时保存，不提供顶部选择表单或额外保存按钮。新主题安装不会自动开启移动端。
- 开关是宿主配置，不写入 `theme.json` 或展示Schema。系统按目录及入口识别能力，前后端均校验；展示Cookie也不能绕过关闭的开关。
- 已声明的目录缺少入口、模板损坏仍拒绝安装，不静默回退。正在使用的移动端入口不能直接随更新移除，须先关闭开关；已有展示配置的组件仍受配置兼容检查保护。
- 没有 `website/`：官网整体使用 default 的页面及展示配置。
- 不逐文件混用模板和资源，也不借用default补齐已提供会员组件的设备入口。
- 平台名称、Logo、版权、公司资料、导航和SEO由宿主管理，切换主题不回写公共资料。

## 3. 配置

展示字段使用有限JSON Schema，默认值写在节点的 `default` 中。后台自动生成表单，后端校验配置与默认值；没有展示配置时不必创建配置文件。官网配置结构为 `global` 和 `pages` 两个对象，页面配置集中维护，不要求每个页面单独拆Schema。详见[官网开发](website.md)。

当前后台主题配置仅编辑官网，不展示“官网 / PC版 / 移动端”配置切换；仅提供会员中心的主题不显示配置入口。会员中心的品牌、Logo、页脚等读取宿主公开配置，不在主题配置中重复维护。

底层保留按 `website / pc / mobile` 区分配置的 API、存储及校验契约，供未来明确开放会员展示配置时扩展。`console/<type>/settings.schema.json` 是预留协议，当前开发者无需提供；已有包声明的配置仍参与安装及更新兼容校验，但不会自动生成会员配置入口。不再使用独立默认值文件。密钥、Cookie、访问令牌和业务功能开关不能写入主题展示配置。

## 4. 构建与交付

所有前端依赖本地编译，交付普通HTML与静态资源；宿主不执行Node编译、Composer或安装钩子。官网使用Twig，不允许PHP及核心模型代码。会员模板可使用React、Vue等框架，无需挂载协议。

```bash
pnpm --dir frontend/console build:template
php backend/tools/package-theme.php backend/themes/default /tmp/default.zip
```

官方源码在 `backend/themes/default/website/` 和 `frontend/console/`，会员构建输出到 `backend/themes/default/console/pc/` 和 `console/mobile/`；移动组件使用 Arco Design Mobile，源码位于 `frontend/console/src/mobile/`。第三方源码可以放在自己的工程，只需按上述目录交付。

ZIP只含一个主题ID目录；最大25MiB，最多1024个文件，单文件最大10MiB，展开最大100MiB。允许JSON、Twig、HTML、JS、CSS、常用图片及WOFF2。安装检查路径、链接、重复文件、配置、系统版本、组件完整性及官网渲染。同源JS属于可信代码，安装检查不代替代码审核。

## 5. 统一管理API

| 接口 | 用途 |
|---|---|
| `GET /api/admin/v1/themes` | 主题目录、当前包资料、组件及实际使用情况 |
| `POST /api/admin/v1/themes/install` | 安装或更新ZIP，新主题不自动启用 |
| `GET /api/admin/v1/themes/bindings` | 一个主题选择及两个组件实际来源 |
| `PUT /api/admin/v1/themes/bindings` | 提交revision、release_id、mobile_enabled，统一保存 |
| `GET/PUT /api/admin/v1/themes/{id}/components/{component}/settings` | website/pc/mobile配置 |
| `DELETE /api/admin/v1/themes/{id}` | 卸载未使用的第三方主题 |

写操作须后台认证、操作权限及同源检查。409表示并发冲突；卡片启用与移动端开关失败时保持已保存状态，提示重新加载后重试；官网展示配置保留未保存草稿。default及正在使用的主题不能卸载。同ID上传更新原记录，记录ID不变；相同内容重复上传无变化。`version`仅为主题包资料，不提供历史版本选择或回退入口。更新保留已有兼容配置，新增字段使用Schema默认值；删除仍有配置的组件、删除字段或改变类型导致不兼容时拒绝更新，并提示开发者或管理员处理配置。更新已启用主题同时要求配置修改及主题切换权限。资源先发布到新摘要目录，再原子更新当前记录和配置；失败时当前主题继续运行，旧资源仍可供已打开的标签页使用。

## 6. 迁移与验收

正式安装包先构建完整default，再执行系统迁移。迁移前备份数据库与主题快照。历史官网和会员独立选择合并时，以原官网为准；原官网是default且会员使用第三方时，优先第三方会员主题。旧主题目录转换为新版本，配置修订及素材引用保留，旧快照不覆盖。旧清单不再接受新的ZIP安装。

旧responsive组件通过前向迁移改为PC；已有PC时优先保留原PC入口及配置。迁移保留记录ID和配置修订，原独立移动端主题继续开启，其余默认关闭。旧物理快照保留，先备份再迁移。

历史迁移需要的静态归档位于 `backend/resources/migration-themes/`，仅用于重放已执行迁移，不是可选主题。

验收：完整/官网单独/会员单独包、PC单独/PC加移动端/拒绝移动单独与responsive、开关能力及权限/409、关闭时手机和Cookie仍使用PC、缺失组件来源、统一切换、默认配置、同ID更新、配置保留与更新失败、卸载保护、权限拒绝、真实登录退出、深层路由刷新、401清理与1440px/390px布局。

更多：[官网开发](website.md)、[会员开发](console.md)、[API文档](/api-docs)。

主题唯一性迁移会优先保留正在使用的安装记录和配置，其余同ID记录合并删除；没有使用中的记录时保留最后安装的记录。升级前备份数据库和主题快照，旧物理快照保留，后台不提供历史记录恢复。`release_id`是已安装主题记录的内部ID，更新时保持不变。
