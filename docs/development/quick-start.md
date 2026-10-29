# 开发入门

先按[仓库首页](../../README.md#快速开始)完成安装。本页按实际开发任务补充操作，所有 `pnpm --dir` 示例均从仓库根目录执行；`php webman` 和 `php start.php` 均在 `backend/` 执行。

## 一、认识运行方式

- Webman 提供 API、官网渲染、后台和会员中心的生产静态资源，默认监听 `8989`。
- 后台、PC 会员中心和独立移动端各有 Vite 开发入口，按需启动，不必同时运行所有应用。
- 开发时前端把 `/api` 请求代理给 Webman，浏览器使用相对路径，业务数据仍由后端提供。
- 官网使用 Twig 模板；修改会员中心源码不会自动重建后端正在使用的会员模板。
- 社区版不需要先购买商业授权；真实支付、短信或供应商操作需要配置对应第三方通道。

## 二、环境与首次安装

推荐 PHP 8.3、Composer 2、Node.js 24 LTS、pnpm 11；MySQL 最低 8.0。安装器会检测数据库、Redis、PHP 扩展和目录权限。完整要求见 [PHP 运行基线](php-compatibility.md)。

先启动 MySQL、Redis，并创建空数据库。数据库用户必须有该库的建表和数据读写权限。不要把安装器连接到生产库或已有其他系统数据的数据库。

全新环境在安装依赖后，先构建 `frontend/console` 的 PC 与移动模板，再按 README 生成安装授权码并完成网页安装。安装器自动执行核心迁移、初始化管理员和设置，不需要手动导入 SQL 或先运行迁移命令。

安装会写入 `backend/.env`。安装完成后完整停止并重启 Webman。后续不要重新复制 `.env.example`，也不要删除安装标记。

```bash
# 在 backend/ 检查当前 PHP 与已安装依赖是否匹配
composer check-platform-reqs

# 查看迁移状态；已有安装更新代码时，先备份再按迁移指南升级
php webman system:migration-status
```

## 三、按需启动开发入口

以下命令分别在不同终端运行，前提是后端已经启动。

| 应用 | 安装依赖 | 启动命令 | 浏览器入口 |
| --- | --- | --- | --- |
| 后台 | `pnpm --dir frontend/admin install --frozen-lockfile` | `pnpm --dir frontend/admin dev --port 5175 --strictPort` | `http://127.0.0.1:5175/admin/` |
| PC 会员中心 | `pnpm --dir frontend/console install --frozen-lockfile` | `pnpm --dir frontend/console dev --port 5176 --strictPort` | `http://127.0.0.1:5176/console/` |
| 独立移动端 | 与 PC 共用依赖 | `pnpm --dir frontend/console dev:mobile --port 5177 --strictPort` | `http://127.0.0.1:5177/console/` |
| 安装器页面 | `pnpm --dir frontend/install install --frozen-lockfile` | `pnpm --dir frontend/install dev --port 5178 --strictPort` | `http://127.0.0.1:5178/install-assets/app/` |

安装器页面开发需要未安装的独立环境，不能用它重装已有环境。线上会员中心是否使用移动模板，由后台当前主题卡片的“移动端模板”开关控制；`dev:mobile` 用于直接开发该模板。

后端使用其他端口时，可在启动后台或会员前端时显式指定代理目标：

```bash
VITE_BACKEND_TARGET=http://127.0.0.1:8990 pnpm --dir frontend/admin dev --port 5175 --strictPort
```

同一局域网使用开发机的内网 IP 访问前端端口，确保防火墙允许访问。前端默认监听 `0.0.0.0`，代理仍连接开发机上的后端；不要在业务代码中写死内网 IP。

## 四、找到对应代码

| 任务 | 起点 | 进一步阅读 |
| --- | --- | --- |
| 后端业务 | `backend/app/modules/<模块>/`，先看路由、Controller 和 Application | [后端规范](../../backend/AGENTS.md)、[路由](backend-routing.md) |
| 表结构 | 新建前向迁移，不修改已执行迁移 | [数据库迁移](database-migrations.md)、[字段注释](database-schema-comments.md) |
| 后台界面 | `frontend/admin/src/pages/`，配套路由与权限目录 | [后台规范](../../frontend/admin/AGENTS.md) |
| 会员界面 | `frontend/console/src/pages/` 与 `src/mobile/`，API 和业务 Hook 复用 | [会员规范](../../frontend/console/AGENTS.md) |
| 官网主题 | `backend/themes/<主题>/website/` | [主题开发](../themes/development.md)、[官网模板](../themes/website.md) |
| 会员主题 | `frontend/console/` 是默认主题源码，构建后进入主题 `console/pc` 和 `console/mobile` | [会员模板](../themes/console.md) |
| 外部适配 | `backend/plugins/<类别>/<插件>/`，不要把第三方参数塞进核心业务 | [插件契约](../architecture/business-plugin-optimization.md)、[供应插件](provider-adapters.md) |

API 的唯一文档源在 `contracts/openapi/`。运行后的 `/api-docs` 要求管理员登录且具有查看权限；它不是公开匿名入口。

## 五、配置与使用外部插件

首次运行界面不要求配置支付、短信、OAuth 或云资源商户。测试这些业务时，再安装并启用对应插件、配置通道与供应映射；没有真实凭据时不要把模拟场景当作联调通过。

带 SDK 的官方插件有各自的 `composer.json` 和锁文件；后端根目录的 Composer 不会安装这些依赖。首次安装前按 README 安装所有随包官方通道的生产依赖，即使尚未启用对应业务也不能省略。单独补齐 SMTP 依赖时，从仓库根目录执行：

```bash
composer install --working-dir=backend/plugins/mail/smtp --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins
```

在 `backend/` 查看包诊断，再按需登记和启用指定插件：

```bash
php webman business-plugin list
php webman business-plugin install smtp
php webman business-plugin enable smtp
```

不要对已经安装的插件重复执行 `install`。插件包或其依赖更新时，使用现有 drain / upgrade 流程，保留历史包与在途任务；不要直接覆盖已审核指纹。此次官方插件许可证元数据调整也会改变包摘要，已有安装按此流程重新审核，不自动认可新摘要或改写历史交易快照。详见[通道管理](channel-management.md)和[插件实施说明](business-plugin-implementation.md)。

## 六、检查与构建

按改动范围选择相应检查：

```bash
pnpm --dir frontend/admin lint
pnpm --dir frontend/console lint
pnpm --dir frontend/console test
pnpm --dir tools/api-docs install --frozen-lockfile
pnpm --dir tools/api-docs check
```

后端测试在 `backend/` 使用 `vendor/bin/phpunit <测试路径>`。部分集成测试需要独立 MySQL、Redis 或浏览器环境；先阅读对应测试和专题文档，不要向生产库执行测试。

生成后台或安装器资源：

```bash
pnpm --dir frontend/admin build
pnpm --dir frontend/install build
```

修改默认会员主题后，构建 PC / 移动模板，再在 `backend/` 发布资源：

```bash
# 仓库根目录
pnpm --dir frontend/console build:template

# 在 backend/ 执行
php webman website:publish
```

完成后重启 Webman。`frontend/console build` 生成的是 `/public/console/` 的独立 SPA 产物，不能替代主题构建与发布。发行包还必须包含所有实际启用插件的生产依赖，完整流程见[安装与发布](online-installation.md)。

## 七、常见问题

| 现象 | 先检查 |
| --- | --- |
| 首次访问跳到 `/install` | 尚未安装，按安装流程完成；不要手动把 `APP_INSTALLED` 改成 `true` |
| 安装环境检测失败 | PHP CLI 的扩展、`backend/.env` 与 `backend/runtime/` 写权限、数据库和 Redis 是否真实可达 |
| 会员模板缺失 | 是否在安装前执行过 `frontend/console build:template` |
| 前端 API 返回 502 | Webman 是否在运行、端口是否为 `8989`、`VITE_BACKEND_TARGET` 是否正确 |
| 修改 `.env` 后无变化 | Webman 长驻进程需完整重启；仅刷新浏览器不够 |
| 端口被占用 | 停止原开发进程或明确更换端口；`--strictPort` 避免悄悄换端口 |
| `/api-docs` 跳转登录或返回 403 | 使用管理员账号并授予查看 API 文档权限；检查 `API_DOCS_ENABLED` |
| 没有支付或短信入口 / 插件不可用 | 检查插件生产依赖、安装状态、通道启用状态和必要商户配置 |
| 手机仍使用 PC 模板 | 检查当前主题是否带移动模板、后台是否开启该开关；本地可直接使用 `dev:mobile` |

当前待验收事项与暂缓入口统一以[社区版 1.0 清单](../releases/community-1.0-scope-and-acceptance.md)为准。
