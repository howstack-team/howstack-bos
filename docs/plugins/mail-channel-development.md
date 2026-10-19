# 邮件通道插件开发文档（第三方开发者）

适用产品：**皓量云擎云业务运营系统（howstack-bos）**  
适用协议：**v1**  
面向读者：希望为本系统接入 SMTP 或第三方邮件平台的插件开发者。

你需要交付一个独立的邮件插件目录，提供配置字段和发送逻辑。系统根据声明生成后台配置表单，并统一管理账号配置、权限和发送记录。本文包含完整的三个文件示例，可以独立提供给第三方开发者阅读。

平台提供模板审核 API 时，同一个根目录入口可按需实现 `SubmitTemplateAdapterInterface` 和 `QueryTemplateReviewAdapterInterface`，分别增加 `submitTemplate()`、`queryTemplateReview()`。不需要新增入口或 JSON 声明；完整参数、状态及账号绑定规则见 [短信与邮件模板审核契约](message-template-review.md)。普通 SMTP 保持原发送契约。

## 一、开发前准备

准备一套已安装的本系统开发环境，以及邮件平台的接口文档和测试账号。

- PHP 8.3 或更高版本，系统版本须符合插件声明的兼容范围。
- 在独立测试实例开发，使用邮件通道配置及测试发送权限。
- 系统提供公共插件接口和封装好的 HTTP 快捷客户端；常规 HTTP 调用无需再声明 Guzzle 依赖。PHPMailer 或邮件平台 SDK 可由插件按需独立声明，宿主自动加载已审核的本地依赖，插件不手工 `require`。
- 第三方 SDK 在插件目录独立声明、锁定和构建，不修改系统主 Composer 配置；交付预构建依赖，客户安装时不运行 Composer。

本文中的**系统运行目录**指包含 `start.php`、`webman` 和 `plugins/` 的目录，插件安装位置相对此目录计算。开发无需调整系统核心代码、数据库、后台菜单或前端工程。

## 二、创建插件目录

以 `example_mail` 为例：

```text
plugins/mail/example_mail/
├── plugin.json                 插件名称、版本与兼容范围
├── configuration.schema.json   用户需要填写的配置字段
└── MailAdapter.php              配置校验和发送入口
```

只要求上述三个文件；内部辅助代码可放在自己的 `app/` 目录，使用 `plugins\mail\example_mail\app` 命名空间。可附带 `README.md`、`CHANGELOG.md` 和许可证文件。

### 插件自己的 Composer 依赖

需要第三方 SDK 时，增加本插件自己的 `composer.json`、`composer.lock` 和预构建 `vendor/`。不要将 SDK 加到系统主 `composer.json`，也不要复制系统的 vendor。

本文 HTTPS 示例使用系统提供的 `HttpClient`，不需要额外 Composer 依赖。若需要直接使用 Guzzle 的高级功能，可按下例在插件目录独立声明；其他第三方 SDK 同理，不设包名白名单。对应 `composer.json`：

```json
{
  "name": "example-team/mail-example",
  "description": "示例邮件通道依赖",
  "type": "project",
  "license": "Apache-2.0",
  "require": {
    "php": ">=8.3",
    "guzzlehttp/guzzle": "^8.2"
  },
  "config": {
    "allow-plugins": false,
    "optimize-autoloader": true,
    "sort-packages": true
  }
}
```

在开发机的插件目录中首次解析依赖、生成锁文件：

```bash
cd plugins/mail/example_mail
composer update --no-dev --no-scripts --no-plugins --prefer-dist --optimize-autoloader
composer validate --strict
```

后续构建及发行流水线按锁文件安装，不重新选择版本：

```bash
composer install --no-dev --no-scripts --no-plugins --prefer-dist --optimize-autoloader
composer validate --strict
```

源码仓库保存 `composer.json` 和 `composer.lock`，`vendor/` 可由 Git 忽略；**交付包必须带上生产 vendor**。内置 SMTP 即按此规则在 `plugins/mail/smtp/` 独立管理 `phpmailer/phpmailer`。客户安装时只部署已构建并审核的文件，不执行 Composer。

宿主验证安装状态、包摘要及锁定版本后加载本插件的 `vendor/autoload.php`；插件发送类直接 `use` SDK，无需添加启动钩子。生产依赖和锁文件均参与包摘要，依赖变化需要增加包版本、审核升级并完整重启所有 Worker。

构建和审核要求：

- `vendor/` 固定在插件目录内，不使用符号链接或越界自动加载路径。
- 禁止 Composer 安装扩展、插件根 `scripts`、根 `autoload.files`、根 PSR-0/classmap；如果需要根 PSR-4，只能映射本插件命名空间。SDK 自身的自动加载文件随依赖审核，仍属于受信 PHP 代码。
- 不将开发工具打包进生产 vendor，锁文件中的生产依赖与安装清单必须一致；保留依赖许可证。当前单包最多 10,000 个文件、100 MiB。
- 独立目录只隔离文件，**不能让 PHP 同一进程中的同名 SDK 不同版本同时工作**。相同包名、精确版本和来源标识可复用；版本不同则拒绝加载，不由加载顺序决定使用哪个版本。
- 遇到冲突，优先选兼容的共同版本。确需不同版本时，须在构建阶段同时隔离依赖包身份及 PHP 命名空间，并重新审核；当前宿主不提供自动重写 SDK 或进程隔离功能。

### 命名约定

| 项目 | 规则/示例 |
| --- | --- |
| 插件 ID | 小写字母开头，仅小写字母、数字、下划线，最多 64 个字符；例如 `example_mail` |
| 唯一性 | ID 在本系统所有插件类别中唯一，不能覆盖其他开发者或内置插件 |
| 插件类别 | 固定为 `mail` |
| 入口文件 | 固定为 `MailAdapter.php` |
| 入口类 | 固定为 `MailAdapter` |
| 入口命名空间 | `plugins\mail\example_mail`，随自己的插件 ID 修改 |

## 三、填写插件信息

创建 `plugin.json`，以下是完整示例：

```json
{
  "manifest_version": "1.0",
  "id": "example_mail",
  "category": "mail",
  "name": "示例邮件平台",
  "version": "1.0.0",
  "publisher": "example-team",
  "license": "Apache-2.0",
  "requires": {
    "core_min": "0.1.0",
    "core_max_exclusive": "1.0.0",
    "php_min": "8.3",
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

修改 `id/name/publisher/license` 为自己的实际信息。插件版本使用三段式，例如 `1.0.0`、`1.0.1`；`core_min` 包含下限，`core_max_exclusive` 不包含上限，填写实际验证过的系统版本范围。

- 协议统一为 v1，对应 `manifest_version/plugin_sdk/mail_sdk` 的值为 `1.0`。
- `network.transport`：SMTP 使用 `smtp`，HTTP 邮件 API 使用 `https`。
- `capabilities`：当前仅支持 `mail.send=true`。
- `configuration_schema` 固定为 `configuration.schema.json`。
- `permissions/frontend/migrations` 当前均填写空数组，`data_policy` 固定为 `retain`。

无需添加自定义控制器、安装脚本或空的 `install()`、`uninstall()` 方法，系统负责插件登记和生命周期管理。

## 四、声明用户配置

创建 `configuration.schema.json`。下面三个字段用于后面的 HTTPS 示例，用户安装后在邮件通道配置页填写：

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

### 字段规则

每个字段必须声明 `key/label/type/required`；`key` 使用与插件 ID 相同的命名规则。配置作用域固定为 `connection`，最多声明 16 个字段。声明文件只定义表单，不填写真实账号、密钥或生产配置。

| 类型 | 必须补充的属性 | 可选属性 |
| --- | --- | --- |
| `text`、`url` | `max_length`，1～2048 | `placeholder/default/description` |
| `password` | `max_length`，1～2048 | `placeholder/description`，不允许 `default` |
| `integer` | 整数 `minimum/maximum` | `default/placeholder/description/unit` |
| `boolean` | 无 | 布尔 `default/description` |
| `select` | `options`，每项为字符串 `label/value`，1～50 项 | `default/placeholder/description` |

默认值必须符合类型、范围和枚举限制；配置文件不使用远程 `$ref` 或自定义 JavaScript。尽量填写占位提示，字段说明放在 `description`；明确的单位填写 `unit`，例如“秒”。

**密码、授权码、API 密钥必须使用 `password` 类型。** 系统加密保存，不向用户回显原值；修改配置时留空保留旧值。每个插件在默认列表中直接配置、启用或禁用；每次调用只传入当前插件的配置，不能跨调用缓存账号。

## 五、实现两个方法

入口类实现系统提供的公共 `MailAdapterInterface`，不需要自行定义或复制这些接口类：

| 公共类型 | 命名空间 |
| --- | --- |
| `MailAdapterInterface` | `app\shared\plugin\contract` |
| `Configuration` | `app\shared\plugin\dto` |
| `MailMessage`、`MailResult` | `app\shared\plugin\dto\mail` |

| 方法 | 参数 | 返回值 |
| --- | --- | --- |
| `validateConfiguration()` | `Configuration $configuration` | `Configuration` |
| `send()` | `MailMessage $message, Configuration $configuration` | `MailResult` |

`validateConfiguration()` 校验平台特有规则、关联条件和默认值，发现错误抛出不含敏感内容的异常；不访问网络或发送邮件。`send()` 将标准邮件转换为平台请求，执行一次发送并返回结果。

### 完整 HTTPS 入口示例

创建 `MailAdapter.php`：

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

**这是示例平台协议，不是通用邮件平台 API：** 示例使用 Bearer 鉴权和 POST JSON，约定 HTTP 202 表示已接收，400/401/403/422 明确表示拒绝，其余响应或网络异常按 `unknown` 处理。示例地址不对应实际邮件服务商。

HTTP 快捷方法与完整参数说明见 [公共 HTTP 请求指南](http-client.md)，包括 GET、POST、PUT、DELETE、表单及通用请求。该工具是推荐能力，开发者也可使用自己引入的 SDK。

接入真实平台时，按其文档替换 URL、鉴权、请求字段和结果判定；有些平台需要检查 JSON 中的业务状态，不能只看 HTTP 200。发布前明确允许访问的服务主机、响应大小和超时限制。平台支持幂等参数时传入 `$message->key`，不能自建新的请求编号。

### SMTP 开发参考

系统自带的完整示例位于运行目录 `plugins/mail/smtp/`，使用 `phpmailer/phpmailer`。开发新的 SMTP 插件时，可以参考其中的配置声明和发送逻辑，复制后修改自己的 ID、命名空间及内部类引用。

SMTP 类通道通常声明服务器、端口、加密方式、认证开关、账号、密码或授权码、发件邮箱、发件名称、回复邮箱和超时。每次发送新建 PHPMailer 实例；使用 UTF-8，按 `text/html` 设置正文；验证 TLS 证书并在完成后关闭连接。服务器可能已接收 DATA，但确认前断线时应返回 `unknown`。

## 六、输入和返回约定

### 通道配置

通过 `$configuration->values` 读取配置，示例为：

```php
$config = $configuration->values;
$apiUrl = $config['api_url'];
$apiKey = $config['api_key'];
```

配置包含当前通道必要的明文凭据，仅在本次调用中使用。不要把它保存到静态属性、全局变量、日志或文件；不要通过 JSON 序列化整个 `Configuration` 来构造发送请求，必须显式读取需要的字段。

### 邮件内容

| 字段/方法 | 说明 |
| --- | --- |
| `$message->key` | 小写 UUID 请求键，同一次发送的重试保持不变 |
| `$message->recipient` | 单个有效收件邮箱，最多 254 字节 |
| `$message->subject` | 非空主题，最多 200 个字符，不含控制字符 |
| `$message->text` | 必填纯文本正文，最多 262144 字节 |
| `$message->html` | 可选 HTML 正文，最多 262144 字节；仍需提供纯文本正文 |
| `$message->messageId()` | 本次邮件的稳定标识，不能替代远端幂等机制 |

邮件内容已由系统校验。插件按平台要求转换字段，不重新选择收件人、不自行拼接其他会员信息。

### 发送结果

必须返回 `MailResult`，第二个参数使用当前邮件的 `messageId()`：

```php
return new MailResult('accepted', $message->messageId());
```

| `status` | 何时使用 | 后续行为 |
| --- | --- | --- |
| `accepted` | 平台明确确认接收 | 不重复发送；最终送达以收件邮箱/平台记录为准 |
| `failed` | 按平台协议能够明确确认未接收或拒绝 | 返回失败，不在插件中自动重试 |
| `unknown` | 超时、断线、异常，不能确认是否接收 | 核查后再处理，不自动重发或切换通道 |

不得把异常文本、原始响应、密码或邮件正文作为结果返回；系统统一生成对用户展示的安全提示。相同请求的重复调用由系统处理，插件无需自行建数据库表记录发送任务。

## 七、本地联调

将自己的目录放到测试实例的 `plugins/mail/` 下，以下命令在系统运行目录执行：

```bash
php webman business-plugin:validate example_mail
php webman business-plugin install example_mail
php webman business-plugin enable example_mail
php start.php restart -d
```

这组命令用于首次登记新插件，结构校验不会发送邮件。执行前确认代码来源，安装和重启只在自己的测试实例进行。

进入“系统管理 → 通道管理 → 邮件通道”，在默认列表中找到自己的插件，点击“配置”，填写后保存；点击操作列“测试发送”，填写测试收件邮箱。测试需要邮件通道查看及测试发送权限，每个通道测试间隔至少 30 秒；通道尚未启用也可测试，启用后才允许业务发送。

开发期间修改包文件后，按现有状态更新安装记录。当前已启用插件先停止接单，并在后台禁用引用它的通道、完成发送收尾：

```bash
php webman business-plugin drain example_mail
# 禁用引用通道并完成收尾后，部署本次插件文件。
php webman business-plugin:validate example_mail
php webman business-plugin upgrade example_mail
php webman business-plugin enable example_mail
php start.php restart -d
```

已禁用或已安装未启用的插件可直接执行审核后的 `upgrade`，不要再对它执行 `drain`。文件变更都会改变包摘要，不能直接改数据库绕过检查；更新后重新保存通道配置、测试，再启用业务通道。

## 八、交付内容

交付自己的插件目录；如用压缩包提交，目录内保留完整结构和命名。该开发包用于审核与联调，应用中心正式分发由平台的发布流程处理；本文不提供或假设存在后台任意上传插件的入口。

推荐交付目录：

```text
example_mail/
├── plugin.json
├── configuration.schema.json
├── MailAdapter.php
├── composer.json               有 SDK 时必需：本插件依赖
├── composer.lock               有 SDK 时必需：锁定版本
├── vendor/                     有 SDK 时必需：生产构建产物
├── app/                        可选，仅本插件内部类
├── README.md                   配置和使用说明
├── CHANGELOG.md                包版本变更
└── LICENSE                     实际插件许可证
```

`README.md` 说明以下信息：

- 插件名称、ID、作者、包版本与测试过的系统版本。
- 对应邮件平台、申请账号方式、官方 API 文档，以及需要开通的发送权限。
- 配置项含义、测试方法、结果状态和常见错误。
- 使用的第三方依赖及许可证；新增依赖列出包名与版本要求。
- 版本升级是否改变配置字段，存量配置如何处理。
- 开发者维护联系方式，以及验收记录。

仅交付插件文件，不包含系统源码、宿主 `vendor/`、`node_modules/`、`.env`、私钥、真实账号、邮件正文或运行日志。交付后修改文件需要重新审核并增加包版本；不要更换已使用的插件 ID。

## 九、交付前自测

| 项目 | 预期 |
| --- | --- |
| 插件结构 | 清单、命名空间、接口及版本校验通过，后台可选自己的插件 |
| 配置校验 | 缺少必填字段、无效邮箱或不支持的地址可以安全报错 |
| 正常发送 | 平台确认接收；另行检查最终收件情况 |
| 明确拒绝 | 返回 `failed`，不泄漏原始错误或凭据 |
| 超时/断线 | 无法确认是否接收时返回 `unknown`，不自行重发 |
| 重复请求 | 同一次请求不产生重复发送，平台幂等参数使用原请求键 |
| 多通道 | 不串用账号、收件人、发件人或连接 |
| HTTPS/TLS | 证书校验开启，设置超时，不跳转到未批准的地址 |
| 交付包 | 不包含真实配置或秘密；依赖锁定且生产 vendor 完整，使用说明完整 |

分别记录模拟测试和真实平台联调结果。没有真正连接平台、没有检查收件箱时，不把结果写成“真实投递验收通过”。

## 十、常见问题

**后台看不到自己的插件**：检查 `mail` 类别、ID 与命名空间、系统兼容范围、结构校验、安装/启用状态及是否完整重启。文件变更后检查是否完成审核升级。

**保存失败**：检查字段类型、范围、私密字段及 `validateConfiguration()` 返回值。规范化后的键仍需在配置声明中定义。

**请求返回 `unknown`**：先查平台发送记录或测试邮箱。即使异常发生，也可能已发送，不能换一个请求键反复试发。

**是否可以添加附件、群发或回调**：当前 v1 邮件接口只开放单收件人文本/HTML 发送，尚未开放附件、群发、模板管理、投递查询或自定义回调。需要新增能力时向平台提出接口扩展需求。

**是否需要实现业务通知规则**：不需要。系统提供收件人和邮件内容，插件执行平台发送。验证码生成、模板策略、客户筛选和订单通知触发由系统负责。

**是否可以直接操作系统数据库或注册路由**：不可以。插件仅使用公共接口、自己的内部类和插件自身声明并审核通过的客户端，不访问系统内部模型、其他插件、环境变量、文件或进程调用。插件以受信 PHP 代码运行，提交前须接受代码审核。
