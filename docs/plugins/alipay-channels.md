# 支付宝通道接入与插件开发（v1）

本指南面向安装者及第三方开发者。两个插件是固定外部接口实现，宿主统一保存配置、加密私钥、校验类别、启用状态、包摘要和配置修订。插件不访问订单、账务、会员表或容器，不注册自定义路由、菜单、安装脚本。

## 目录与依赖

```text
backend/
├── composer.json                 alipaysdk/easysdk 2.2.3，两个官方插件共用
├── composer.lock                 锁定 SDK 及传递依赖
├── vendor/                       由主 Composer 安装
└── plugins/
    ├── payment/alipay_order_code/
    └── identity/alipay_identity/
```

按本项目决定，支付宝 Easy SDK 安装到项目后端主 Composer，两个插件各自目录没有 Composer/vendor。SDK 的 Tea 依赖目前要求 Guzzle 7，宿主使用兼容的 `^7.15`；不能升级到 Guzzle 8 后忽略依赖冲突。发行包包含主锁文件及生产依赖。第三方插件仍可自行声明并交付独立 Composer SDK，禁止自行修改主 Composer；同进程依赖冲突校验保持不变。

两个插件分别有 `plugin.json`、`configuration.schema.json`、根目录固定 `*Adapter.php`，专有配置和支付宝协议封装置于各自 `app/`。协议统一 v1，清单值 `1.0`。官方 SDK 软件版本 2.2.3 与插件协议版本不同。

## 安装和配置

在 `backend/` 运行，先完成依赖安装，再校验并登记插件：

```sh
composer install --no-scripts --no-plugins --prefer-dist
php webman business-plugin:validate alipay_order_code
php webman business-plugin:validate alipay_identity
php webman business-plugin install alipay_order_code
php webman business-plugin enable alipay_order_code
php webman business-plugin install alipay_identity
php webman business-plugin enable alipay_identity
```

已经登记的包使用现有生命周期操作，不重复安装。修改已启用包需按停止接单、处理原流水、审核升级、重新启用流程执行，不能直接绕过包摘要检查。SDK、自动加载或容器绑定变化后完整重启 Webman。

| 后台入口 | 服务平台 | 配置 |
| --- | --- | --- |
| 系统管理 → 通道管理 → 支付通道 | 支付宝订单码支付 | 应用 ID、商户 PID、应用私钥、支付宝公钥、沙箱开关 |
| 系统管理 → 通道管理 → 实名认证 | 支付宝身份验证 | 应用 ID、应用私钥、支付宝公钥、沙箱开关 |

使用**公钥模式、RSA2**，应用私钥须为 2048 位 RSA，支持 PKCS#1/PKCS#8 PEM 或对应 Base64。支付宝公钥应从开放平台获取，不能填应用公钥。私钥加密入库，接口不回显；编辑时私钥留空保留原值。每个插件在默认列表中直接配置并启停，不再新增通道；每次调用只使用本次商户配置，不共享商户静态配置。

生产与沙箱网关由插件固定选择，禁止填写任意接入地址、关闭 TLS 或跨域重定向。商户应用须实际开通对应产品；沙箱开关不保证沙箱已支持某项认证产品。保存和启用只表示配置可供业务用例选用，不代表外部开通验证成功。

## 支付固定契约

公共接口：`app/shared/plugin/contract/PaymentAdapterInterface.php`。

| 方法 | 输入 | 输出和上游方法 |
| --- | --- | --- |
| `validateConfiguration` | `Configuration` | 规范化配置，无网络副作用 |
| `create` | `PaymentRequest, Configuration` | `PaymentResult`；`alipay.trade.precreate`，生成二维码 |
| `query` | 同上 | 查单；`alipay.trade.query` |
| `close` | 同上 | 关单；`alipay.trade.close` |
| `verifyNotification` | 原始表单参数、原付款快照、原商户配置 | 验签并核对应用、商户、付款单和金额 |

`PaymentRequest` 包含付款单号、整数分金额、标题、宿主 HTTPS 通知 URL、分钟有效期和币种。当前支付宝仅支持 CNY。元金额使用整数转换为两位小数字符串，不经过 float。查询/回调使用持久化的原始金额快照，不能信任回调提交的金额来构造 expected 请求。

`PaymentResult.status` 为 `pending/paid/closed/not_found/failed/unknown`。生成二维码仅为 pending；paid 必须有支付宝交易号。query 的官方响应不保证包含 seller_id，存在时核对；异步通知强制核对 app_id 和 seller_id。未验签、金额不符或身份不符的结果不能入账。超时、格式异常及关单失败为未知结果时先查询原流水，禁止自动重发或更换通道。

宿主业务经容器注入 `PaymentChannelService`，不是直接加载未审核插件：

```php
use app\modules\channel\application\payment\PaymentChannelService;
use app\shared\plugin\dto\payment\PaymentRequest;

// 调用方已做权限检查，并持久化付款单、通道及配置修订。
$request = new PaymentRequest(
    paymentNo: $payment->number,
    amountMinor: $payment->amountMinor,
    subject: '云服务器订单',
    notifyUrl: $trustedNotifyUrl,
);
$result = $channels->create($payment->channelId, $payment->channelRevision, $request);
```

服务还提供 query/close/verifyNotification。调用方必须：

1. 创建稳定唯一付款单号，先持久化关联订单、用户、金额、币种、通道和修订；重复请求复用原流水。
2. 新交易前执行权限、订单状态和应付校验，不能允许浏览器直接指定应付金额或任意通知地址。
3. 在宿主固定通知入口限制请求大小，解析一次原始 form 字段，保留签名字段并拒绝数组/重复歧义参数；按可靠付款关联查原快照。
4. 验签后在事务内锁定原付款单，校验交易号唯一性、业务归属、金额及乱序状态，重复通知幂等处理；事务成功提交后才回复支付宝 `success`，失败不确认。
5. 订单付款、资金入账与履约通过核心事务和 Outbox 处理。插件无权自行完成这些步骤。
6. 通道修订不匹配、包禁用或包摘要变化时执行门面拒绝调用；目前没有历史凭据快照服务。变更商户配置前先收敛未决交易，禁止用新凭据强行查询旧交易。

## 身份验证固定契约

公共接口：`app/shared/plugin/contract/IdentityAdapterInterface.php`。

| 方法 | 输入 | 输出和上游方法 |
| --- | --- | --- |
| `validateConfiguration` | `Configuration` | 规范化配置，无网络副作用 |
| `initialize` | `IdentityRequest, Configuration` | `alipay.user.certify.open.initialize`，FACE 场景；返回 certify_id 和认证 URL |
| `query` | `IdentityReference, Configuration` | `alipay.user.certify.open.query`，验签后映射 passed |

认证 URL 由 SDK 对 `alipay.user.certify.open.certify` 加签生成，不额外请求接口。initialize 输入为宿主申请号、姓名、18 位身份证号、固定 HTTPS 返回地址。DTO 的 JSON/调试输出脱敏并禁止序列化，任务仅保存申请引用；调用方从受保护的申请记录取得身份材料后单次调用。

```php
use app\modules\channel\application\identity\IdentityChannelService;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityReference;

$result = $channels->initialize($channelId, $revision, new IdentityRequest(
    requestNo: $applicationNumber,
    name: $decryptedName,
    identityNumber: $decryptedIdentityNumber,
    returnUrl: $trustedReturnUrl,
));
// 先保存本地申请与 certify_id 的关联，再向本人提供认证链接。
// 后续 query 只能使用已持久化、已核对归属的认证号。
$checked = $channels->query($channelId, $revision, new IdentityReference($applicationNumber, $savedCertifyId));
```

结果为 `pending/verified/rejected/failed/unknown`。查询 passed=T 映射 verified，F 映射 rejected，缺失或未识别结果为 unknown；业务仍需按产品规则处理处理中、重试资格等状态。浏览器返回参数不能代替后端查验；前端不得指定其他人的 certify_id。宿主负责申请归属、隐私数据保护、有效期、频率限制和会员状态更新，插件不返回人脸资料、姓名或证件原文。

## 第三方开发边界

支付清单必须声明 `payment_sdk=1.0` 和 `payment.create/query/close/notify=true`；身份验证声明 `identity_sdk=1.0` 和 `identity.initialize/query=true`；两者网络声明 `transport=https`。入口必须实现对应公开接口，返回标准 DTO，不添加任意行为钩子或直接依赖核心模块。可注入 `HttpClientInterface` 便于契约测试；使用公共客户端的默认 TLS、10 秒超时、禁止重定向、不自动重试及 2 MiB 响应上限。

固定方法目前不包含退款、企业认证、附件或任意动态 API 名称。需要扩展先定义对应宿主公开契约，不能自行在配置中传入任意 method 绕过边界。新插件可按 HTTP 指南调用平台，也可按插件依赖指南自行引入 SDK。

官方 Easy SDK Factory 存在静态配置，常驻 Worker 多商户调用不使用 `Factory::setOptions()`。本插件每次创建独立 `EasySDKKernel` 完成协议加验签，并通过公共客户端发送固定网关请求，避免跨通道配置串用。

## 验证范围与业务接入状态

`AlipayAdaptersTest` 使用真实官方 SDK 和临时 RSA2 密钥，对请求、响应和通知做真实签名/验签；HTTP 响应由 MockHandler 提供，覆盖精确金额、错单、错商户、篡改、未知结果及配置隔离。`AlipayChannelManagementTest` 使用隔离数据库验证登记启用、配置加密、密钥保留、类别限制及失效修订拦截。测试不向支付宝下真实订单或提交真实个人材料。

2026-10-06，隔离 MySQL 回归通过 141 项测试、790 个断言，包含支付宝、公共 HTTP、依赖加载、SMTP、通道管理、插件生命周期、供应插件和官网接入。本地重启后容器能加载两个固定适配器与内部执行门面，平台目录包含两个支付宝插件及原 SMTP；匿名通道 API 返回 401。以上不替代登录后的浏览器验收或支付宝真实商户联调。

当前完成**插件能力、后台配置及内部执行门面**。核心完整在线收银、公网通知入口、自动入账、会员认证页面和认证状态流程尚未接入。退款、证书模式和服务商代调用不支持。真实商户须在开通产品并配置后进行独立联调，不能把模拟响应或配置保存作为真实支付/认证成功证明。

## 官方参考

- [Alipay Easy SDK PHP](https://github.com/alipay/alipay-easysdk/tree/master/php)
- [SDK API 文档](https://github.com/alipay/alipay-easysdk/blob/master/APIDoc.md)
- [支付宝订单码支付](https://open.alipay.com/api/detail?code=I1080300001000068149&index=0)
- [支付宝身份验证](https://open.alipay.com/api/detail?code=I1080300001000044085&index=0)
- [公共 HTTP 请求指南](http-client.md)
- [插件独立 Composer 依赖](mail-channel-development.md#插件自己的-composer-依赖)
