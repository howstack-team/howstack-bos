# howstack-bos 官网接入要求 v1

软件标识固定为 `howstack-bos`。客户端连接 howstack-website，使用授权码激活与安装 Ed25519 请求签名，不向官网传递本机管理员密码。

## 授权码激活（2026-10-15）

官网现为同级howstack-website。购买后复制授权码，在本机“授权码激活”填写；无需配对确认或官网登录，安装UUID和公钥自动读取。首次激活使用POST /api/client/v1/licenses/activate，随后仅通过安装签名进行核验，原授权码不持久保存。续费保持原码和绑定。

请求头为X-Howstack-Software、X-Howstack-Installation、X-Howstack-Public-Key、X-Howstack-Timestamp、X-Howstack-Nonce、X-Howstack-Signature；八行原文为HOWSTACK-CLIENT-V1、软件、UUID、大写方法、原始URI、body的SHA256、Unix秒、nonce，无末尾换行。窗口±300秒、nonce48位十六进制、TTL610秒。所有远程响应为字符串code，成功OK。

GET /connection返回安全上下文，POST /connection/disconnect解除绑定。GET /license-certificate只读；POST /license-certificate/refresh续签有限租约并同步已付续费。原connections、token、refresh和device_code调用已移除。社区无需商业激活，社区包检查仍使用安装持钥证明。

系统更新的登记、固定目标清单和报告分别是software-installation-operations、software-installation-operations/{id}/manifest、software-installation-operations/{id}/reports，与应用版本ID分开。应用中心协议为后续预留，不表示新官网应用权益/主题分发已实现。

许可公钥归trusted_license_keys，发行公钥归trusted_release_keys；本地源站8808及公开公钥仅用于开发，正式部署替换为受信生产源站/公钥。完整协议参见[官网客户端契约](../../../howstack-website/contracts/openapi/client.v1.json)及[接入方案](../../../howstack-website/docs/architecture/client-licensing-and-updates-integration.md)。

## 已接入：商业授权证书

`GET /api/client/v1/license-certificate` 只读最新证书；客户端通过 `POST /api/client/v1/license-certificate/refresh` 同步已付续费并续签有限租约。两者使用当前安装持钥签名，成功返回 `{code:"OK",message:"成功",data:{payload,kid,signature},request_id:"UUID"}`。

`payload` 为 Base64 编码的原始 UTF-8 JSON 字节，Ed25519 签名覆盖解码后的原始字节，不重序列化。示例载荷：

```json
{
  "schema_version": 1,
  "type": "software-license",
  "software_code": "howstack-bos",
  "installation_id": "00000000-0000-4000-8000-000000000001",
  "connection_id": "00000000-0000-4000-8000-000000000002",
  "license_no": "商业授权编号",
  "edition": "professional",
  "edition_name": "专业版",
  "status": "active",
  "revision": 12,
  "issued_at": 1791043200,
  "offline_policy": "online_lease",
  "offline_until": 1791648000,
  "grants": [
    {
      "capability": "analytics.advanced",
      "use_until": 1822579200,
      "updates_until": 1822579200,
      "limits": {}
    }
  ]
}
```

- 2026-10-13 起本机使用上述细粒度权益契约；旧 `expires_at` 和按版本配置的功能映射已移除。官网签发端须同步更新并重新签发，不能由本机把旧证书推导成新权益。
- 机器可读字段约束见 [证书载荷 Schema](../../contracts/licensing/v1/certificate.schema.json)，跨字段租约、唯一能力、原始签名及高水位由本机继续校验。
- `edition`、`edition_name` 只表达购买套餐和名称，不能隐式开启功能。社区实例无需商业证书；`status` 为 `active/expired/revoked`。
- `grants` 最多 128 项，能力标识唯一、最长 96 字符。每项必须显式包含 `use_until`、`updates_until`、`limits`；前两项为正整数 Unix 秒或明确的 null，null 分别表示永久使用和永久更新。额度为非负整数、最多 32 项；额外字段、重复能力及浮点额度拒绝。
- `revision` 为当前连接的单调递增正整数。载荷任何变化，包括签发时间、租约和权益，均须提高修订号；同修订只接受完全相同载荷。撤销后不能发送旧修订恢复权益。
- `offline_policy` 首期只允许 `online_lease`，`issued_at < offline_until <= issued_at + 604800`。普通租约建议 7 天，使用期限逐项判断，不再与全局使用截止混用。企业永久离线策略尚未开放。
- 客户端校验软件、实例、连接、预置公钥、原始字节签名和字段范围。更新清单中的短期 `expires_at` 继续保留，和商业证书期限不同。
- 签名证书可记录尚未接入的购买能力；本机只执行已登记且可用的能力，当前生产商业目录为空。上述 `analytics.advanced` 是协议示例，不代表已交付该扩展。
- 使用到期后阻止新的高级操作，保留历史信息。有效受理时生成的宿主加密证明可用于原范围内完成任务，不能授权新的循环批次或扩大业务范围。
- 对于软件更新清单，本机使用签名证书及更新权益选择版本上下文；目标包的下载资格仍由官网验证，使用到期不等于更新权益自动结束。
- 本机状态保存在既有加密 `official_platform_state` 中，无新增业务表。真实签发、试用、赠送、续费和撤销需由官网完成权益汇总；本机不复制销售账本。

## 已接入：软件更新检查（v1）

`GET /api/client/v1/software-updates?architecture=arm64&current_version=0.1.0&platform=darwin&role=system`，使用当前安装持钥签名。签名覆盖原始 query。

没有适配版本返回 `data:{available:false,current_version:"0.1.0"}`。有版本返回 `data:{available:true,current_version:"0.1.0",manifest:{payload,kid,signature}}`；载荷为：

```json
{
  "schema_version": 1,
  "type": "software-release",
  "software_code": "howstack-bos",
  "installation_id": "实例UUID",
  "connection_id": "连接UUID",
  "edition": "community",
  "version_id": 2,
  "from_version": "0.1.0",
  "version": "0.2.0",
  "channel": "stable",
  "changelog": {
    "added": ["新增版本提醒"],
    "improved": ["优化配置体验"],
    "fixed": ["修复更新问题"],
    "notices": ["更新前请备份数据"]
  },
  "force_update": false,
  "platform": "darwin",
  "architecture": "arm64",
  "role": "system",
  "first_published_at": 1791043200,
  "required_capability": "",
  "package_type": "incremental",
  "package_id": 123,
  "bytes": 123456,
  "sha256": "文件SHA-256的64位小写十六进制",
  "download_path": "/api/client/v1/software-packages/updates/123?architecture=arm64&current_version=0.1.0&platform=darwin&role=system",
  "issued_at": 1791043200,
  "expires_at": 1791043800
}
```

- 有效期最多 600 秒，目标版本严格高于当前版本，只提供 stable、已发布、扫描通过的 ZIP 包。
- 社区实例只获取社区包；商业实例依据当前有效授权获取对应级别包。已撤销/解绑授权不能下载商业包；合法使用到期时按版本首次发布时间对照updates_until。
- `from_version` 与实际代码版本一致；平台/架构支持具体值或 `universal`。发布包版本与软件级别不能由浏览器决定。
- 使用与应用发布一致的签名信封，不能把许可证 HMAC 的更新接口直接当作实例签名接口。
- `changelog` 必须包含 `added/improved/fixed/notices` 四个分类，各值为字符串列表，每类最多 50 项、每项最多 1000 字、整体 JSON 不超过 32KB；不接受旧字符串、额外分类或空白条目。前端隐藏空分类，按文本转义展示目标版本内容，不宣称是跨版本累计日志。应用发布清单遵循相同要求。
- `force_update` 必须为布尔值，以已验签清单为准；跨尚未安装的强制版本时，官网仍返回 `true`。本机提醒不允许“稍后提醒”隐藏强制要求，但必须由具有执行权限的管理员确认受控更新，不自动停止现有业务。网络失败保留最近已验证摘要。

## 已接入：系统包下载（v1）

`GET /api/client/v1/software-packages/{type}/{id}?architecture=...&current_version=...&platform=...&role=system`，实例认证、软件/级别、购买权益、发布状态和兼容范围在每次下载时重新校验。

`type` 为 `updates`（增量）或 `installations`（完整），与清单中的 `package_type` 一致。返回 ZIP 字节；对象存储跳转只允许客户端预配置的 HTTPS 域名，最多三跳，不向对象存储转发安装签名或身份头。任何失败返回非 2xx 和稳定业务码，不能以未授权或接口缺失冒充“已是最新版本”。

## howstack-bos 更新包格式

更新包根目录包含 `howstack-release.json` 及 `files/`，禁止自定义安装钩子或脚本、符号链接、绝对路径、`..`、秘密和客户运行数据。正常核心 PHP 文件仍属于受信的软件代码，客户端只执行固定安装流程。

```json
{
  "schema_version": 1,
  "software_code": "howstack-bos",
  "version_id": 2,
  "from_version": "0.1.0",
  "version": "0.2.0",
  "minimum_php": "8.4.0",
  "files": {
    "app/example.php": "文件SHA-256",
    "config/version.php": "文件SHA-256"
  },
  "remove": []
}
```

路径均相对 `backend/`。当前客户端白名单为：

- `app/`、`support/`、`vendor/`、`database/migrations/`。
- `public/admin/`、`public/console/`、`public/install-assets/app/` 的预构建资源。
- `themes/default/`、`config/`、`composer.json`、`composer.lock`、`start.php`、`webman`。

其中 `config/database.php`、`config/authentication.php`、`config/official_platform.php`、`config/client_ip.php` 不允许覆盖；`.env`、安装身份、客户 `plugins/`、上传物和运行目录同样禁止覆盖。历史迁移不得删除或修改字节。包中必须包含与目标版本一致的 `config/version.php`。应用包使用独立安装规范，不混入 Core 更新包。

数据库只执行项目已有的前向迁移命令。执行前备份受影响文件和完整数据库，暂停业务请求；迁移开始后出现异常保持维护状态，记录备份与人工恢复入口，不自动用旧代码运行已变更的数据库。完成后重新启动 Webman，使启动配置与自动加载生效。

## 应用发布包约定

- 当前开放自动安装的类别是符合 `provider` v1 规范的供应插件。ZIP 根目录直接包含 `plugin.json`、`configuration.schema.json` 和对应代码，不再包一层分类或插件目录。
- 应用 `code` 与插件清单 `id` 一致，使用小写字母开头、字母/数字/下划线组成，最长 64 字符；版本与签名发布清单一致。
- 发布清单 `permissions` 目前必须为空。宿主提供固定供应接口，不开放任意容器、核心表、HTTP 路由、安装脚本或插件迁移执行。
- 安装结果为“已安装”，需管理员在本地插件管理中启用；更新前停止接单、排空业务并停用。供应绑定仍需按已有规则重新测试。
- 官网可以展示、领取或购买主题及其他类别，但在相应宿主运行契约完成前不能自动安装。不要将目录展示等同于运行能力。
- 原生 PHP 包按受信代码审核。数字签名确认发行来源与完整性，固定接口与静态检查不能作为恶意代码隔离方案。

## 联调验收

- 社区安装无需授权可用；社区账号连接不占商业激活名额。
- 商业证书校验签名、软件、实例、连接、级别、期限；复制到其他实例、伪造公钥、过期或撤销均不能启用高级功能。
- 社区、专业、企业更新各自只接收适配包；无适配版本、接口缺失、网络错误分别展示。
- 清单篡改、下载超限、摘要错误、ZIP 越界、重复任务、运行中崩溃和迁移失败得到处理。
- 免费应用需官网确认领取，付费应用在官网确认付款；返回本机后重新查询权益，不根据浏览器跳转授予权限。

当前官网已实现软件订阅及系统发行协议；应用商店权益和主题分发仍待接入，不能把本机应用中心页面视为官网业务已交付。
