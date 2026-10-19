# 提供者自动开通契约

提供者继续实现 `app/shared/plugin/contract/ProviderAdapterInterface`，目录插件只声明 `cloud_server.provision=false` 即可。提供真实开通能力时，还须实现 `ProviderProvisioningInterface` 并声明 `cloud_server.provision=true`。清单与接口不一致的包会被宿主拒绝。`provider.product_types` 必须包含交付产品类型，`provider.type_version` 必须与标准配置 `contract_version` 一致；当前云服务器为 `2.0.0`，不是供应商原生 API 的版本号。

```php
public function provision(ConnectionContext $context, ProvisionRequest $request): ProvisionResult;
public function queryProvision(ConnectionContext $context, ProvisionRequest $request): ProvisionResult;
```

- `ConnectionContext`：付款时固定的原连接配置；不随后台修改切换目标。
- `ProvisionRequest::requestKey`：每台实例的固定请求标识，恢复和重试保持不变。插件应优先使用供应商原生幂等键；不支持幂等键时必须支持按该标识查单。
- `configuration`：封存的标准交付配置，`quantity=1`，包含外部资源对应和 CPU、内存、磁盘、上下行、IP、周期。
- `parameters`：声明式映射计算的供应参数。插件需进一步白名单处理，不能允许覆盖请求标识、实例数量或密码。
- `password`：创建调用期间的初始密码；查询时为空。禁止保存、打印、写日志或放进返回对象。
- `externalId`：已确认的供应实例 ID；为空时也必须能够按固定请求标识查询。查询不得创建实例。

`ProvisionRequest` 的 JSON 与调试输出默认脱敏；插件自己访问属性后仍须遵循上述限制。

## 结果语义

| status | 必要语义 |
| --- | --- |
| pending | 供应方已受理，尚未确认可用；尽量返回 externalId |
| active | 确认实例可用，必须有 externalId，可返回 addresses IP 列表 |
| failed | 供应明确确认没有创建任何资源，不能带 externalId |
| unknown | 无法确定是否受理或创建；不能假装 failed |

HTTP 超时、500、格式异常、查不到记录和多个匹配都不能自动转为 failed。已有外部 ID 的故障实例也不能当作“未创建”；保持 pending/unknown，由业务管理员核查。

宿主首次提交前写入意图，后续恢复仅调用查询。供应商没有幂等创建保证时，插件不能自行重发 POST。查询须精确核对外部标识或原请求标识，不能把列表中的第一条记录当作匹配。

仅在明确 failed 或管理员通过独立权限核实未创建后，才允许原请求重新创建。退款与库存由宿主订单、财务模块处理，插件不得写这些表或直接完成退款。

所有网络请求继续使用 `ScopedHttpClientInterface` 和清单中的固定端点，TLS 校验及响应限制不变。返回值只携带标准结果和合法 IP，原始供应商响应留在本次调用内。插件升级变更包摘要后，宿主会停止旧任务重放，不能用新包静默解释旧绑定。

宿主状态流转、部署、人工核查与验证边界见 [自动开通说明](../development/cloud-provisioning.md)。默认生成器仍生成只读目录插件；开发者明确完成创建与查询后，再启用生产声明。

## 续费与到期的可选契约

供应入口可实现 `ProviderLifecycleInterface` 的固定 `lifecycle` 与 `queryLifecycle` 方法，接收 `LifecycleRequest`，返回 `LifecycleResult`。renew成功必须返回已确认的绝对目标时间；未知只能查单。底层自管资源与上游订阅采购分别实现，不得把本地延长时间冒充第三方付费。详情及DTO字段见[续费与到期处理](../development/service-renewal-and-expiry.md)。

## 单实例运维扩展

可选实现 `ProviderInstanceOperationsInterface` 提供开关机、重启、重装和重置密码；不改变必需的供应入口。能力声明、短期凭据、原任务查询及魔方云协议说明见 [云服务器实例运维](../development/cloud-instance-operations.md)。供应商受理和最终完成必须分开，不得因超时重复发送破坏性动作。

## VNC 远程控制台

提供者可选实现单方法 `ProviderVncInterface`，宿主负责认证、独立权限、限流、原实例身份与临时连接保护。SDK、魔方云协议、直接 WSS 网络要求和三端验收说明见 [云服务器 VNC 远程控制台](../development/cloud-vnc-console.md)。
