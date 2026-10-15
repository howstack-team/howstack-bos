# 会员注册与公开编号

## 运营配置

「系统管理 → 系统设置 → 注册与登录」统一承载会员注册和登录相关配置。目前分别设置：手机号注册、邮箱注册、手机号验证码验证、邮箱验证码验证。两种注册方式可独立或同时开启，全部关闭则不开放自助注册。关闭某种注册方式不禁止其既有账号登录。

默认开启手机号注册并要求短信验证码；邮箱注册默认关闭，其验证码要求默认开启。选择需要验证时，在「消息配置 → 发送给客户 → 注册验证码」启用短信或邮件、配置模板并引用已配置且启用的通道。模板必须包含 `{{code}}`，也可使用 `{{expires_in}}`。未配置可用通道时不绕过验证，返回可操作的错误提示。

客户入口 `/console/register` 按实时设置显示手机号/邮箱注册方式。验证码关闭时仅需账号和密码，并且该联系方式不标记为已验证。密码至少 8 个字符、最多 72 字节；注册成功返回登录页。当前登录使用手机号或邮箱加密码，不新增邮箱登录验证码或找回密码流程。

## 公开会员 ID 与排序

- `members.id` 仍为内部自增主键，订单、钱包和服务外键保持不变。
- `members.public_id` 新生成编号为固定 **9 位纯数字**，范围 `100000000–999999999`，使用密码学随机源生成，数据库唯一索引防止重复；碰撞时重新生成，最多尝试五次。它只表示身份，不表示会员规模或注册先后。
- 本人资料及新会员令牌使用公开编号，不向客户返回内部会员主键。后台操作仍传内部主键，展示会员 ID 时使用公开编号。
- 会员列表默认按 `created_at DESC, id DESC` 排序。表头可按注册时间、最近登录时间排序；不按随机公开编号推断注册顺序。
- 迁移给既有会员补充公开编号，保留所有内部主键及业务关系；编辑联系方式、密码或资料不得修改公开编号。
- 会员JWT使用公开编号subject，不能以内部主键或未登记的旧JWT恢复会话。认证会话迁移后现有会员需要重新登录；历史八位会员编号仍保留。
- 公开编号的新编号空间为九亿个；不能作为认证凭据或授权依据，仍按已认证内部主键执行归属校验。

## 验证码边界

注册验证码由独立即时服务发送，复用安装通道的固定邮件/短信执行接口，不经过普通通知队列及消息日志。只保存挑战标识、接收账号摘要、浏览器绑定摘要、验证码摘要、到期时间、次数与状态，不保存明文验证码或完整接收地址。

- 6 位数字验证码有效 300 秒，成功后单次消费，错误校验最多 5 次。
- 验证绑定注册方式、账号、场景和 HttpOnly 临时注册 Cookie；修改账号或切换方式后必须重新获取。
- Redis 限制：同一接收账号每 60 秒最多发送一次；同一 IP 每 600 秒最多 10 次验证码请求、30 次注册提交；Redis 不可用时停止注册写入。
- 同一浏览器同一账号重新发送后，旧挑战失效。厂商未知/失败结果不激活验证码，不自动重发。
- 注册写请求校验同源 Origin；公开配置读取建立临时 Cookie，并使用 no-store。
- 验证码消费与账号创建分别提交事务：如果消费成功后账号创建失败，需要重新获取验证码，已消费的验证码不会恢复。
- 验证码、密码及厂商原始异常不进入业务日志或返回 DTO。通道接受请求并不等于运营商最终送达。

## 升级与验证

前向迁移 `20261009000100` 将公开编号字段扩大至 `VARCHAR(9)`，保留已有八位编号及唯一索引，新创建会员使用九位编号；历史八位编号继续支持资料读取和登录。仅扩大字段，不对既有会员重新编号。历史迁移 `20261008000500` 新增注册配置、验证记录表和会员字段。执行前备份数据库；迁移后完整重启 Webman。新增表字段包含中文注释。未执行迁移时注册配置读取失败，不开放注册。

API 定义见 `contracts/openapi/console-finance.v1.yaml` 与 `admin-settings.v1.yaml`。本人资料 `data.user.id` 改为公开编号字符串，增加 `email`，`mobile` 可为 null。依赖旧数字类型的自定义客户端需同步更新；密码登录只接受当前 `account` 参数；手机号验证码登录单独使用 `mobile`。

集成测试使用实际迁移及隔离数据库，短信/邮件运输和 Redis 频率限制使用契约替身；浏览器模拟用于检查交互及布局。真实邮件、短信送达需使用运营者配置的有效通道另行验收。


## 登录与找回密码后端

- `GET /api/console/v1/auth/options` 返回实际可用的短信登录、手机号/邮箱找回能力并建立30分钟的HttpOnly浏览器绑定Cookie。后台必须配置并启用对应的“登录验证码”“找回密码”消息模板及短信/邮件通道；只安装插件不代表通道可用。
- `POST /auth/login-code` → `POST /auth/login-sms`：前者提交 `mobile`，后者提交 `mobile/code/challenge_id/remember_me`。验证码登录只支持短信；邮箱使用密码登录。
- `POST /auth/password-reset-code` → `POST /auth/password-reset`：提交 `account` 获取挑战，提交 `account/code/challenge_id/password` 完成重置。前端确认密码不进入API。
- 以上路径均位于 `/api/console/v1`。写请求验证同源Origin。登录、找回与注册挑战互相隔离，不能跨账号、浏览器、用途或密码版本使用；有效300秒，错误最多5次，重发后原挑战失效。
- 未注册及停用账号使用相同的验证码投递路径、响应结构与错误处理，但即使拿到验证码也不能登录、重置或自动创建账号。投递被通道接受不代表实际送达，未知结果不自动重发。验证码不写普通通知日志，只保存HMAC摘要。
- 普通登录会话默认2小时；`remember_me=true`最长30天，签发对应的持久Cookie。服务端JWT有公开编号、用途、受众、密码版本与随机会话标识；`member_sessions`只保存会话标识摘要及许可，不保存原始JWT。
- 退出先撤销当前会话许可，再删除Cookie；复制的旧Cookie不能再次访问。重置密码时，验证码消费、密码更新和全部会话删除在同一数据库事务内提交；其他会员及后台Cookie不被清除。密码变化也使旧挑战失效。
- 第三方会员登录已接入QQ、微信通道：`oauth_providers`只返回已启用且配置有效的通道；首次授权必须验证平台账号后关联，不按邮箱、昵称或UnionID自动合并会员。
- PC的接口业务反馈使用Message，移动端使用Toast；422的 `data.errors` 映射到当前字段，不重复全局弹提示。原生字段校验失败不发送写请求。验证码、密码及会话令牌不放入浏览器持久存储。
- 会话变化时清理本人查询及页面状态，取消旧请求并丢弃迟到响应；跨标签页只广播事件，不广播会员资料或凭据。焦点恢复时重新确认身份，覆盖PC/移动开发端口无法直接共享BroadcastChannel的情况。

### 迁移与验收

前向迁移 `20261015000100` 新增 `member_sessions`、`member_auth_challenges`；不删除会员、订单、资金及历史注册验证码表。备份数据库后执行 `php webman system:migrate --force` 并完整重启。旧会员JWT不在新会话许可表中，升级后需要重新登录。

完整接口结构见 `contracts/openapi/console-auth.v1.yaml`。隔离SQLite/MySQL集成测试使用真实迁移和ORM事务，外部运输及限流使用替身。本地可显式执行 `HOWSTACK_AUTH_HTTP_ACCEPTANCE=1 php tests/acceptance/member-auth-http.php`：该命令拒绝生产环境，仅使用loopback HTTP，创建并清理临时会员，人工植入独立测试挑战，不发送真实消息；用于验证HTTP输入、JWT、会话撤销及重置持久化，不证明运营商投递。上线前仍需用有效的通道、已审核模板和实际PC/手机浏览器验收投递及用户流程。

所有新密码写入入口（注册、找回、后台会员新建及改密）均要求至少8个字符且最多72个UTF-8字节，密码登录不接受超过72字节的输入，避免bcrypt截断产生等价密码。后台修改历史会员不填写密码时保留原哈希；无法从哈希推断历史密码原始长度，不批量重设或截断历史凭据。此前保存的超长密码应通过验证码找回或管理员明确重设为当前规则范围。

## 第三方登录与首次账号关联

PC 和独立移动端使用同一套核心接口，配置入口为后台“第三方登录通道”。先安装并启用QQ或微信插件，填写应用ID、密钥以及已通过平台审核的固定HTTPS回调，例如 `https://member.example.com/console/login`。该地址必须真正承载会员SPA；不要填写后台登录、API接口或只有静态首页的地址。开发环境的HTTP内网地址不能直接替代平台要求的HTTPS回调。

| 接口 | 职责 |
| --- | --- |
| `GET /api/console/v1/auth/options` | 建立/续期浏览器绑定Cookie，并返回已启用的QQ、微信登录方式 |
| `POST /api/console/v1/auth/oauth/start` | 使用服务器通道配置产生授权链接，接收provider及remember_me，不接收任意回调地址 |
| `POST /api/console/v1/auth/oauth/complete` | 一次消费state后交换授权码；返回authenticated或binding_required |
| `GET /api/console/v1/auth/oauth/pending` | 通过HttpOnly Cookie读取待关联的平台和剩余时间，支持页面刷新 |
| `POST /api/console/v1/auth/oauth/bind` | 重新验证平台账号密码，原子建立关联并消费票据，随后建立可撤销会话 |

授权state有效期300秒，与原浏览器、通道ID/修订和记住登录选择绑定，跨浏览器不能消费。后台调整、停用或升级通道使尚未完成的授权/关联失效。已关联会员仍需要通过新的上游授权取得身份，不以本地昵称等展示数据替代平台验证；会员停用禁止登录。

首次授权不会自动创建无手机号/邮箱的账号。用户在 `/console/oauth-bind` 输入已有平台账号和密码；新用户可跳转原注册流程，遵循后台设置的手机号/邮箱与验证码要求，注册成功后返回关联页。原购买配置继续由购买草稿机制保留，原返回地址仅保存在sessionStorage或路由状态且限制在控制台内部。密码、验证码、上游令牌、关联票据均不进入URL、localStorage或sessionStorage。

`member_oauth_identities`以区分大小写的 `(provider, app_id, subject)` 唯一关联会员，同一个会员在同一应用只能关联一个第三方身份。不同应用、不同平台不按UnionID隐式合并。`member_oauth_pending`只保存已验证身份和十分钟随机票据摘要，不保存上游access_token、refresh_token或原始授权码。关联票据通过HttpOnly Cookie传递，错误密码最多尝试五次；关联与票据消费同一事务完成，重复或冲突关联拒绝。过期待关联记录在新授权时清理。

所有OAuth写接口同时检查同源Origin并使用会员独立登录限流。返回禁止缓存；成功JSON只有状态及会员安全资料，JWT只写HttpOnly Cookie。PC接口反馈使用Arco Message，Mobile使用Toast；可映射的字段错误只在字段显示，前端校验失败不请求后端。回调查询参数在开始交换前立即从地址栏移除。

### 移动端与平台协议

QQ使用其官方H5授权界面（`display=mobile`）；[QQ官方授权说明](https://wiki.connect.qq.com/使用authorization_code获取access_token)明确说明移动浏览器的展示参数。现有微信插件使用**网站应用扫码登录**（`snsapi_login`），PC与移动浏览器共用网站应用身份范围；公众号内直接授权属于不同的公众号网页授权能力，不会伪装成网站扫码协议。微信内直接授权需要另行对接公众号应用及其scope，不能通过前端替换URL绕过应用类型限制。[腾讯官方微信授权类型说明](https://cloud.tencent.com/document/product/1441/68675)区分网站应用、公众号和移动App。

多个同平台通道按后台排序使用首个有效通道，本次授权固定原通道和修订，之后排序变化不会将回调交换到另一个应用。不要随意更换appid，否则原关联不会自动迁移。上游真实授权必须使用商户自己的应用、审核通过的HTTPS域名和用户同意，测试替身不代表QQ/微信线上验收。

### 验证与迁移

新增前向迁移 `20261015000200_create_member_oauth_identities.php`，上线升级前先备份数据库，再通过 `php webman system:migrate --force` 执行；不修改已执行迁移、不改已有账号资料和订单外键。新增表与字段均含中文注释。

- `HOWSTACK_MYSQL_TEST=1 vendor/bin/phpunit tests/integration/MemberOAuthAuthenticationTest.php tests/integration/MemberOAuthMigrationTest.php tests/integration/OAuthChannelsTest.php`：独立MySQL测试库、ORM唯一约束、JWT，以及生产Redis一次消费；上游HTTP协议使用受控回复。
- `HOWSTACK_PLAYWRIGHT_MODULE=<Playwright模块> node frontend/console/tests/oauth-binding-browser.mjs`：PC/Mobile回调、首次关联、刷新、字段反馈、注册返回、原业务返回路径及过期处理；接口为模拟回复。
- `HOWSTACK_OAUTH_HTTP_ACCEPTANCE=1 php tests/acceptance/member-oauth-http.php`（backend目录）：本机开发真实HTTP授权入口、Origin拒绝、非法state、待关联、密码验证、Cookie身份及退出；临时账号/通道在finally清理，上游调用0次。
- 真实QQ/微信用户授权及HTTPS生产回调需另外执行平台联调验收。
