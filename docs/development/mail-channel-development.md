# 邮件通道插件开发指南 v1

本文面向本项目维护者，包含源码位置和内部测试入口。提供给第三方开发者的独立文档为 [邮件通道插件开发文档](../plugins/mail-channel-development.md)。

开发邮件通道只需完成三件事：**声明插件、声明配置字段、实现配置校验和发送方法**。后台表单、配置保存、密码加密、权限、启停和发送记录由系统统一提供。

本文对应当前代码，协议统一为 v1，清单中的版本值使用 `1.0`。内置 SMTP 使用 `phpmailer/phpmailer`；HTTPS 邮件平台可使用系统公共 HTTP 快捷客户端，也可以声明并构建自己的 SDK 依赖。协议版本与插件自身的包版本分别管理。

## 1. 创建目录

所有路径从仓库根目录开始：

```text
backend/plugins/mail/example_mail/
├── plugin.json                 插件信息
├── configuration.schema.json   后台配置字段
└── MailAdapter.php          固定入口
```

- 插件 ID 必须符合 `^[a-z][a-z0-9_]{0,63}$`，并在所有插件类别中唯一。
- 目录名、清单 ID 和命名空间保持一致：`plugins\mail\example_mail`。
- 入口文件和类固定叫 `MailAdapter.php`、`MailAdapter`。
- 内部辅助类可放到自己的 `app/` 目录，README 按需添加。不需要编写控制器、后台页面或空的 `install()`、`uninstall()`。
- 插件源码放在 `backend/plugins/`，不放在 Webman 的 `config/plugin/` 或公开的 `public/`。

## 2. 声明插件信息

`plugin.json` 完整示例，开发时修改 ID、名称、发布者、许可证及兼容范围：

```json
{
  "manifest_version": "1.0",
  "id": "example_mail",
  "category": "mail",
  "name": "示例邮件平台",
  "version": "1.0.0",
  "publisher": "example-team",
  "license": "AGPL-3.0-or-later",
  "requires": {
    "core_min": "0.1.0",
    "core_max_exclusive": "1.0.0",
    "php_min": "8.4",
    "plugin_sdk": "1.0",
    "mail_sdk": "1.0"
  },
  "configuration_schema": "configuration.schema.json",
  "capabilities": {
    "mail.send": true
  },
  "network": {
    "transport": "https"
  },
  "permissions": [],
  "frontend": [],
  "migrations": [],
  "data_policy": "retain"
}
```

SMTP 插件将 `network.transport` 设置为 `smtp`，HTTPS 平台设置为 `https`。当前只开放单封发送能力，`permissions/frontend/migrations` 必须为空数组；不要加入未发布的能力或自定义路由。

## 3. 声明配置字段

`configuration.schema.json` 声明后台需要填写的内容，不保存客户实际的账号、密钥或密码。以下与后面的 HTTPS 示例对应：

```json
{
  "schema_version": "1.0",
  "scope": "connection",
  "fields": [
    {
      "key": "api_url",
      "label": "发送接口地址",
      "type": "url",
      "required": true,
      "max_length": 512,
      "placeholder": "例如 https://mail.example.com/send",
      "description": "填写邮件平台的 HTTPS 发送地址。"
    },
    {
      "key": "api_key",
      "label": "平台密钥",
      "type": "password",
      "required": true,
      "max_length": 2048,
      "placeholder": "请输入平台密钥"
    },
    {
      "key": "from_email",
      "label": "发件邮箱",
      "type": "text",
      "required": true,
      "max_length": 254,
      "placeholder": "例如 noreply@example.com"
    }
  ]
}
```

当前最多声明 16 个字段；所有字段都要有 `key/label/type/required`。`key` 使用与插件 ID 相同的字符规则。

| 类型 | 额外声明 | 用途 |
| --- | --- | --- |
| `text`、`url` | `max_length`，范围 1～2048 | 名称、地址、邮箱等 |
| `password` | `max_length`，不能设置 `default` | 密码、授权码、密钥 |
| `integer` | `minimum`、`maximum` | 端口、超时等整数 |
| `boolean` | 可设置布尔 `default` | 是否启用认证等 |
| `select` | `options`，每项包含字符串 `label/value` | 加密方式、区域等枚举 |

按需填写 `placeholder/description/default/unit`。带单位的字段通过 `unit` 展示后缀；整数由后台使用数字输入框，开关使用 round 样式。`default` 必须满足字段类型和范围。

后台默认显示插件，每个插件直接维护一份配置，不再新增通道。历史连接身份和业务引用保留。私密字段由宿主加密，读取配置仅显示是否已设置；修改时留空保留旧值，清除必须通过明确操作。插件只接收当前调用解密后的配置。

## 4. 实现固定入口

入口实现公共 [MailAdapterInterface](../../backend/app/shared/plugin/contract/MailAdapterInterface.php)，只需要两个方法：

| 方法 | 输入 | 输出 |
| --- | --- | --- |
| `validateConfiguration()` | 当前通道的 `Configuration` | 校验、规范化后的 `Configuration` |
| `send()` | `MailMessage` 和当前通道的 `Configuration` | `MailResult` |

配置校验只处理平台规则、字段关系和默认值，不访问网络。类型、必填和未知字段由宿主按声明先校验，插件返回的配置仍会再次校验。构造函数不发请求，不保存本次通道的凭据。

### HTTPS 入口示例

下面是**示例协议**：使用 Bearer 密钥，POST JSON，HTTP 202 明确表示已接收；HTTP 400、401、403、422 明确表示未接收，其余状态和异常按结果未知处理。真实开发必须按对应平台的官方文档调整地址、鉴权、字段和结果判定，不能假设所有平台都遵循这个协议。

`MailAdapter.php`：

```php
<?php

declare(strict_types=1);

namespace plugins\mail\example_mail;

use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use app\shared\plugin\http\HttpClient;
use InvalidArgumentException;
use Throwable;

final readonly class MailAdapter implements MailAdapterInterface
{
    /**
     * 补充平台配置规则，不执行网络请求。
     * @param Configuration $configuration 本次通道配置
     * @return Configuration 通过校验的配置
     * @throws InvalidArgumentException 地址、密钥或发件邮箱无效
     */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $config = $configuration->values;
        $url = $config['api_url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;

        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !is_string($config['api_key'] ?? null)
            || trim($config['api_key']) === ''
            || !filter_var($config['from_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('请检查平台地址、密钥和发件邮箱');
        }

        return $configuration;
    }

    /**
     * 将标准邮件转换为本平台的发送请求。
     * @param MailMessage $message 单封邮件
     * @param Configuration $configuration 本次通道配置
     * @return MailResult 平台接收结果
     */
    public function send(MailMessage $message, Configuration $configuration): MailResult
    {
        $config = $this->validateConfiguration($configuration)->values;
        $http = new HttpClient();

        try {
            $response = $http->post($config['api_url'], [
                'from' => $config['from_email'],
                'email' => $message->recipient,
                'subject' => $message->subject,
                'text' => $message->text,
                'html' => $message->html,
                'message_id' => $message->messageId(),
            ], ['Authorization' => 'Bearer ' . $config['api_key']]);
            $code = $response->status;
            $status = match (true) {
                $code === 202 => 'accepted',
                in_array($code, [400, 401, 403, 422], true) => 'failed',
                default => 'unknown',
            };
        } catch (Throwable) {
            // 请求可能已经被接收；不重发，不输出异常或原始回包。
            $status = 'unknown';
        }

        return new MailResult($status, $message->messageId());
    }
}
```

示例地址是占位地址，不对应已接入的服务商。发布前还需根据平台规则限制允许访问的主机、响应大小及错误语义；平台支持幂等时，将 `$message->key` 映射到其官方幂等参数，不自行生成新的请求编号。

### SMTP 参考实现

开发 SMTP 类通道时，直接参考已实现的 [SMTP 入口](../../backend/plugins/mail/smtp/MailAdapter.php)、[配置声明](../../backend/plugins/mail/smtp/configuration.schema.json)、[配置校验](../../backend/plugins/mail/smtp/app/SmtpConfiguration.php) 和 [DATA 阶段跟踪](../../backend/plugins/mail/smtp/app/TrackedSmtp.php)。复制为新插件后同步修改清单 ID、命名空间与内部引用，不直接依赖另一个插件的类。

该实现使用 PHPMailer，支持 SMTP 认证、SSL/TLS、STARTTLS、中文及 HTML 邮件；每次发送新建客户端并关闭连接，不复用其他通道的账号或收件人。SMTP 服务器已接收正文但确认前断线时返回 `unknown`，不能直接当作失败重发。

## 5. 参数和返回结果

`Configuration::values` 是当前通道的配置数组。`MailMessage` 已由宿主校验，字段如下：

| 字段/方法 | 含义与限制 |
| --- | --- |
| `key` | 小写 UUID 请求键，由业务调用方提供；重试保持同一个键 |
| `recipient` | 单个有效收件邮箱，最长 254 字节 |
| `subject` | 非空主题，最长 200 个字符，不含控制字符 |
| `text` | 必填纯文本正文，最多 262144 字节 |
| `html` | 可选 HTML 正文，最多 262144 字节；即使使用 HTML，仍须提供 `text` |
| `messageId()` | 稳定的邮件标识；不能替代宿主或远端幂等机制 |

统一返回 `new MailResult($status, $message->messageId())`，不能返回另一个请求的标识，也不返回平台原始响应、凭据或正文。

| 状态 | 含义 | 处理方式 |
| --- | --- | --- |
| `accepted` | 平台明确确认接收 | 不重复发送；收件箱最终送达仍需确认 |
| `failed` | 明确未接收/拒绝，按平台协议判定 | 展示安全提示，由业务决定后续处理 |
| `unknown` | 超时、断线、异常，无法确认是否接收 | 先核查平台记录或邮箱，不自动重发或切换通道 |

同键同内容的调用由宿主返回已有结果，同键不同内容拒绝；已领取的发送不会因为浏览器重试而重复执行。发送记录保留请求键、内容摘要和状态，不保存邮件正文或明文凭据。插件不得自己遍历会员、生成验证码、渲染业务模板或决定通知触发策略。

## 6. 安装、配置与升级

从 `backend/` 目录运行。以下命令适用于新开发的 `example_mail`，不是让已安装的内置 SMTP 再安装一次：

```bash
php webman business-plugin:validate example_mail
php webman business-plugin install example_mail
php webman business-plugin enable example_mail
php webman business-plugin list
php start.php restart -d
```

结构校验不修改安装状态，也不执行真实发送；安装前审核代码与依赖，安装后完整重启使各 Worker 加载同一版本。第三方客户端由插件自己的 `composer.json` 和 `composer.lock` 管理，交付预构建的生产 `vendor/`。不得放置真实配置或密钥，不修改宿主 Composer，也不在客户安装时执行任意脚本。依赖加载、构建命令及冲突规则见 [第三方开发文档](../plugins/mail-channel-development.md#插件自己的-composer-依赖)。

在后台“系统管理 → 通道管理 → 邮件通道”找到默认显示的插件并点击“配置”。保存后点击“测试发送”，只填写收件邮箱即可。测试需要 `admin.channels.mail.view` 和 `admin.channels.mail.test` 权限，可测试禁用通道；每个通道测试间隔至少 30 秒。业务发送要求通道已启用；通过主用通道发送时还需明确设置“主用通道”。

升级已有插件时，停止接单、禁用引用它的通道并完成已有发送收尾，再审核新包；增加插件包版本后执行：

```bash
php webman business-plugin drain example_mail
# 在后台禁用引用通道、完成收尾，并部署经过审核的新包。
php webman business-plugin:validate example_mail
php webman business-plugin upgrade example_mail
php webman business-plugin enable example_mail
php start.php restart -d
```

上述 `drain` 适用于当前处于 `enabled` 状态的插件；已禁用插件按其状态执行升级。包内文件修改都会改变摘要，不能直接改安装记录绕过审核。升级后重新保存/验证存量通道配置、测试发送，再启用业务通道；历史配置快照不会自动替换。存在通道或历史引用时，不直接卸载插件。

## 7. 验证与排错

开发自己的插件时至少验证：配置错误、密码不回显、正常接收、明确拒绝、超时结果未知、相同请求不重复发送、不同通道凭据隔离，以及 TLS 校验与重定向策略。

现有测试可参考 [SMTP 集成测试](../../backend/tests/integration/SmtpMailChannelTest.php) 和 [插件包测试](../../backend/tests/integration/BusinessPluginPackageTest.php)：

```bash
vendor/bin/phpunit --filter 'SmtpMailChannelTest|BusinessPluginPackageTest|ChannelManagementTest'
```

SMTP 测试使用本地邮件服务，不投递外部邮箱；HTTPS 契约测试使用模拟响应。新增真实平台仍需配置自己的测试账号，确认平台记录与收件邮箱。

| 现象 | 优先检查 |
| --- | --- |
| 后台没有新平台 | 类别、ID、命名空间、兼容范围、结构校验、启用状态、包摘要和 Worker 重启 |
| 修改文件后不可用 | 按生命周期审核升级，不自动接受变更摘要 |
| 保存配置失败 | 声明字段类型、长度、默认值及插件补充校验，不向响应泄漏原始异常 |
| 发送返回 `unknown` | 先查平台记录或收件邮箱，不生成新键反复试发 |
| 不能升级/卸载 | 查看启用通道、存量业务及历史引用，按提示完成收尾 |

当前契约不包含附件、群发、模板管理、投递查询或回调。需要这些能力时先扩展公共契约和宿主用例。PHP 插件属于受信代码；固定接口和静态检查不构成安全沙箱，禁止依赖核心内部模型、数据库、容器、环境变量、文件或进程调用。

## 关联文档

- [通道管理实施说明](channel-management.md)：后台入口、API、权限和数据模型。
- [业务插件体系优化方案](../architecture/business-plugin-optimization.md)：类别边界与生命周期。
- [后端开发规范](../../backend/AGENTS.md)：代码格式、目录与依赖约定。

公共 HTTP 快捷客户端提供 GET、POST JSON、PUT JSON、DELETE、POST 表单及通用 request；使用示例及边界见 [第三方 HTTP 请求指南](../plugins/http-client.md)。普通 HTTP 插件无需声明 Guzzle 依赖，第三方 SDK 仍独立管理，不设依赖包名白名单。
