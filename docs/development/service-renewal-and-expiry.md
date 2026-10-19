# 云服务器续费与到期处理

## 当前实现

后台「业务管理 → 云服务器」以及会员 PC、独立移动端的云服务器列表已接入续费。续费创建普通 `renewal` 订单，订单项数量固定为 1，关联原 `service_id`；付款继续使用订单余额付款，余额不足时复用在线充值。不会创建新实例、重新开通或占用新库存。后台创建订单与代会员余额付款是独立权限和确认步骤。

- 续费周期取原实例所在可用区当前发布的周期；按该实例原选配和当前发布版本价格重新计价。
- 排除所有非周期费用，特别是一次性开通费；原购买数量不会乘入单实例续费。
- 原方案停止新售仍可按最后发布版本续费。配置被移除、没有合法发布版本或原配置已不符合当前配置约束时明确拒绝，不能猜价或改变实例配置。
- 新报价不回写历史订单和首次供应绑定；创建订单时再次核对期望金额，之后付款执行该订单封存金额。
- 未到期从原到期时间延长；已到期从付款时间起算。月份按月末钳制规则计算，付款时固定绝对目标，重试不按当前时间重复累加。
- 同一实例只允许一笔未结束续费；未付款订单可以在会员订单详情取消，再重新选择周期。
- 本期为主动确认付款的续费，不读取余额自动扣费；旧 `auto_renew_enabled` 不是会员扣款授权。

## 到期策略

| 时点 | 动作 |
| --- | --- |
| 到期前 7、3、1 天 | 在对应区间登记一次提醒，复用消息配置及发送队列 |
| 到期时 | 标记 `expired`，登记到期暂停任务 |
| 已付续费正在执行 | 等待续费确认，未提交的旧暂停任务作废 |
| 暂停成功 | 保持 `expired`，发出服务暂停通知，资源与库存仍保留 |
| 续费成功 | 写入已确认的目标到期时间；若此前确实因到期暂停，登记恢复任务 |
| 恢复确认 | 服务恢复为 `active`；恢复未确认时保留处理状态 |
| 结果不明或插件能力不可用 | 只查询原请求或转 `attention`，后台核查后重试 |

本期不自动销毁过期实例、释放库存或删除数据。延期处理不等于重新创建资源。没有自动扣费授权，也不以自动续费名义使用会员余额。

提醒按照剩余时长分档：超过 3 天且不超过 7 天、超过 1 天且不超过 3 天、未到期且不超过 1 天。进程晚启动只发送当下档位，不补发已错过的多个档位。通知遵循后台已启用的消息配置，登记事件不代表短信、邮件已送达。

## 数据、事务与竞争

新增前向迁移 `20261018000300_create_service_lifecycle_tasks.php`，任务表为 `service_lifecycle_tasks`。不修改已执行迁移、不重写历史订单和服务到期时间。

- `renew`：待付款记录与新订单原子提交；钱包、付款、固定续期目标和排队同事务提交。
- `suspend` / `resume`：系统任务，关联原实例及预期到期时间。
- `notice7` / `notice3` / `notice1`：提醒去重记录，与消息登记同事务。
- 状态：`unpaid → queued → processing → waiting/succeeded/attention`；失效的系统任务为 `cancelled`。
- 固定锁序：订单（如有）→服务→任务。供应调用在提交本地事务之后执行。
- 同一服务只允许一个已领取或外部结果尚未确认的动作。任务先领取租约，再记录提交意图；领取但尚未外呼也阻止其他动作并发。
- 180 秒随机令牌租约；旧令牌或过期回写不生效。首次提交意图存在后，超时及重试只能查询。
- 查询采用有上限退避，最多 20 次转人工核查。后台重试保留原请求及提交事实。
- 到期任务执行前核对原到期时间和已付续费；恢复执行和回写重新检查期限，避免迟到任务误开通已再次到期实例。

服务列表批量投影 `lifecycle_action/status/error`、`renewal_order_id`、`renewal_payment_pending`、`can_renew`。暂停任务不能遮住已有待付续费订单的支付入口。租约、连接密文、供应参数与原始响应不返回浏览器。

## 接口及权限

| API | 用途 |
| --- | --- |
| `POST /api/console/v1/services/{id}/renewal-quote` | `months=0` 获取周期，否则报价；仅本人 |
| `POST /api/console/v1/services/{id}/renewals` | 期数、期望整数分金额、固定请求键；创建本人订单 |
| `POST /api/console/v1/orders/{id}/cancel` | 幂等取消本人未付订单 |
| `POST /api/admin/v1/service-instances/{id}/renewal-quote` | 独立续费权限及实例类型查看权限 |
| `POST /api/admin/v1/service-instances/{id}/renewals` | 创建原会员的待付订单，不直接扣款 |
| `POST /api/admin/v1/service-instances/{id}/retry-lifecycle` | 重新排队待人工核查任务 |

操作权限为 `admin.services.cloud-servers.renew`、`admin.services.cloud-servers.lifecycle`；后台付款仍需订单付款权限。全部 POST 校验同源及输入，会员归属由服务端会话确定。操作审计复用后台日志，保留 IP 和位置快照。

契约源：[service-lifecycle.v1.yaml](../../contracts/openapi/service-lifecycle.v1.yaml)。已有服务、会员订单响应同步新增公开状态；API 文档必须重新 build/check。

## 供应插件边界

可选 SDK：`ProviderLifecycleInterface::lifecycle()` 与 `queryLifecycle()`，使用 `LifecycleRequest` 和 `LifecycleResult`，不要求其他提供者增加空方法。

- 动作固定为 `renew/suspend/resume`，包含原供应 ID、首次固定主机名、固定请求键、续费月份、原到期时间及绝对目标到期时间。
- 续费 `succeeded` 必须确认同一目标时间，否则核心不延长。
- 对真正售卖订阅的上游，插件须实现上游续订及原请求查单；不能用本地改时间冒充已向上游付费。
- 对底层自管资源，计费期限由核心拥有。魔方云 1.3.0 按此方式接入：续费只校验原资源身份和可用状态，不创建上游采购订单；到期调用 `POST /v1/clouds/{id}/suspend`（`type=due`，不释放 GPU），恢复调用 `unsuspend`，随后读取原资源确认状态。
- 宿主复用原开通任务的加密连接和包摘要；不跟随当前默认连接、密码或映射变化。插件代码/摘要不一致时停止自动操作，不静默用更新后的代码重放存量任务。存量资源切换新插件需另行核查迁移，不能直接修改历史供应记录解除校验。
- HTTP 路径模板在清单中精确审核，只允许 `HttpRequest.pathParameters` 的正整数参数（1～2147483647），无任意 URL 或通配路径权限。参数名须与模板完全相同。

魔方云官方说明其版本对应 API 文档位于已安装后台：[官方 API 文档说明](https://docs.idcsmart.com/docs/魔方云/10.%20网站管理与升级/魔方云API文档)。本次端点参考项目已使用的同协议实现并使用模拟响应验证；仍须使用目标魔方云版本核对实例详情及暂停状态字段，再进行真实供应验收。

## 运行与验证

升级前备份数据库，执行 `php webman system:migrate --force`；新增 `service-lifecycle` 常驻进程，必须完整 `php start.php restart -d`。每 10 秒扫描提醒、到期和小批任务，持久表是事实来源，不依赖内存完成履约。

PC 和独立 mobile 主题均需 `pnpm build:template`，随后 `php webman website:publish` 发布当前主题摘要，再完整 restart。客户服务器运行发行产物，无需 Node.js。

验证入口：

```bash
cd backend
vendor/bin/phpunit tests/integration/ServiceRenewalLifecycleTest.php tests/integration/MagicCloudLifecycleGatewayTest.php
HOWSTACK_MYSQL_TEST=1 vendor/bin/phpunit tests/integration/ServiceRenewalLifecycleTest.php
HOWSTACK_RENEWAL_HTTP_ACCEPTANCE=1 php tests/acceptance/service-renewal-http.php
```

HTTP 验收仅本地开发库，建立并清理临时会员、无真实上游的实例和未付订单；验证认证、越权、RBAC、同源、统一响应及真实取消，不执行扣款或供应操作。钱包扣款、原实例续期、并发领取、超时查单、暂停恢复与消息幂等在隔离 MySQL 验证。三端 Playwright 脚本使用模拟 API，不能替代真实财务及供应商验收。
