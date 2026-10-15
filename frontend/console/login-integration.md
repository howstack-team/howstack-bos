# 会员认证接入

PC与移动端使用同一套 `/api/console/v1/auth` 后端，凭据仅存于HttpOnly Cookie。接口唯一契约位于 `contracts/openapi/console-auth.v1.yaml` 与 `console-finance.v1.yaml`，实现及部署规则见 `docs/development/member-registration.md`。

| 接口 | 用途 |
| --- | --- |
| GET `/options` | 查询实际登录/找回能力，建立验证码浏览器绑定 |
| POST `/login` | `account/password/remember_me` 密码登录 |
| POST `/login-code` | `mobile` 获取短信登录挑战 |
| POST `/login-sms` | `mobile/code/challenge_id/remember_me` 验证码登录 |
| POST `/password-reset-code` | `account` 获取手机或邮箱重置挑战 |
| POST `/password-reset` | `account/code/challenge_id/password` 重置密码并撤销全部会员会话 |
| GET `/me` | 当前会员资料，401表示匿名 |
| POST `/logout` | 撤销当前会话并清除Cookie |

手机号或邮箱注册配置与独立注册挑战保持原契约。后台关闭注册不应阻止已注册会员登录和找回密码。

短信登录/找回入口按后端模板与通道能力接入。QQ、微信入口根据 `oauth_providers` 实际通道能力展示；不得从客户端强制开启。授权URL只允许对应官方HTTPS域名，首次授权进入平台账号关联流程。

输入字段先使用Arco原生校验，通过后才调用写API；验证码挑战只存内存并绑定账号与用途。422字段错误就地展示，其他业务/网络错误使用PC Message或移动Toast一次反馈。重置后不自动登录，不删除当前浏览器其他会员的Cookie。

本人缓存统一使用 `console` 前缀。会话变化取消在途请求、清理缓存并挂载新身份页面；跨标签页只发送随机变更事件。切换账号后的迟到数据及迟到401均不能污染新会话。匿名购买选配位于公共查询和购买草稿边界，登录后保留选配，不携带前一个会员的资金或订单数据。

## 第三方授权结果

`completeOAuth`返回判别联合：

```ts
type OAuthCompletion =
  | { status: 'authenticated'; user: MemberProfile }
  | { status: 'binding_required'; provider: 'qq' | 'wechat'; expires_in: number }
```

已关联账号调用 `establishSession(user)`，会再读取真实Cookie身份并清理跨账号缓存。首次授权跳转 `/oauth-bind`，PC和Mobile分别使用各自原生表单，业务流程通过 `useOAuthBinding` 复用。`getOAuthPending`支持刷新继续，私有票据由服务端HttpOnly Cookie管理。账号关联不得由客户端传入member_id，也不能只凭页面展示的昵称创建身份。

`bindOAuth(account, password)`重新验证平台账号后登录；新用户点击注册，原注册页在明确的 `oauthBinding: true` 路由状态下成功返回关联页，不将注册密码存入路由、存储或查询参数。关联完成恢复经过 `loginReturnPath` 校验的原路径。配置及真实平台联调要求见 `docs/development/member-registration.md`，微信网站应用扫码与公众号内授权是不同的能力。
