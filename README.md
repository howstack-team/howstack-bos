# 皓量云擎云业务运营系统

面向 IDC、云服务商和云资源分销商的开源业务系统，提供官网、运营后台和 PC / 移动端会员中心。

围绕云服务器完成产品选配、下单、充值支付、资源交付、续费运维和工单服务，支持通过插件接入供应商、支付、短信、邮件等外部服务。

[快速开始](#快速开始) · [开发指南](docs/development/quick-start.md) · [问题反馈](https://github.com/howstack-team/howstack-bos/issues) · [Apache-2.0](LICENSE)

> 当前处于社区版 1.0 收尾验收阶段，尚未正式发布。真实支付、供应商生产及部署验收以[上线范围与验收清单](docs/releases/community-1.0-scope-and-acceptance.md)为准。

## 快速开始

### 1. 准备环境

- PHP **8.3 或更高版本**、Composer 2。
- MySQL **8.0 或更高版本**、Redis，并提前创建一个空的开发数据库。
- Node.js **24 LTS**、pnpm **11**，用于前端开发与构建。
- PHP 扩展至少包括 `pdo_mysql`、`redis`、`mbstring`、`openssl`、`fileinfo`；其余依赖由 Composer 检查。OPcache 推荐启用。

### 2. 获取代码与安装依赖

```bash
git clone https://github.com/howstack-team/howstack-bos.git
cd howstack-bos
composer install --working-dir=backend
pnpm --dir frontend/console install --frozen-lockfile
pnpm --dir frontend/console build:template
```

会员中心模板构建必须在首次安装前完成。后台和安装器已包含构建资源，首次体验无需先启动它们的开发服务器。

### 3. 启动并完成安装

以下命令仅用于**全新克隆、尚无 `.env` 的环境**：

```bash
cp backend/.env.example backend/.env
cd backend
php webman install:key
php start.php start
```

打开 **http://127.0.0.1:8989/install**，输入命令行生成的安装授权码，按照页面配置数据库、Redis 和管理员。安装授权码一小时有效，不是商业授权码。

安装完成后，在后端终端按 `Ctrl+C` 停止，再执行 `php start.php start`，使新配置生效。

| 入口 | 地址 |
| --- | --- |
| 官网 | http://127.0.0.1:8989/ |
| 运营后台 | http://127.0.0.1:8989/admin/（以安装时设置的后台路径为准） |
| 会员中心 | http://127.0.0.1:8989/console/ |
| API 文档 | http://127.0.0.1:8989/api-docs（需管理员登录及查看权限） |

已有环境不要覆盖 `.env` 或重新安装；更新步骤见[数据库迁移指南](docs/development/database-migrations.md)。

### 4. 开始前端开发

保持后端运行，按需在仓库根目录的新终端执行：

```bash
# 运营后台
pnpm --dir frontend/admin install --frozen-lockfile
pnpm --dir frontend/admin dev --port 5175 --strictPort
```

```bash
# PC 会员中心
pnpm --dir frontend/console dev --port 5176 --strictPort
```

后台访问 http://127.0.0.1:5175/admin/，PC 会员中心访问 http://127.0.0.1:5176/console/。前端开发服务器自动代理 API 到本机 `8989` 后端。

独立移动端、官网主题、插件依赖、构建与常见问题见[开发指南](docs/development/quick-start.md)。

## 从哪里开始改

| 你要做什么 | 代码位置 / 文档 |
| --- | --- |
| 修改后台页面 | `frontend/admin/src/` |
| 修改 PC / 移动端会员页面 | `frontend/console/src/pages/`、`src/mobile/` |
| 开发后端业务和接口 | `backend/app/modules/` · [路由规范](docs/development/backend-routing.md) |
| 修改官网或开发主题 | `backend/themes/default/website/` · [主题开发](docs/themes/development.md) |
| 对接供应商、支付等外部服务 | `backend/plugins/` · [插件开发](docs/architecture/business-plugin-optimization.md) |
| 修改 API 契约 | `contracts/openapi/` · [API 文档维护](docs/development/api-documentation.md) |
| 修改数据库 | `backend/database/migrations/` · [迁移规范](docs/development/database-migrations.md) |

其他目录：`frontend/packages/` 为前端共享包，`docs/` 为专题文档，`deploy/` 为部署配置。开发前阅读 [AGENTS.md](AGENTS.md) 及对应子目录规范。

## 部署与贡献

- [安装与发行包制作](docs/development/online-installation.md)：站点根目录为 `backend/public/`，发行包携带后端依赖、插件依赖和前端构建资源，客户服务器无需 Node.js。
- [系统架构](docs/architecture/system-architecture.md)：需要了解模块边界时再阅读。
- 提交问题请说明复现步骤与环境；贡献代码请从 `main` 创建分支，通过 Pull Request 提交，并附验证结果。不要提交 `.env`、密钥、日志或用户数据。

## 许可证

本项目原创代码与文档采用 [Apache License 2.0](LICENSE)。第三方依赖、上游代码及素材保留各自许可证和声明，见 [NOTICE](NOTICE)。
