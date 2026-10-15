# 人机验证插件开发与接入（v1）

本指南面向第三方插件开发者与核心业务接入者。后台入口为「系统管理 → 通道管理 → 人机验证」，默认列出每个插件，直接配置、启用或禁用，不创建额外通道；密钥加密保存。配置保存只校验格式，不表示已完成厂商联调。

## 1. 内置插件与依赖

| 插件标识 | 平台 | 后端依赖位置 |
| --- | --- | --- |
| `image_captcha` | 图像验证码 | 复用宿主 `webman/captcha`，宿主提供受限图片绘制与答案校验能力 |
| `geetest_v4` | 极验行为验证 4.0 | HTTPS 协议，无额外 SDK |
| `yidun_captcha` | 网易云盾行为式验证码 | 按用户提供的官方 PHP v2 demo 协议实现，无额外 SDK |
| `tencent_captcha` | 腾讯云天御验证码 | 本插件目录 `tencentcloud/captcha`，锁定 3.0.1679 |
| `aliyun_captcha` | 阿里云验证码 2.0 | 本插件目录 `alibabacloud/captcha-20230305`，锁定 1.1.3 |

腾讯云、阿里云 SDK 的 Composer 配置、锁文件与生产 `vendor/` 均放在对应插件目录。开发者在构建环境运行：

```sh
cd backend
composer install --working-dir=plugins/captcha/tencent_captcha --no-dev --no-scripts --no-plugins --prefer-dist
composer install --working-dir=plugins/captcha/aliyun_captcha --no-dev --no-scripts --no-plugins --prefer-dist
php webman business-plugin:validate
php webman business-plugin install tencent_captcha
php webman business-plugin enable tencent_captcha
```

其余插件按对应标识安装。更新已安装包需先 drain，再 upgrade、enable；文件与锁文件变更后完整重启 Webman。插件不执行 Composer 命令、不自行加载其他插件的依赖。共享依赖必须版本及来源一致，独立目录不等于 PHP 命名空间隔离。发行包必须包含各插件生产 vendor 和原依赖许可证。

插件自身类遵循 PSR-4，宿主已有 `plugins\\` → `plugins/` 映射。独立 SDK 的 Composer 自动加载器由宿主验证后加载。不要把各插件 vendor 逐项写进主 `autoload.files`，也不要通过空命名空间映射接管核心类。参考 [Webman 自动加载说明](https://www.workerman.net/doc/webman/others/autoload.html)。

## 2. 固定目录和入口

```text
plugins/captcha/<plugin_id>/
├── plugin.json
├── configuration.schema.json
├── CaptchaAdapter.php
├── README.md
├── composer.json          按需
├── composer.lock          使用 Composer 时必需
└── vendor/                使用 Composer 时发行包必需
```

清单协议与 `captcha_sdk` 均为 `1.0`，入口为 `api\\CaptchaAdapter`，实现 `CaptchaAdapterInterface`。不注册任意路由、权限、迁移或前端启动代码，构造函数不联网。固定能力为：

```php
public function validateConfiguration(Configuration $configuration): Configuration;
public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge;
public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string;
public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult;
```

`initialize` 返回公开的组件初始化参数；`fingerprint` 返回证明的 SHA-256 指纹，用于宿主一次性消费；`verify` 执行服务端二次校验，只能返回 `verified`、`rejected` 或 `unknown`。密钥、原始证明、答案及私有会话值不进入公开响应。

## 3. 配置与证明字段

| 插件 | 后台连接配置 | 初始化公开参数 | `CaptchaProof` 字段 |
| --- | --- | --- | --- |
| 图像 | `length`、`width`、`height` | JPEG `image` data URI | `answer` |
| 极验 | `captcha_id`、`captcha_key` | `captchaId`、`product=bind` | `lot_number`、`captcha_output`、`pass_token`、`gen_time` |
| 网易云盾 | `captcha_id`、`secret_id`、`secret_key` | `captchaId`、随机挑战 `user` | `validate` |
| 腾讯云 | `secret_id`、`secret_key`、`captcha_app_id`、`app_secret_key` | `appId` | `ticket`、`randstr` |
| 阿里云 | `access_key_id`、`access_key_secret`、`scene_id`、`prefix`、`region` | `SceneId`、`prefix`、`region` | `captcha_verify_param` |

密钥字段使用 `password` schema，接口只返回是否已配置，空输入保留旧密钥。图像配置默认 5 位、160×50 px；允许 4～8 位、宽 120～320 px、高 40～120 px。

### 平台校验约束

- 极验：保留原始证明，按 `lot_number` 和服务端密钥生成 HMAC-SHA256，成功结果必须与本次 lot number 一致。过期证明拒绝，异常不自动放行。
- 网易云盾：固定 HTTPS 的 `api/v2/verify`，按官方 demo 排序拼接参数与密钥生成 MD5；验证请求 `user` 为本次随机挑战身份。只接受整数 `error=0` 和布尔 `result=true`，不把字符串当成功，不对原始 validate 做去斜线或重新编码。
- 腾讯云：使用 API 2019-07-22 的 `DescribeCaptchaResult`，服务端提供 `CaptchaType=9`、可信 IP、应用 ID 和对应应用密钥；云 API SecretId/SecretKey 与验证码 AppSecretKey 分别填写。只接受整数 `CaptchaCode=1`；容灾 `trerror` 票据不放行。
- 阿里云：使用 API 2023-03-05 的 `VerifyIntelligentCaptcha`，`CaptchaVerifyParam` 原始整体字符串不重新序列化，`SceneId` 从服务端连接配置传入。cn/sgp 分别使用固定上海/新加坡端点。严格核对成功布尔值与真实通过码 `T001`，当前不接受测试模式码 `T005`。
- 图像：宿主使用安全随机数生成答案，Redis 只存绑定摘要及 HMAC 答案；插件只能调用受限的绘制、校验接口，不能读取 Redis、宿主密钥或明文答案。

## 4. 核心业务接入

已提供内部 `CaptchaChannelService`，供核心登录、注册、找回密码等用例调用。启用配置不会自动改变现有登录策略，当前没有新增可独立兑换“通行证”的公网接口。业务模块接入时须实现对应的固定浏览器组件与保护策略。

1. 核心策略选择通道、修订号及场景；浏览器不得任选较弱通道绕过保护。
2. 服务端会话生成并保存 64 位十六进制随机绑定值，可信 IP 由宿主统一解析。
3. 调用 `initialize`，返回挑战标识、300 秒有效期及公开初始化参数。
4. 浏览器完成平台交互后，将原始证明随受保护的业务请求一起提交。
5. 在该业务请求内部调用 `verify`，只有 `verified` 才继续本次业务；`unknown` 也拒绝继续。

```php
$challenge = $captcha->initialize(
    $channelId,
    $revision,
    $sessionBinding,
    'member.login',
    $trustedIp,
);

$result = $captcha->verify(
    $challengeId,
    new CaptchaProof($originalProof),
    $sessionBinding,
    'member.login',
    $trustedIp,
);

if ($result->status !== 'verified') {
    // 返回统一业务错误，要求重新完成验证。
    throw new BusinessException('人机验证未通过，请重新验证');
}

// 在同一次请求内继续核心登录流程和原有权限校验。
```

变量来自受保护用例，不直接相信请求体的 IP、会话绑定、场景、通道或修订号。前端根据宿主固定 provider 映射渲染厂商官方组件，不执行任意插件提供的脚本 URL。

## 5. 一次性消费与失败行为

- 随机挑战绑定会话、场景及可信 IP，300 秒过期；错误绑定不能消费其他会话的挑战。
- 绑定正确的提交先原子消费挑战，再保留证明指纹 600 秒；错误答案、平台异常和结果未知也不能重放原挑战。
- 同一证明换挑战仍拒绝，例如修改腾讯 `randstr` 不改变 ticket 指纹；图像答案按挑战指纹，避免不同用户碰巧输入相同答案被误拒绝。
- 每可信 IP 每分钟最多初始化 30 次；业务模块仍须执行账号级限流和原有认证策略。
- 配置修订、插件摘要或状态变化时停止验证，不切换其他平台重试。平台故障、Redis 故障或返回类型异常均不放行。
- 不把证明写入任务、持久日志或调试输出，不签发可重复使用的放行凭证。原生 PHP 插件仍是受信代码，受限接口不是恶意代码沙箱。

## 6. 验证与官方资料

本地测试覆盖真实 GD 图片、Redis 过期设置/原子消费/绑定/防重放、配置加密与依赖加载，并使用真实官方请求模型和模拟 HTTP 验证签名及失败路径。未使用真实厂商凭据，仍需平台控制台配置网站域名、场景、密钥及前端组件后联调。

- [Webman 图像验证码](https://www.workerman.net/doc/webman/components/captcha.html)
- [极验 4.0 快速集成](https://docs.geetest.com/gt4/quick_integration_guide) / [服务端接入](https://docs.geetest.com/gt4/deploy/server/)
- [网易云盾官方 PHP demo](https://github.com/yidun/captcha-php-demo) / [用户指定接入文档](https://support.dun.163.com/documents/15588062143475712?docId=150401879704260608)
- [腾讯云 Captcha SDK](https://github.com/tencentcloud-sdk-php/captcha) / [验证票据 API](https://cloud.tencent.com.cn/document/api/1110/36926)
- [阿里云验证码 2.0 服务端接入](https://help.aliyun.com/zh/captcha/captcha2-0/user-guide/server-access)
