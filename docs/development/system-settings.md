# 系统设置

## 数据结构与界面边界

系统设置使用两张表，物理表名前缀由 `DB_PREFIX` 决定：

| 逻辑表 | 职责 | 关键字段 |
| --- | --- | --- |
| `system_setting_categories` | 设置分类与 Tab 顺序 | `id`、唯一 `code`、`name`、`sort_order`、时间戳 |
| `system_settings` | 按稳定键保存设置值 | 唯一 `setting_key`、`setting_value`、可空 `category_id`、`is_public`、时间戳 |

当前分类为 `base`（基础设置，排序 10）和 `security`（安全设置，排序 20）。分类接口按排序、ID 返回这两个已实现的分类。

后台使用 Arco Tabs 和固定编写的表单。数据库提供分类信息和表单值，不提供字段定义、控件类型、验证规则或可执行表单描述。新增分类需要同步实现前端表单、后端校验、权限评估和迁移；直接插入分类记录不会自动生成界面。

`admin.path`、`legal.*` 等内部设置保留在原设置项表中，`category_id` 为 `null`，不通过这组后台接口开放。接口只接受下表中的固定字段，不接受任意数据库键或额外字段。`is_public` 是既有数据标记，本接口仍全部需要后台认证，不能依此标记绕过权限。

本次基础设置实现持久化、读取与后台编辑；尚未把这些值接入官网、运营后台和会员控制台的全部品牌展示位置。

## 固定表单字段

字符串保存前去除首尾空白。PUT 保存整个分类，所有字段都必须出现；可选文本用空字符串表示，不传 `null`。

| 分类 | API 字段 | 数据库键 | 类型与范围 |
| --- | --- | --- | --- |
| 基础设置 | `site_title` | `site.title` | 字符串，必填，2–120 字符 |
| 基础设置 | `site_keywords` | `site.keywords` | 字符串，可空，最多 255 字符 |
| 基础设置 | `site_description` | `site.description` | 字符串，可空，最多 500 字符 |
| 基础设置 | `site_url` | `site.url` | 字符串，必填，HTTP/HTTPS URL，最多 255 字符 |
| 基础设置 | `copyright` | `site.copyright` | 字符串，可空，最多 200 字符 |
| 基础设置 | `icp_record` | `site.icp_record` | 字符串，可空，最多 100 字符 |
| 基础设置 | `public_security_record` | `site.public_security_record` | 字符串，可空，最多 100 字符 |
| 基础设置 | `business_license` | `site.business_license` | 字符串，可空，最多 100 字符 |
| 安全设置 | `session_expire` | `security.session_expire` | JSON 整数，5–1440，单位分钟 |
| 安全设置 | `login_attempts` | `security.login_attempts` | JSON 整数，1–100，窗口内每 IP 最多尝试次数 |
| 安全设置 | `login_window` | `security.login_window` | JSON 整数，10–3600，单位秒 |

安全设置使用 `integer:strict` 验证，拒绝数字字符串、布尔值和浮点数；前端应直接发送范围内的整数，不能把 `"10"`、`true` 或 `10.0` 当成整数提交。服务端将已验证值以文本写入设置表，读取后返回原生整数。

## API 与权限

版本契约见 `contracts/openapi/admin-settings.v1.yaml`。

| 方法 | 路径 | 权限 |
| --- | --- | --- |
| GET | `/api/admin/v1/settings/categories` | `admin.page.settings.view` |
| GET | `/api/admin/v1/settings/base` | `admin.page.settings.view` |
| PUT | `/api/admin/v1/settings/base` | 同时具有 `view` 和 `admin.page.settings.update` |
| GET | `/api/admin/v1/settings/security` | `admin.page.settings.view` |
| PUT | `/api/admin/v1/settings/security` | 同时具有 `view` 和 `admin.page.settings.update` |

沿用后台 HttpOnly Cookie 或 Bearer JWT 认证与创始管理员权限规则。其他分类返回 422，不开放分类或设置键的通用 CRUD。PUT 在事务中锁定分类并保存完整表单，保留既有设置的公开标记；只读管理员可查看，不能保存。

所有响应都包含 `code`、`message`、`data`、`request_id`，并返回 `X-Request-Id` 响应头。成功 `code` 为 0。字段错误使用 HTTP 422，`data.errors` 是字段名到错误文字数组的映射；没有字段错误数据的失败响应将 `data` 设为 `null`。

## 安全设置何时生效

- 登录过程实时读取数据库安全设置，常驻 Worker 不缓存这组可变配置。安全配置缺失、损坏或读取失败会阻止登录，不能静默放行。
- `session_expire` 控制新签发后台 JWT 的有效期。保存后新登录立即使用新值；已有 JWT 继续按自身 `exp` 校验，不会被此次设置操作自动注销或延期。
- 登录频率通过官方 `support\limiter\Limiter::check()` 计数，Redis 驱动共享多 Worker、多实例计数。同一安装按统一客户端 IP 解析服务的结果限流，不无条件信任转发 Header。
- 使用固定时间窗口，成功登录、密码错误及非法输入等所有到达登录中间件的尝试都会计数。这是每 IP 的登录频率限制，不是连续失败锁定账号；同一出口 IP 的用户共用窗口。
- 限流参数在下一次登录尝试读取。`Limiter::check()` 不使用注解的本机 IP 白名单。超限返回统一 HTTP 429，`Retry-After` 使用完整窗口秒数作为保守等待上界；Redis 不可用时返回 503。
- JWT 签名密钥、Cookie 安全属性、受信任代理及 Redis 连接仍由部署配置管理，不加入这两个表单。

## 迁移与部署

分类迁移为 `20260926000500_add_system_setting_categories.php`，基础字段扩展迁移为 `20260926000600_expand_base_system_settings.php`。分类迁移新增分类表及关联字段；扩展迁移将旧 `site.name` 在 `site.title` 为空时迁移为网站标题，随后删除 `site.name`，并补齐其余基础字段。内部设置不归类。仅缺失的设置才写入默认值：URL 取 `APP_URL`，其他可选文本为空；登录次数为 10，窗口为 60 秒。

系统正式名称为“皓量云擎云业务运营系统”。更名通过新增前向迁移 `20261001000100_rename_default_site_title.php` 完成，仅将仍为“皓量云擎业务管理系统”的默认 `site.title` 更新为新名称，保留客户自定义标题及其他设置。已执行的历史迁移保持不变；安装器默认网站标题同步使用新名称。

首次生成登录有效期时，优先采用合法正整数 `JWT_TTL` 秒数，向上换算成分钟并限制在 5–1440 分钟；未配置或无效时取 120 分钟。已有 `security.session_expire` 不会被迁移默认值覆盖。安装器使用同一套迁移，再将安装表单中的网站值写入基础分类。

在 `backend/` 目录核对环境、完成数据库备份后执行产品升级入口：

```bash
php webman system:migration-status
php webman system:migrate
php webman system:migration-status
php start.php reload
```

非交互部署只有完成备份后才使用 `php webman system:migrate --no-interaction --force`。不要使用直接 Phinx migrate 替代产品升级入口。代码包含新的 Attribute 路由、DI 及限流驱动配置，发布后需要 reload；确认 Redis 可访问后再验证登录及设置读写。迁移禁止通过 down 删除客户配置，故障采用前向修复；MySQL DDL 不保证随业务事务整体回滚。

生产 MySQL 建立 `category_id` 外键及索引。SQLite 测试库保留字段、索引和分类关联数据，但受当前 Phinx 0.16 前缀外键复核缺陷影响，不创建物理外键。因此 SQLite 迁移测试可验证初始化、旧值保留及重复执行，不能替代 MySQL 外键、并发锁和安装器集成验证。

## 本次验证范围

本次交付在本地 MySQL 备份后通过产品迁移入口执行，确认 10 个迁移无待执行项、基础设置 9 项、安全设置 3 项及分类外键。浏览器验证两个 Tab 保存、基础值刷新后保留及 390px 窄屏无横向溢出。Redis 通过 3 个独立 PHP 进程验证共享限流计数；这些结果不代表真实多节点集群压测或生产发布验证。

## 当前基础设置边界（2026-10-11）

基础设置只包含平台名称、网站地址、页脚版权、ICP备案、公安备案和经营许可证六项。平台副标题已从表单、接口和主题公开上下文移除，`20261011000200_remove_platform_subtitle.php` 删除 `system_settings` 中的 `site.subtitle` 设置记录；该项不是独立数据库列。公司资料在官网基础设置中维护；官网默认 SEO 和逐页 SEO 在“官网管理 → 页面 SEO”中维护，详见 `page-seo.md`。

默认主题升级到1.1.1并发布新模板快照，保留已有主题配置及其他平台设置。迁移不可逆，已执行的历史迁移保持不变；新安装执行全部前向迁移后同样不保留副标题。
