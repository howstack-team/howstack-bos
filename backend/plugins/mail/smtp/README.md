# SMTP 邮件插件 v1

免费内置邮件通道，插件通过自身 Composer 依赖 `phpmailer/phpmailer` 实现 SMTP 发送。

在后台“系统管理 → 通道管理 → 邮件通道”新建 SMTP 配置，保存后测试发送。支持SSL/TLS、STARTTLS、可信网络不加密连接、账号密码/授权码认证及无认证邮件中继。证书必须通过宿主信任库校验。

开发邮件插件只需三项：`plugin.json` 声明身份和兼容范围，`configuration.schema.json` 声明后台配置字段，`MailAdapter.php` 实现 `validateConfiguration(Configuration)` 和 `send(MailMessage, Configuration)`。`app/` 是本插件可选的内部辅助代码，本包在此放置 SMTP 配置校验和 DATA 阶段跟踪。

发送方法接收当前通道配置、收件人、主题和正文，返回 `MailResult`。SMTP 连接、认证、TLS 和 MIME 处理均在插件内完成；宿主负责表单、密码加密、权限、启停、发送记录及幂等。插件不访问核心数据库或路由，构造和配置校验不访问网络，不跨请求保存凭据。SMTP 超时可能已经接收邮件，`unknown` 结果不自动重发。

安装、配置、安全边界与调用示例见仓库 `docs/development/channel-management.md`。插件自身为Apache-2.0；PHPMailer 为 LGPL-2.1，随插件本地 Composer 依赖保留原许可证。

## 插件依赖构建

在本插件目录执行：

```bash
composer install --no-dev --no-scripts --no-plugins --prefer-dist --optimize-autoloader
composer validate --strict
```

`composer.json` 和 `composer.lock` 纳入源码；`vendor/` 由 Composer 生成，Git 忽略，但发行包必须包含。运行时宿主验证并加载本目录 `vendor/autoload.php`，不需要插件手动引入或修改主项目 Composer。PHPMailer 已从宿主依赖移出。依赖或文件变更需要审核升级插件并完整重启 Webman。
