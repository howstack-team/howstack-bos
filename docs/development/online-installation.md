# 在线安装器开发与发布说明

## 目标

发行包只需要包含 `backend/`。运营后台、会员控制台和安装器都在发布前完成构建，产物随 Webman 后端一起交付；客户服务器无需安装 Node.js，也不会在浏览器运行时访问 CDN。

## 访问与安装状态

- 未安装时访问 `/`、`/console` 或自定义后台路径会跳转到 `/install`。
- 未安装时，除安装接口以外的 `/api/*` 返回 HTTP 503，并携带安装入口。
- 安装成功后访问 `/install` 会跳转到自定义后台路径，安装接口拒绝重复执行。
- 安装状态以 `.env` 中的 `APP_INSTALLED=true` 为最终判定；`runtime/install/installed.json` 只用于保存非敏感安装摘要。
- 自定义后台路径在安装时写入 `.env`，安装后可在「系统设置 → 安全设置 → 后台访问入口」修改，路由会先匹配核心和插件路由，再由 fallback 判断后台入口，避免覆盖插件路由。

## 安装流程

1. 阅读宁波皓量云擎网络科技有限公司《皓量云擎云业务运营系统软件使用协议》并明确勾选同意；社区版代码的许可权利仍以仓库 Apache-2.0 为准。
2. 检测 PHP 8.3、必需扩展、`.env` 与 `runtime/` 写权限、安装器静态资源及随包官方通道的锁定生产依赖。插件依赖缺失或不是生产安装时，在建表前阻止继续安装并列出需要修复的插件。同时检测 OPcache 扩展，推荐安装并启用以提升运行性能，但未安装不阻止继续安装。
3. 配置并实际测试 MySQL 8.0 与 Redis 连接；只有连接测试成功后才允许进入下一步。数据表前缀固定为 `hlyq_`，Redis 安装配置只使用密码认证，不展示 ACL 用户名。
4. 配置网站标题与地址、自定义或随机后台路径，以及管理员昵称、账号和密码；网站描述留空，管理员账号与密码可分别随机生成。
5. 分阶段执行核心数据库迁移、写入系统初始数据、原子生成 `.env`、写入安装标记，并返回实时阶段进度。
6. 使用 Arco Design React `Result` 展示成功或失败结果；成功时提供首页、后台、管理员信息及单项/全部复制。

管理员账号为 6 至 32 位，必须以英文字母开头，且只能包含英文字母、数字和下划线。管理员密码至少 8 位，并同时包含大写字母、小写字母、数字和特殊字符。密码只以哈希形式进入数据库；明文密码仅存在于当前安装页面的表单状态中，不写入 `.env`、安装摘要或服务端响应。安装记录会保存用户同意的软件使用协议版本和同意时间，便于后续审计。

仓库内置的软件使用协议是产品初稿，正式对外发布前应由宁波皓量云擎网络科技有限公司的法务或受托律师结合实际销售模式、商业版授权和隐私政策完成定稿。

## 安全与恢复

- 安装写接口要求同源 Session 与 256 位安装令牌。
- `runtime/install/operation.lock` 保证并发阶段串行执行，`active.json` 阻止不同浏览器会话同时安装，活动锁 30 分钟过期。
- 服务端会记录已完成阶段并按阶段锁定配置：数据库步骤成功后锁定 MySQL/Redis，系统数据步骤成功后再锁定网站与管理员信息；既禁止跳过步骤执行最终安装，也允许失败阶段在尚未落库前修正配置。
- 首次安装和后续升级共用 `database/migrations/` 中的 Phinx 核心迁移；基础迁移能够接管当前旧安装器已创建的表，系统初始数据使用幂等写入。
- `.env` 通过同目录临时文件原子替换并设置为 `0600`。只有所有步骤完成后才写入 `APP_INSTALLED=true`。
- 安装页启用仅允许同源资源的 CSP；HTTPS 站点会自动写入安全 Session Cookie 配置。
- 安装完成会尝试安排 Webman 平滑重载；若运行环境不支持信号，需由运维手动执行 `php start.php reload`。
- Web 服务器的站点根目录必须指向 `backend/public/`，不得直接暴露 `backend/`。

### 官方插件依赖缺失后的恢复

仅安装 `backend/` 根目录的 Composer 依赖不会安装插件各自的 `vendor/`。例如日志确认微信支付 `wechat_native` 缺少依赖时，在与服务器 PHP 版本及扩展匹配的构建或运维环境中，从仓库根目录按该插件现有 `composer.lock` 安装：

```bash
composer install --working-dir=backend/plugins/payment/wechat_native --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins
```

将 `backend/plugins/payment/wechat_native/` 完整上传到对应位置，包含原有插件文件、`composer.json`、`composer.lock` 和生成的生产 `vendor/`；不要只补一个 SDK 文件，也不要使用 `composer update` 改变锁定版本。其他随包官方通道同样需要完成下文的依赖构建。

保留安装过程中已创建的表和 `migration_versions`，先备份并依据安装日志、实际迁移记录核对失败位置，再在原安装会话重试失败阶段。不要清库、反复导入 SQL、伪造迁移版本或跳过尚未完成的迁移。未完成的安装继续由安装器推进；已经安装的系统按[数据库迁移指南](database-migrations.md)处理。

数据库初始化异常会提示核对安装日志，不再将所有失败归为数据库权限问题。默认日志位于 `backend/runtime/logs/webman-YYYY-MM-DD.log`，可按失败时间及接口返回的 `request_id` 定位异常链；分享日志前移除密码、令牌、连接串和 SQL 中的敏感数据。

## 发行包许可证

仓库及本项目官方插件的原创代码采用 Apache-2.0。仅交付 `backend/` 时，保留其中的 `LICENSE`、`NOTICE` 与 `MIT-LICENSE.txt`：前两项为项目许可与通知，后一项为 Webman 基础代码原始 MIT 声明。依赖和素材继续保留各自许可证。安装使用协议版本为 1.1，新安装记录该版本；已有安装的历史同意记录不回填。

官方插件的许可证字段变化属于包内容变化，已登记插件仍通过原有 drain / upgrade / enable 流程审核；在途交易继续使用原审核快照及归档包，不因许可证更新跳过包完整性或引用检查。

## 本地依赖与构建产物

安装器使用 React 19、TypeScript、Vite 和固定版本 Arco Design React 2.66.16。按照 [Arco Design React 快速开始](https://arco.design/react/docs/start) 引入组件与 `@arco-design/web-react/dist/css/arco.css`，中文配置由 `ConfigProvider` 提供。入口加载官方 `react-19-adapter`，保证 React 19 下的消息提示与浮层兼容。

React、Arco 组件及 CSS 均由 Vite 本地打包到 `backend/public/install-assets/app/`，浏览器运行期间不会访问 CDN，也不再使用独立的 Ant Design 样式文件。环境检测同时检查入口 HTML、JavaScript 和 CSS。Arco 的 MIT 许可证保留在 `frontend/install/public/ARCO_DESIGN_LICENSE`，构建时一并复制到发行包的 `backend/public/install-assets/app/ARCO_DESIGN_LICENSE`。

安装流程保持六步：协议、检测、数据库、系统、安装、结果。使用 Arco `Steps`、`Form`、`Table`、`Progress`、`Result` 和 `Descriptions`；步骤条从 1 开始计数。表单使用点号字段路径，并通过公开的 `getFields()` 收集完整配置，保留前面步骤已卸载的字段。连接参数发生变化后必须重新测试。密码仅在当前页面内存中暂存，不写入浏览器持久存储。

安装器保留 `#165DFF` 品牌色和 `2px` 矩形圆角，通过官方 CSS 变量设置圆角；窄屏隐藏品牌侧栏、精简检测列并将结果描述列表降为一列。运营后台和会员控制台分别构建到 `backend/public/admin/` 与 `backend/public/console/`。

已安装的系统继续阻止安装接口。UI 验收应在独立安装测试环境或隔离模拟接口进行，不得为预览界面清除现有安装标记。

数据库结构和系统版本的后续更新流程见 [数据库迁移与系统版本更新规范](database-migrations.md)。

从仓库根目录依次执行：

```bash
pnpm --dir frontend/install install --frozen-lockfile
pnpm --dir frontend/install lint
pnpm --dir frontend/install build

pnpm --dir frontend/admin install --frozen-lockfile
pnpm --dir frontend/admin lint
pnpm --dir frontend/admin build

pnpm --dir frontend/console install --frozen-lockfile
pnpm --dir frontend/console lint
pnpm --dir frontend/console build
```

构建结果需要纳入发行包。安装器依赖文件升级时，必须同时更新版本锁定、许可证文件并重新完成浏览器验收。

## 后端验证

```bash
# 从仓库根目录安装宿主开发依赖与插件生产依赖
composer install --working-dir=backend
for manifest in backend/plugins/*/*/composer.json; do
  composer install --working-dir="$(dirname "$manifest")" --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins || exit 1
done

cd backend
composer validate --no-check-publish
find app config support tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
php webman route:list
```

完成验证后，在独立发行目录从仓库根目录安装宿主及所有插件的生产依赖：

```bash
composer install --working-dir=backend --no-dev --classmap-authoritative --prefer-dist --no-interaction
for manifest in backend/plugins/*/*/composer.json; do
  composer install --working-dir="$(dirname "$manifest")" --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins || exit 1
done
```

宿主与插件是独立的 Composer 项目；根目录安装成功不代表插件依赖齐全。循环覆盖发行包中所有存在 `composer.json` 的官方通道，不能只构建当前计划启用的插件。插件 Composer 失败必须停止打包，不使用 `--ignore-platform-reqs` 绕过平台检查，也不执行 `composer update` 改动锁文件。需要单独修复 SMTP 时，同样在仓库根目录执行：

```bash
composer install --working-dir=backend/plugins/mail/smtp --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins
composer validate --working-dir=backend/plugins/mail/smtp --strict
```

发行包必须携带宿主生产依赖，以及所有随包官方通道各自的 `composer.json`、`composer.lock` 和完整生产 `vendor/`。Git 忽略 `vendor/` 不代表发行包可以省略；缺失、锁定版本不一致或混入开发依赖会被安装前检查拒绝。该步骤在开发机、构建流水线或运维环境完成，客户安装器不执行插件 Composer。

用于上传的发行包不应包含开发机的 `.env`、`runtime/` 内容、日志、测试数据库凭据或其他敏感数据。首次启动前确保 PHP 进程用户能创建/写入 `.env` 与 `runtime/`，安装结束后可收紧 `.env` 权限。

## 安装后修改后台入口

- 系统设置的安全设置提供「后台访问路径」，独立于登录策略保存；只读账号只能查看，修改需要 `admin.page.settings.view` 和 `admin.page.settings.update`。
- `GET /api/admin/v1/settings/admin-entry` 读取实际生效路径，`PUT` 提交 `admin_path` 和上次读取的 `current_admin_path`，要求同源 Origin。
- 路径只接受3–32位小写字母、数字及连字符，以字母开头。系统保留路径、宿主业务路由、公开资源和已安装主题页面冲突会拒绝；冲突及失败保留输入。
- 使用安装文件锁和同目录原子替换，仅更新 `.env` 中的 `ADMIN_PATH`，不复制后台目录、不修改其余配置，不需要重启。安装数据表中的路径是首次安装记录，保留历史值。
- 保存成功后进入新后台设置页，原入口不再可用；API 保持 `/api/admin/v1`，静态资源仍位于 `/admin/assets`，管理员会话保持原有效期。
- 本地 Vite 开发入口固定 `/admin`，因此修改后跳转到后端实际入口；生产环境按当前同源新入口跳转。
