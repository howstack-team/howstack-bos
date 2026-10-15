# 官方通道插件接入与开发（v1）

短信、邮件额外提供可选的 [模板审核契约](message-template-review.md)：根目录入口可分别实现模板提交、审核查询接口。宿主通过实际接口识别能力，当前内置发送插件尚未接入厂商审核 API，不宣称支持审核，也不要求实现空方法。

本指南面向安装者和第三方开发者。插件负责平台协议；宿主负责配置加密、权限、启用状态、包摘要、修订与业务数据。全部沿用 v1（清单值 `1.0`）。官方 SDK 的版本号不等于插件协议版本。

## 1. 已实现的插件与后台入口

进入「系统管理 → 通道管理」选择对应分类。默认列表直接显示各插件，填写配置后启用或禁用，不再新增通道。历史连接身份保留；每次调用只传入本次配置，不共享账号、密钥和 SDK 客户端。保存配置只完成格式验证，不代表实际连通或业务成功。 短信和邮件可设置一个主用通道，文件存储可设置默认存储；支付、第三方登录、实名认证和人机验证由具体业务选择，不设统一默认。

| 分类 | 插件 ID | 平台 | 主要配置 |
| --- | --- | --- | --- |
| 短信通道 | `aliyun_sms` | 阿里云短信 | AccessKey、审核签名 |
| 短信通道 | `tencent_sms` | 腾讯云短信 | SecretId/Key、地域、短信应用 ID、审核签名 |
| 短信通道 | `submail_sms` | 赛邮短信 | APPID、APPKEY |
| 短信通道 | `smsbao` | 短信宝 | 账号、API Key |
| 邮件通道 | `aliyun_dm` | 阿里云邮件推送 | AccessKey、地域、验证过的发件邮箱、发件名称 |
| 邮件通道 | `tencent_ses` | 腾讯云邮件推送 | SecretId/Key、地域、验证过的发件邮箱、审核模板 ID |
| 实名认证 | `tencent_faceid` | 腾讯云人脸核身 | SecretId/Key、地域、业务 RuleId |
| 文件存储 | `aliyun_oss` | 阿里云 OSS PHP SDK V2 | AccessKey、地域、存储桶 |
| 文件存储 | `tencent_cos` | 腾讯云 COS | SecretId/Key、地域、带 APPID 后缀的存储桶 |
| 文件存储 | `qiniu_storage` | 七牛云存储 | AccessKey/SecretKey、地域、空间、HTTPS 下载域名 |
| 文件存储 | `local_storage` | 本地私有存储 | 逻辑存储分区，默认 `uploads` |
| 支付通道 | `wechat_native` | 微信 Native API v3 | AppID、商户号、商户私钥及序列号、平台公钥及 ID/序列号、API v3 密钥 |
| 第三方登录 | `wechat_login` | 微信开放平台网站登录 | 网站应用 AppID/AppSecret、固定 HTTPS 回调地址 |
| 第三方登录 | `qq_login` | QQ 网站登录 | 应用 ID/密钥、固定 HTTPS 回调地址 |

已有 SMTP、支付宝订单码支付、支付宝身份验证保留原实现。通道插件不注册任意路由、菜单、启动脚本或业务表迁移。

## 2. 安装与依赖

阿里云、腾讯云与微信支付 SDK 安装在 **各自 `backend/plugins/<category>/<plugin_id>/` 目录**，每个插件交付自己的 `composer.json`、`composer.lock` 和生产 `vendor/`。SMTP 同样独立管理依赖；支付宝 Easy SDK 继续保留在主 Composer。第三方插件不得修改宿主依赖。PHP 同一进程没有依赖命名空间隔离：共享包必须版本及来源一致，不兼容版本需在构建时隔离。

| SDK | 当前锁定版本 | 用途 |
| --- | --- | --- |
| `alibabacloud/dysmsapi-20170525` | 4.6.0 | 短信模型及阿里云 RPC 协议 |
| `alibabacloud/dm-20151123` | 1.11.1 | 邮件模型及阿里云 RPC 协议 |
| `alibabacloud/oss-v2` | 0.5.0 | 新版 OSS PHP SDK V2 上传、删除、预签名 |
| `tencentcloud/sms` / `faceid` / `ses` | 3.0.1679 | 官方请求模型，Common SDK 的 TC3 签名 |
| `qcloud/cos-sdk-v5` | 2.6.17 | 官方 COS 签名 |
| 七牛 REST（无 SDK） | — | 自行生成上传令牌、V2 管理签名与私有下载签名 |
| `wechatpay/wechatpay` | 1.5.0 | 官方 RSA、AES-GCM、API v3 签名格式 |

腾讯云 SDK 的模型与签名通过宿主 HTTP 桥传输，避免 SDK 私有传输实现、全局客户端和自动重试。阿里云短信/邮件使用 SDK 模型与 RPC 签名；OSS 使用官方 V2 Client 并注入受限 HTTP handler。COS 使用 SDK 签名和受限 HTTP；七牛使用固定区域上传端点和受限 HTTP 实现 REST 协议，不依赖 `qiniu/php-sdk`。

插件依赖对应目录：

| SDK | 插件目录（相对 backend） |
| --- | --- |
| `alibabacloud/dysmsapi-20170525` | `plugins/sms/aliyun_sms` |
| `alibabacloud/dm-20151123` | `plugins/mail/aliyun_dm` |
| `tencentcloud/sms` | `plugins/sms/tencent_sms` |
| `tencentcloud/faceid` | `plugins/identity/tencent_faceid` |
| `tencentcloud/ses` | `plugins/mail/tencent_ses` |
| `alibabacloud/oss-v2` | `plugins/storage/aliyun_oss` |
| `qcloud/cos-sdk-v5` | `plugins/storage/tencent_cos` |
| `wechatpay/wechatpay` | `plugins/payment/wechat_native` |
| `tencentcloud/captcha` | `plugins/captcha/tencent_captcha` |
| `alibabacloud/captcha-20230305` | `plugins/captcha/aliyun_captcha` |

保留依赖原有许可证。插件依赖自动加载由宿主负责，插件不手动引入 vendor；发行构建禁止安装脚本和 Composer 扩展，不在运行中的请求中下载安装依赖。

在后端目录完成包审核后，逐个执行：

```sh
composer install --working-dir=plugins/sms/aliyun_sms --no-dev --no-scripts --no-plugins --prefer-dist
php webman business-plugin:validate aliyun_sms
php webman business-plugin install aliyun_sms
php webman business-plugin enable aliyun_sms
```

对上表其他 ID 使用相同命令。已经安装的包按状态处理，不能重复 install；文件变更必须按 drain、处理业务引用、upgrade、enable 的既有流程审核。依赖、DI、路由及启动配置变更后完整重启 Webman；不要只 reload。

## 3. 短信

固定接口：`SmsAdapterInterface::send(SmsMessage, Configuration): SmsResult`。

宿主以 E.164 格式传入单个手机号，例如 `+8613800000000`。阿里云当前使用国内 `SendSms`；腾讯云参数必须按审核模板顺序提供列表；阿里云和赛邮为按名称提供的参数对象。赛邮、短信宝当前仅接入中国大陆手机号；短信宝要求包含已审核签名的完整正文，API Key 不自动当成明文密码计算 MD5。

- 阿里云：`SendSms`，固定 API `2017-05-25`，传递宿主事件标识 `OutId`。
- 腾讯云：`SendSms`，API `2021-01-11`，检查单个返回号码与原请求一致。
- 赛邮：`sms/xsend.json`，使用官方 v2 请求签名，变量不参与签名串。
- 短信宝：官方 GET 协议，凭据在查询参数中，不记录完整 URL、异常链、手机号或正文。

`accepted` 只表示平台受理，不代表运营商送达；`failed` 为明确拒绝；`unknown` 为结果未知。调用方必须持久化事件、限流、预留幂等记录，并在未知时人工核查或查询平台记录。传入事件 ID 不能代替宿主幂等控制。验证码生成、验证、模板映射及业务触发属于核心通知用例，本次不增加任意短信发送公网入口。

## 4. 邮件

固定接口继续使用 `MailAdapterInterface::send(MailMessage, Configuration)`。现有后台邮件测试入口同样可调用新增云邮件插件。

阿里云调用 `SingleSendMail`，支持配置的杭州、新加坡、悉尼固定端点；只发送一个收件人。腾讯云 SES 使用审核过的模板，不依赖已废弃的 `Simple` 内容方式，模板变量约定为 `text`、`html`、`subject`。如果只使用文本或 HTML，审核模板应仅引用相应内容变量。发件域名、发件地址与模板须提前在平台配置。

发送记录、权限、限流和幂等沿用宿主邮件用例；受理成功不等于最终送达。

## 5. 对象存储

固定接口：`put(ObjectUpload, Configuration)`、`delete(ObjectKey, Configuration)`、`signedUrl(ObjectKey, seconds, Configuration)`。

当前处理单个不超过 **20 MiB** 的小对象；对象键由宿主生成，仅接受明确的相对逻辑键，禁止 `..`、空路径段或绝对路径。文件类型、上传权限、病毒检查、业务归属与对象引用由调用方负责。多段上传、大文件、断点续传、目录枚举暂未开放。

云端请求全部校验证书、不跟随重定向、不自动重试。签名下载有效期为 60～3600 秒；对象和存储桶建议私有，业务检查访问权限后才生成链接。七牛下载域名须已绑定当前空间。

### 本地存储

真实根目录配置在 `backend/config/storage.php` 的 `private_root`，默认 `backend/storage/private/channel-objects`。它位于公开目录外，不由通道表单指定。插件只能使用分区和逻辑对象键，不取得任意文件系统访问能力。

宿主把对象存为逻辑键的 SHA-256 文件名，记录受控元数据，使用临时文件原子替换；目录权限 0700，文件 0600，并拒绝软链接。HMAC 从宿主 `APP_KEY` 派生，密钥不传入插件配置。更换宿主密钥会使现有签名链接失效。

固定下载入口 `GET /api/public/v1/storage/local` 验证分区、对象键、到期时间及 HMAC。返回二进制附件、`nosniff` 和 `private, no-store`，不执行 HTML、SVG 或其他上传内容。签名链接属于短期持有者凭证；前置业务必须先检查对象归属，不能直接公开签名生成接口。

## 6. 腾讯云人脸核身

`initialize` 使用 `DetectAuth` 得到 H5 URL 和 BizToken。宿主持久化申请与 BizToken、通道、修订的关系。浏览器返回只触发 `GetDetectInfoEnhanced` 主动查询，不能自行标记认证成功。

查询仅请求文本结果，不获取人脸视频、照片及证件材料。只有 `Text.ErrCode` 明确为整数 0 才返回 `verified`；缺少结果、未完成或格式异常为 `unknown`。返回 DTO 不包含姓名、身份证或人脸材料。RuleId 必须在腾讯云配置为业务要求的核身流程；意愿核身等额外步骤不能只凭活体结果判断完成。

## 7. 微信 Native 支付

Native API v3 的下单二维码是 `weixin://wxpay/bizpayurl?pr=...`，不是 HTTPS 网页。固定下单、查单、关单方法沿用支付 v1。金额全程整数分，当前仅 CNY，微信付款单号最多 32 字符。

商户私钥与平台公钥支持规范 PEM，也支持输入框粘贴的单行 PEM/Base64（纯 Base64 私钥为 PKCS#8，公钥为 SPKI）；禁止文件路径及 SDK 文件协议。平台公钥 ID 或证书序列号必须与信任锚对应；商户证书序列号单独配置。平台公钥模式与证书模式均通过显式信任锚验证，证书轮换需先处理未决流水并更新通道，当前不自动下载证书或关闭验签。

所有响应，包括错误和关单空响应，核对平台序列、时间与 RSA 签名后才解释。查单和通知还核对 AppID、商户号、付款单号、整数金额、币种及交易号。

### JSON 通知

微信使用 `JsonPaymentNotificationAdapterInterface` 的 `verifyJsonNotification`，这是 v1 中明确的 JSON 能力接口。表单通知接口继续用于支付宝，不使用数组魔法字段模拟 JSON。

核心回调用例应：

1. 按持久化付款单找到原通道、修订及应付金额快照。
2. 从 Request 取得**未修改、未重编码**的原始正文和 `Wechatpay-*` 签名头，构造 `PaymentNotification`。
3. 调用 `PaymentChannelService::verifyJsonNotification`，验证 RSA 时间窗口、AES-GCM 完整性及交易快照。
4. 在核心事务内完成付款单锁、交易号唯一性、重复通知幂等、入账和 Outbox；成功提交后按微信协议返回成功响应。

当前交付插件能力和内部执行门面，未自动增加订单收银、公网付款回调、入账或履约流程。不能仅根据二维码生成、浏览器返回或通道启用认为付款完成。未知结果先查原付款单，不切换通道或自动重下单。

## 8. 微信与 QQ 网站登录

固定接口：`authorize(state, Configuration)`、`authenticate(code, Configuration): OAuthIdentity`。微信是**开放平台网站应用**，不是公众号网页授权、小程序或管理员快捷登录；scope 为 `snsapi_login`。QQ 按用户提供的官方 PHP SDK 中的 OAuth 端点和返回格式实现协议，不直接运行依赖全局 Session 的示例 SDK。

宿主内部 `OAuthChannelService`：

- 生成 256 位随机 state，Redis 缓存 5 分钟；绑定服务端浏览器会话随机值、通道和修订。
- 用 Lua 核对会话绑定并原子消费 state；错误会话、过期、重放不得交换授权码。
- 核对原通道仍启用且修订一致，后端交换令牌并查询平台身份。
- 微信核对用户信息 OpenID/UnionID 与令牌一致；QQ 核对 `/me` 中的应用 ID，JSONP 只解析 JSON，不执行脚本。
- 回调地址保留原始路径与末尾斜线，必须与开放平台配置一致。
- 输出 `OAuthIdentity`，不返 access_token、refresh_token 或 AppSecret，也不记录带凭据的异常链。

`browserBinding` 必须由核心用例生成并保存到服务端会话，不能把浏览器提交的任意值当成绑定值。核心以 **插件身份 + 应用 ID + OpenID** 唯一绑定账号，昵称只展示，不作账号匹配。UnionID 跨应用合并需经过明确的账号绑定验证。

本次提供登录插件和安全内部门面。会员注册策略、已有账号绑定、用户禁用检查、会话签发、登录审计以及会员页面登录按钮和公网回调路由仍由核心会员模块接入；插件不能直接创建管理员或会员会话。微信/QQ 网站应用当前不增加平台未声明支持的 PKCE 参数。

## 9. 验证及官方参考

测试使用真实 SDK 模型/签名、临时 RSA 密钥、模拟 HTTP、临时私有文件目录及隔离 MySQL。OAuth state 另外使用真实 Redis、独立随机状态验证原子消费。未使用生产账号，未发真实短信、邮件、支付或认证请求，不能代替厂商联调。

```sh
HOWSTACK_MYSQL_TEST=1 vendor/bin/phpunit --filter 'CloudChannel|WechatNative|OAuthChannels|PrivateLocalObject'
```

官方资料：[阿里云短信](https://api.aliyun.com/api-tools/sdk/Dysmsapi?version=2017-05-25&language=php-tea)、[腾讯云短信](https://cloud.tencent.com/document/product/382/43195)、[赛邮](https://www.mysubmail.com/documents/XwlOB)、[短信宝](https://www.smsbao.com/openapi/213.html)。

存储资料：[OSS PHP SDK V2](https://help.aliyun.com/zh/oss/developer-reference/manual-for-php-v2/)、[COS PHP SDK](https://cloud.tencent.com/document/product/436/12266)、[七牛 REST API](https://developer-doc.qiniu.com/products/kodo/3939-overview-of-the-api)。

其他资料：[腾讯云 Faceid](https://github.com/tencentcloud-sdk-php/faceid)、[微信 Native](https://pay.weixin.qq.com/doc/v3/merchant/4012076511)、[阿里云邮件](https://api.aliyun.com/api-tools/sdk/Dm?version=2015-11-23&language=php-tea)、[腾讯云 SES](https://github.com/tencentcloud-sdk-php/ses)、[微信网站登录](https://developers.weixin.qq.com/doc/oplatform/Website_App/WeChat_Login/Wechat_Login.html)、[QQ 官方 PHP SDK 下载](https://cgi.connect.qq.com/v5/website/app_get_sdk_download_url?name=PHP_SDK_2.3)。

## 人机验证

新增 `captcha` 分类：图像验证码、极验 4.0、网易云盾、腾讯云天御、阿里云验证码 2.0。配置、初始化参数、原始证明和宿主一次性校验流程见 [人机验证插件开发与接入](captcha-channels.md)。
