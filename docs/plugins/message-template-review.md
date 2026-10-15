# 短信与邮件模板审核契约 v1

模板提交和审核查询是短信、邮件插件的可选能力。入口仍在插件根目录，同一个 `SmsAdapter.php` 或 `MailAdapter.php` 按需实现接口，不添加 `api/` 目录、第二入口或新的 JSON 能力声明。

## 1. 可选接口

| 能力 | 实现接口 | 固定方法 |
| --- | --- | --- |
| 提交模板审核 | `app\shared\plugin\contract\SubmitTemplateAdapterInterface` | `submitTemplate(TemplateSubmission, Configuration): TemplateSubmissionResult` |
| 查询审核状态 | `app\shared\plugin\contract\QueryTemplateReviewAdapterInterface` | `queryTemplateReview(TemplateReviewReference, Configuration): TemplateReviewResult` |

两项独立：平台只提供查询接口时只实现查询能力；支持两项时同时实现。普通 `SmsAdapterInterface`、`MailAdapterInterface` 的 `send()` 保持原契约，无审核接口的插件不必提供空实现。宿主根据入口实际实现的接口识别能力，不能仅凭存在同名方法或在清单增加字段假装支持。

```php
final readonly class SmsAdapter implements
    SmsAdapterInterface,
    SubmitTemplateAdapterInterface,
    QueryTemplateReviewAdapterInterface
{
    // validateConfiguration()、send() 和下面两项方法均在本入口实现。
    public function submitTemplate(TemplateSubmission $template, Configuration $configuration): TemplateSubmissionResult
    {
        // 按厂商文档转换类型、占位符、签名和请求键，提交后取得可查询的外部引用。
        // 这里只展示返回类型；实际实现必须调用并校验平台响应，不能固定返回成功。
        return new TemplateSubmissionResult('accepted', $providerReference);
    }

    public function queryTemplateReview(TemplateReviewReference $reference, Configuration $configuration): TemplateReviewResult
    {
        // 平台确认通过后，返回原申请引用和实际可发送的模板 ID。
        return new TemplateReviewResult('approved', $reference->providerReference, $providerTemplateId);
    }
}
```

这是接口片段，省略了命名空间、use、发送、配置校验及真实平台调用。参考现有发送插件组织完整入口。不得通过提交或查询方法发送短信、邮件，或修改会员、订单等业务状态。

## 2. 提交参数

`TemplateSubmission` 位于 `app/shared/plugin/dto/template/`。

| 字段 | 含义 |
| --- | --- |
| `key` | 宿主预留的稳定请求键，1–64 个字母、数字、下划线或连字符；同次申请重用，不代表远端一定支持幂等 |
| `channel` | `sms` 或 `mail` |
| `name` | 模板名称，最多 128 个字符 |
| `purpose` | `verification/notification/marketing/system`；插件转换为厂商分类 |
| `content` | 静态模板正文，使用 `{{name}}` 占位符；短信最多 2048 字符，邮件正文最多 64 KiB UTF-8 字节 |
| `subject` | 邮件必填标题，最多 256 字符；短信为空 |
| `format` | `text` 或 `html`；短信仅支持 `text` |
| `variables` | 按声明顺序给出的变量名称与静态示例，最多 32 项，每个示例最多 256 字符 |
| `description` | 模板用途说明，最多 1000 字符 |

所有正文与标题中出现的占位符必须声明。插件负责转为 `${name}`、数字序号等厂商格式；按顺序转换的平台不能自行重新排序变量。静态示例不使用真实会员资料、登录密码或实时验证码。DTO 的 JSON 和调试输出脱敏，任务保存宿主持久化的申请引用，而非序列化 DTO。

签名、发件身份、区域和平台认证沿用本次 `Configuration`，不在模板申请中另传凭据。厂商特有的长度、内容、变量、签名及审核材料要求由插件明确验证，不能仅因通过通用 DTO 校验就宣称平台一定受理。当前通用输入不承载任意上传材料；需要附件资质的平台须另行定义受控材料引用能力。

## 3. 提交结果

| `TemplateSubmissionResult.status` | 含义与处理 |
| --- | --- |
| `accepted` | 平台明确受理，必须提供 `providerReference`；不表示审核通过 |
| `failed` | 有明确证据确认提交未成功，例如平台拒绝不合法模板 |
| `unknown` | 超时、断线或响应不能确认结果；不可自动再次提交或换通道 |

`providerReference` 是平台可查询的申请或模板标识。申请 ID 与实际发送模板 ID 可以不同。`reason` 仅返回安全说明，不能直接复制厂商原始响应、凭据或堆栈。若平台在超时后无法通过请求键或其他凭证找回申请，保留未知申请并由管理员在原平台核实，不伪造引用或直接标记失败。

宿主门面对执行过程中未处理的插件异常抛出带原异常链的 `PluginException(reason=result_unknown)`。申请业务同样将其记录为未知结果，不能因 HTTP 请求报错就把原申请当作未受理。能力不支持、通道失效等执行前检查仍明确拒绝，不调用外部接口。

## 4. 查询参数和结果

`TemplateReviewReference(channel, providerReference)` 只携带原审核引用。必须使用申请保存的原通道、平台账号和通道修订，不使用当前默认通道，也不能相信浏览器任意提供的其他账号模板 ID。

| `TemplateReviewResult.status` | 含义 |
| --- | --- |
| `pending` | 平台明确表示仍在审核 |
| `approved` | 平台明确通过，必须返回实际发送所用 `providerTemplateId` |
| `rejected` | 平台明确驳回，给出安全原因 |
| `not_found` | 原平台账号明确返回审核对象不存在 |
| `unknown` | 网络、认证、限流、响应或状态映射无法确认；不解释为驳回或不存在 |

结果中的 `providerReference` 必须与查询输入相同。查询只读，禁止修改模板、重新提交或轮换账号。返回的发送模板 ID 仅在 `approved` 时作为通过结果处理；平台审核通过不能代替本系统的模板变量、权限和消息启用校验。

## 5. 宿主调用与后台能力识别

宿主内部通过 `MessageTemplateReviewService` 调用：

```php
$submitted = $service->submit($originalChannelId, $channelRevision, $templateSnapshot);
$reviewed = $service->query($originalChannelId, $channelRevision, $savedReviewReference);
```

它沿用通道配置边界和已安装适配器加载器：提交需要启用通道；已停用通道可在配置修订未变且插件仍可用时查询原申请。包摘要变化、插件停用、通道修订变化或不存在的能力均拒绝执行；不接受任意方法调用。查询返回另一个申请引用时明确拒绝。

调用前，后台业务用例必须完成权限和对象归属校验，绑定并锁定本地模板修订、原通道及配置修订，持久化申请并预留请求键。不得在数据库事务中长期持有锁等待外部平台。提交后先保存受理或未知结果，不能将超时标记为已失败后立即再发。查询或审核通过不静默覆盖用户后来编辑的模板，也不自动替换已保存发送模板号。

`GET /api/admin/v1/channels/{category}/platforms` 增加公开字段：

```json
{
  "platform_available": true,
  "template_review": { "submit": true, "query": true }
}
```

后台可根据两项能力和 `platform_available` 判断相应操作入口，仍需实际操作权限。无能力的内置平台和其他类别均返回 false。这里的能力字段不是模板审核状态。

## 6. 本次交付与接入边界

已交付可选接口、DTO、加载能力识别、宿主调用门面及平台目录的能力字段；两类测试插件验证真实入口加载、方法调用、账号配置隔离和结果分类。当前官方发送插件没有新增厂商审核 API 实现，能力仍为 false，SMTP 也不宣称提供第三方审核。

尚未增加后台提交/查询 HTTP 操作、审核申请数据表或操作按钮。接入这些业务入口时按上一节实现持久化、权限及幂等；本次能力契约不代表完整后台审核流程已上线。

新开发者通过自己的平台文档实现一个或两个接口，补齐成功、驳回、超时、无此申请、其他通道与修订变化测试；修改插件包须审核升级并完整重启 Webman。普通发送能力与无审核接口的平台继续按现有协议工作。
