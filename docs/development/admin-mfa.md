# 运营后台两步验证

## 使用与交付范围

管理员右上角头像 → 个人设置 → 两步验证。仅运营后台接入，不改变会员认证。默认未开启，不自动为任何已有账号强制绑定。创始管理员自动拥有权限；其他管理员需通过角色授予 `admin.profile.view` 与 `admin.profile.mfa`，后者仅允许管理本人，不能为其他账号关闭验证器。

支持常见验证器的 TOTP（RFC6238、SHA1、6位、30秒），二维码由本地 `qrcode` 生成，密钥和配置URI不发送到外部二维码服务。可手动输入Base32密钥。后端使用MIT许可证的 `spomky-labs/otphp` 11.5；前端QR库与其间接依赖MIT许可证随构建附带 `licenses-mfa.json`。本轮没有“信任设备免验证”、短信替代MFA、Passkey、组织强制策略或会员MFA。

## 绑定、关闭与恢复

1. 验证当前密码生成待绑定密钥；10分钟内输入验证器验证码确认。生成二维码不等于开启，关闭弹窗不会启用MFA。
2. 确认绑定时生成10组各128位随机恢复码。明文仅返回一次，可主动下载离线保存；数据库只存SHA256摘要。关闭后不可再次查询明文。
3. 登录时先校验密码，再输入验证器验证码；无法使用验证器时可改用未使用的恢复码。单独持有恢复码不能跳过密码。
4. 关闭MFA、重置恢复码都要求当前密码及有效的TOTP或恢复码。重置后旧恢复码全部作废；每组恢复码消费一次即失效。
5. 绑定、关闭、重置恢复码递增管理员认证修订，令所有旧JWT和未完成挑战失效；为当前浏览器签发新的会话Cookie，其他浏览器需要重新登录。修改密码同样使旧挑战失效。

绑定确认也会消费当时的时间步。如立即换浏览器登录，请使用下一组动态码或恢复码；已使用过的动态码不能重复验证。验证器和服务器应保持时间同步，允许前后各一个时间步偏差；最后已消费时间步只前进不倒退。

若验证器与全部恢复码同时丢失，本轮没有自助绕过、客服HTTP强制关闭或只靠密码重置MFA的入口。平台需制定经过身份核实的受控运维恢复流程；不得直接公开修改MFA数据库的接口。

## 登录契约与保护

`POST /api/admin/v1/auth/login`：

- 未开启MFA时，返回 `mfa_required=false`、用户、权限、过期时间，并设置完整HttpOnly管理员JWT Cookie。
- 开启MFA时，只返回 `mfa_required=true` 和第二步截止Unix秒。清除旧访问Cookie，仅设置5分钟、Strict、HttpOnly且路径限于 `/api/admin/v1/auth` 的临时挑战Cookie。
- `POST /api/admin/v1/auth/mfa/verify` 读取挑战Cookie，接收 `code` 和布尔 `recovery`。验证通过且挑战原密码版本、认证修订和账号状态仍有效，才签发完整登录凭证并记录成功登录。

临时挑战不是JWT，AdminAuthMiddleware不接受它。数据库只保存随机挑战的SHA256摘要；每账号最多一笔当前挑战，新建会撤销旧挑战，成功立即消费。挑战最多5次验证失败；IP采用已有后台登录限制，账号额外限制每5分钟创建5次挑战、校验10次，避免跨IP重建挑战绕过限制。管理操作每账号5分钟最多10次，Redis不可用时拒绝继续，不降级为无限尝试。

所有写接口要求同源Origin；设置与恢复码响应 `Cache-Control: no-store`。不得缓存到localStorage/sessionStorage、URL、全局状态或日志。前端字段校验失败不发送请求，接口字段错误只映射到Form.Item，其他业务错误使用一次Message。

## 目录与持久化

- `app/modules/admin/auth/`：MFA用例、固定算法契约、行锁仓储、登录和管理HTTP入口，遵循现有模块边界。
- `admin_mfa_credentials`：加密的已启用/待绑定密钥、绑定有效期、最后消费时间步、恢复码摘要。
- `admin_mfa_challenges`：临时Cookie摘要、管理员及凭据版本、错误次数、记住登录选项和截止时间。每账号重新发起时清理旧挑战，记录数量受管理员数量约束。
- `admin_users.auth_version`：认证修订，纳入JWT凭据证明；不是对外ID或权限字段。
- `frontend/admin/src/components/AdminMfaSettings.tsx`：本人验证器管理与一次性恢复码展示。
- `contracts/openapi/admin-mfa.v1.yaml` 和基础登录契约：完整接口结构，运行 `tools/api-docs` 的build/check同步发行文档。

固定锁顺序为管理员 → MFA凭据 → 挑战。TOTP计数器和恢复码在同一事务内校验、消费；并发请求至多一个成功。错误次数在事务成功提交后再抛业务错误，不能因异常回滚而恢复尝试额度。失败MFA写入登录日志，成功仅在完成两步后写入；管理操作由现有操作日志链保存IP和位置快照，参数白名单不保存密码、验证码、绑定密钥或恢复码。

## 升级与验收

新增前向迁移 `20261015000400_create_admin_mfa.php`。部署前备份数据库，再执行 `php webman system:migrate --force` 并完整重启Webman。现有账号保持未启用MFA；JWT凭据证明增加认证修订字段后，旧登录凭证需重新登录。

- MySQL：`HOWSTACK_MYSQL_TEST=1 vendor/bin/phpunit tests/integration/AdminMfaWorkflowTest.php`，验证真实迁移、中文注释、凭据加密、挑战/时间步/恢复码防重放、过期、改密、停用、修订撤销和多进程竞争。
- 真实HTTP：`HOWSTACK_ADMIN_MFA_HTTP_ACCEPTANCE=1 php tests/acceptance/admin-mfa-http.php`，仅本机开发库，自动创建并清理临时管理员，不绑定实际员工；验证完整Cookie、第二步隔离、权限、Origin、恢复码及请求追踪信封。
- 浏览器：`HOWSTACK_PLAYWRIGHT_MODULE=<Playwright模块> node frontend/admin/tests/mfa-browser.mjs`，模拟API检查登录两步、绑定表单、字段反馈、二维码、本地不持久化秘密、390/320px及遮罩关闭。
- 上线前需用实际手机验证器扫描绑定，并验证时间同步、恢复码离线保管及应急运维流程。浏览器模拟不代表真实手机验证器已验收。
