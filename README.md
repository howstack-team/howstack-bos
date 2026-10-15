# 皓量云擎云业务运营系统

皓量云擎云业务运营系统是一套面向 IDC、云计算服务商和云资源分销商的开源系统，采用客户独立部署的方式，建设官网、会员控制台和运营后台统一的业务体系。

项目仓库：[howstack-team/howstack-bos](https://github.com/howstack-team/howstack-bos) · [问题反馈](https://github.com/howstack-team/howstack-bos/issues) · [参与贡献](https://github.com/howstack-team/howstack-bos/pulls)

我们的目标是连接**产品配置、销售定价、订单支付、资源交付、续费、财务和售后**，让服务商围绕自己的品牌、产品和客户开展业务。

> 项目正在持续开发。当前已具备基础管理和部分业务记录能力，完整的云产品购买、渠道支付和资源生产链路仍在建设中。下文分别说明当前实现与建设方向。

商业订阅购买后，在后台“授权与更新 → 授权码激活”填写官网“我的授权”中的授权码即可；续费沿用原码，后台自动同步。系统更新复用受控安装器，详细配置与部署边界见[官网接入要求](docs/development/official-platform-v1-requirements.md)。

## 适用对象

- **IDC 与云计算服务商**：管理客户、云资源业务、订单和售后，逐步建立销售与交付闭环。
- **云资源分销商**：对接上游供应能力，建设自己的产品方案、售价和客户服务体系。
- **需要独立品牌的运营团队**：部署自己的官网和控制台，管理自己的数据库、域名及业务配置。

## 当前能力

| 领域 | 已落地内容 |
| --- | --- |
| 运营后台 | 系统用户、部门、角色权限、基础与安全设置、登录日志和操作日志 |
| 会员管理 | 会员资料、分组、等级、个人与企业实名认证记录及人工审核；会员控制台提供手机号与密码登录、余额展示 |
| 业务记录 | 云服务器、物理服务器、服务器托管的实例记录查询 |
| 产品配置 | 云服务器配置资源、销售草稿与发布版本、配置联动及费用预览；供应连接与魔方云目录映射 |
| 财务基础 | 订单及订单项、充值、支付、资金流水、退款、发票、提现记录的后台查询与详情；资金账户、来源批次和流水基础结构 |
| 服务与内容 | 后台工单类型、工单会话与回复、快捷回复；帮助文档和新闻公告的分类管理、Markdown 内容编辑 |
| 安装与维护 | 在线安装器、数据库迁移和系统升级基础机制 |

已实现会员选配、下单、在线充值到账与余额付款；后台支持线下入账和余额退款等财务办理。真实支付平台联调、生产资源开通、原渠道退款和自动打款需按实施边界验收。具体范围见 [财务管理实施状态](docs/development/finance-foundation.md) 和 [服务与内容管理实施说明](docs/development/support-and-content.md)。

## 产品建设方向

以下内容为已确定的设计或后续规划，尚不代表当前版本已经提供相应功能。

### 专属产品配置与购买体验

每种产品类型拥有自己的业务模型、配置页面、购买页面和生命周期。云服务器按地域、可用区、线路、规格、镜像、系统盘、数据盘、带宽、IP 和防护等配置组织，通过联动和组合校验呈现实际可交付的方案。

首期以云服务器为核心，完成真实购买与交付闭环；物理服务器、域名、SSL 等类型按各自业务特点逐步扩展。

### 销售方案与供应来源分开管理

服务商维护自己的可售配置、价格和政策，通过供应适配器连接外部生产系统。供应目录与销售配置分开管理，已发布配置、订单快照和存量服务保留各自的版本与绑定。

首个生产适配器计划接入魔方云。其他供应系统按接口能力与兼容要求逐步接入，多级上下游采用逐级采购、独立销售、独立定价的设计。

### 交易、服务与财务形成闭环

围绕订单项和单台服务实例管理支付、交付、续费、试用及退款。一单购买多台资源时，每台保留独立的金额归属和服务关联，支持后续针对单台申请退款。

充值、在线支付和余额支付分别记录业务来源，配合资金流水、退款、发票和提现记录进行核对。自动续费首期规划使用会员明确授权的余额支付；外部操作通过持久化任务、幂等和状态查询恢复，保证资金与资源状态可追踪。

### 核心业务内置，外部能力通过插件接入

| 层次 | 建设范围 |
| --- | --- |
| 核心业务 | 客户、权限、产品业务、订单、账务、服务生命周期和工单等标准能力 |
| 外部适配 | 云资源供应商、支付、文件存储、邮件、短信和实名认证等外部服务接口 |
| 个性扩展 | 行业流程、营销工具、报表及其他专项功能 |

标准业务流程在核心系统内形成完整链路，插件通过明确的能力声明、权限和版本契约接入。开发规范、示例和兼容说明将随插件体系逐步提供，便于第三方开发者维护和升级扩展。

### 品牌呈现与业务能力协同

官网主题承担品牌与页面呈现，产品类型提供专属购买配置器，会员控制台承载日常业务操作。主题通过稳定数据接口组合页面，服务端统一校验配置、价格、权限和资源归属。

后续将建设数据迁移辅助、系统诊断及经营分析能力，帮助运营团队核对迁移数据、定位问题，并了解续费、客户留存、履约成功率和业务成本。

完整的模块边界、实施路线和验收要求见 [云产品平台架构与开发方案](docs/architecture/cloud-product-platform.md)。现有持久化、付款事务和服务关联调整见 [现有系统与 ORM 重构实施说明](docs/architecture/current-system-refactor.md)。

后台产品管理的下一步整改见 [云服务器工作台与产品管理整改方案](docs/architecture/cloud-server-workspace-plan.md)：云服务器固定为一个内置产品，点击菜单直接进入十个配置区域；销售方案可以分别维护，云服务器入口不再新增产品。固定入口与十个区域已落地，包含网络策略和外部平台交付规则；同系统采购、真实生产、会员购买页面和门户主题仍按后续阶段建设。

## 开源生态与服务方向

- **开放协作**：以开源代码、版本化接口契约和开发文档支持集成与二次开发，逐步提供插件及主题开发示例。
- **清晰的能力边界**：标准业务能力以内置模块建设；外部服务通过适配器接入，特色功能通过扩展模块提供。
- **围绕交付提供服务**：商业服务拟覆盖部署实施、数据迁移、供应接口适配、培训和技术支持，服务范围与交付内容明确说明。
- **透明的维护规则**：商业版本、授权权益、更新范围、兼容政策及服务期限将在正式发布时说明，便于客户评估持续使用与维护成本。

当前代码遵循 AGPL-3.0，商业版本的具体权益、价格及服务条款尚未发布。插件 SDK、主题开发工具和迁移工具仍属建设方向。

## 技术栈

- 后端：PHP 8.4、Webman、MySQL 8.0、Redis
- 运营后台：React 18、Vite、Arco Design React / Arco Design Pro 主题
- 会员控制台：React 19、Vite、Arco Design React
- 在线安装器：React 19、Vite、Arco Design React
- 官网：Webman 服务端渲染；主题与专属购买配置器机制按上述方案建设

## 目录结构

```text
backend/             后端应用
backend/plugins/     业务插件分类目录，统一清单、固定 SDK 与宿主生命周期
backend/themes/      官网与会员中心统一主题
frontend/admin/      运营后台
frontend/console/    会员控制台
frontend/install/    在线安装器
frontend/packages/   前端共享包
contracts/           API、产品和插件契约
docs/                架构及开发文档
deploy/              部署配置
```

## 系统架构与优化建议

- [系统架构图册](docs/architecture/system-architecture.md)：整体结构、全部业务模块、订单财务关系、消息运行时和部署目标，提供六张图的 Mermaid 图源、SVG 与 PNG。
- [授权与版本功能隔离及升级下发方案](docs/architecture/licensing-editions-and-delivery.md)：统一核心、细粒度权益、商业扩展、到期业务连续性、发行组合及升级恢复的分阶段技术设计。
- [系统缺陷与优化建议](docs/architecture/system-improvement-review.md)：基于 2026-10-10 工作区的 18 项问题与建议，包含代码依据、优先级、验收标准和社区版实施顺序；明确区分已实现、未完成和条件风险。
- [第三方主题开发指南](docs/themes/development.md)：统一主题包、官网Twig、会员中心自适应/PC/移动端完整前端、安装与版本绑定。
- [官网首页主题技术方案](docs/architecture/website-theme-plan.md)：Twig 主题目录、核心与自定义页面路由、公开数据契约、官网配置与图片存储、管理 API、资源发布及分阶段实施安排。

## 本地开发

```bash
git clone https://github.com/howstack-team/howstack-bos.git
cd howstack-bos
```

### 后端

```bash
pnpm --dir frontend/console install --frozen-lockfile
pnpm --dir frontend/console build:template
cd backend
composer install
cp .env.example .env
php start.php start
```

首次安装或发布正式安装包前，先构建内置会员中心模板；数据库迁移会登记该构建的不可变版本。服务器安装时不执行前端编译，发布包需携带 `backend/themes/default/console/` 的构建文件。

后端新增业务接口前请先阅读 [后端路由开发规范](docs/development/backend-routing.md)。涉及数据表结构或系统版本更新时，遵循 [数据库迁移与系统版本更新规范](docs/development/database-migrations.md)。

统一 API 文档位于 `/api-docs`，也可从后台头像菜单进入。按运营后台、会员控制台、公开与安装接口分组，提供中文说明、代码示例、下载及同源调试；页面和原始契约均要求管理员登录和“查看 API 文档”权限。构建、维护、发布与现有字段缺口见 [统一 API 文档](docs/development/api-documentation.md)。

云产品、计费、履约、供应商插件及多级上下游能力的目标边界见 [云产品平台架构与开发方案](docs/architecture/cloud-product-platform.md)。文档会明确区分当前实现、确定设计和后续扩展，权限或菜单中的预留名称不代表业务已经实现。

财务管理已落地的查询页面、资金基础结构与尚未开放的交易能力见 [财务管理实施状态](docs/development/finance-foundation.md)。

后台云服务器产品配置、销售版本、费用预览与供应映射见 [后台产品实施说明](docs/development/backend-cloud-products.md)。云服务器为固定产品入口，配置资源、销售方案和交付规则在十个区域内管理，销售方案新增不增加产品记录；资源供应商同属产品管理。

新建供应连接可选择已安装启用的适配器插件，首个实现为魔方云 v1 的认证与只读目录。扩展协议及当前能力边界见 [供应插件 SDK](docs/development/provider-adapters.md)。

插件目录、配置管理、类别固定方法、宿主权限边界及兼容迁移见 [业务插件体系优化方案](docs/architecture/business-plugin-optimization.md)。已迁移到 `backend/plugins/<类别>/<插件标识>/`，魔方云采用 SDK v1，后台应用中心管理已获取应用的安装、更新和卸载，本地包登记与启停使用后端维护命令。真实生产及其他类别运行契约按后续阶段建设。

第三方开发者接入 SMTP 或 HTTPS 邮件平台，请阅读 [邮件通道插件开发文档](docs/plugins/mail-channel-development.md)，包含独立插件包的完整示例、公共接口、插件本地 Composer 依赖、联调与交付要求。支付宝订单码支付、身份验证及固定宿主调用示例见 [支付宝通道接入与插件开发](docs/plugins/alipay-channels.md)。推荐的 GET/POST/PUT/DELETE 等快捷方法见 [公共 HTTP 请求指南](docs/plugins/http-client.md)。本项目维护者的实现说明见 [邮件通道插件开发指南](docs/development/mail-channel-development.md)。

后台“授权与更新”和“应用中心”已接入官网 v1 客户端、实例签名、商业期限校验及本机维护任务。未配置授权时使用社区版。部署配置与当前验收范围见 [官网接入实施说明](docs/development/official-platform-implementation.md)；官网需要补充的商业证书及三版本更新接口见 [HLYQ-WEB 接入要求](docs/development/official-platform-v1-requirements.md)。真实授权、应用下载安装和在线升级仍须完成官网联调后验收。

手机号/邮箱注册方式、验证码设置、公开会员编号与升级说明见 [会员注册与公开编号](docs/development/member-registration.md)。

### 运营后台

```bash
cd frontend/admin
pnpm install
pnpm dev
```

### 会员控制台

```bash
cd frontend/console
pnpm install
pnpm dev
```

### 在线安装

三个前端应用的生产构建产物会写入 `backend/public/`。制作发行包时只需交付包含 Composer 生产依赖和前端构建产物的 `backend/`；站点根目录必须指向 `backend/public/`。首次访问域名时，系统会自动跳转到 `/install` 完成环境检测、数据库和系统配置。

包含第三方 SDK 的内置插件还需在各自目录按锁文件构建生产 `vendor/` 并纳入发行包。SMTP 的 PHPMailer 由 `backend/plugins/mail/smtp/composer.json` 管理，不安装到宿主 vendor；支付宝插件继续共用主 Composer 中的 Easy SDK；阿里云、腾讯云与微信支付插件各自在自己的目录管理 SDK 和锁文件，七牛使用 REST 不依赖 SDK；不要将 Git 忽略的插件 vendor 遗漏出发行包。人机验证配置见 [人机验证插件开发与接入](docs/plugins/captcha-channels.md)。

详细的构建、安装状态、安全与失败恢复说明见 [在线安装器开发与发布说明](docs/development/online-installation.md)。

## 分支约定

- `main`：默认和稳定分支，所有变更通过 Pull Request 合并
- 外部贡献者先 Fork 本仓库，再在自己的 Fork 中创建 `feature/*` 或 `fix/*` 分支
- 项目协作者从 `main` 创建短期功能或修复分支，完成后向 `main` 提交 Pull Request

## 软件著作权与许可

本项目已登记计算机软件著作权，登记号：**2024SR0953729**。项目原创代码及相关文档的著作权归相应权利人所有，依法受到保护。

使用、修改或分发本项目时，应遵守适用的许可证，并保留原有的著作权声明及许可信息。项目中的第三方组件及上游代码，其权利归属与使用条件以各自许可证为准。

本项目基于 [GNU Affero General Public License v3.0](LICENSE) 开源。项目引用的第三方组件及上游代码继续遵循其各自许可证。

通道插件配置、依赖和业务接入边界见 [官方通道插件接入与开发（v1）](docs/plugins/cloud-channels.md)。


### 在线安装与认证安全

首次部署先在 `backend/` 执行 `php webman install:key`，将生成的一小时授权码输入安装器。授权码只在服务器命令输出中显示，不能经公开API生成，不要放入URL或提交到Git。重新生成会立即撤销旧安装授权，安装完成后清理凭据；已经安装的实例拒绝授权和重新安装。

当前没有使用WebSocket推送业务，已移除未接入鉴权的 `webman/push` 依赖及默认公开服务/路由。通知继续使用已有消息队列、站内消息和前端查询；以后接入实时推送时须重新定义所有权鉴权、实例密钥和内部发布边界，不能恢复旧默认配置。

会员密码登录、短信验证码登录、手机号/邮箱找回密码及可撤销会话的配置和验收说明见 [会员认证与注册](docs/development/member-registration.md)。

在线充值部署、PC/Mobile接入、回调验签、自动查单与人工核查说明见[在线充值与支付确认](docs/development/online-payments.md)。

后台验证器绑定、恢复码、第二步登录与升级说明见[管理员两步验证](docs/development/admin-mfa.md)。
