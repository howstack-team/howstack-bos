# 云服务器实例运维

## 已接入范围

后台、PC 会员中心及独立移动会员中心在实例列表的「管理」入口提供：开机、正常关机、正常重启、重装系统、重置实例登录密码。订阅状态与电源状态分开；电源显示最近确认结果和确认时间，不作为实时监控。

重装需要选择镜像、新密码、二次密码确认并确认系统盘数据会被清空。固定保留数据盘，不开放强制关机、硬重启、数据盘格式化或磁盘缩容。

## API 与权限

- 会员：`GET/POST /api/console/v1/services/{id}/operations`。
- 后台：`GET/POST /api/admin/v1/service-instances/{id}/operations`。
- GET 返回原插件支持的动作、满足原实例配置的镜像、忙碌标志与最近 20 条运维记录。实例详情及列表独立返回 `power_status/power_checked_at/installed_image/initial_password_available`。
- POST 请求包含 `action/request_key/acknowledged`；重置和重装增加 `password`，重装增加本地 `image_id`。动作值为 `power_on/power_off/reboot/reinstall/reset_password`。
- 所有写入要求认证、同源、服务端校验及对象归属。会员只操作本人；后台还要求实例查看权限及对应的 `.power`、`.reinstall`、`.reset-password` 操作权限。角色权限分配已登记，前端能力结果按权限过滤。
- 后台「重试处理」使用已有 `.lifecycle` 权限，只恢复原请求查单。明确失败的任务不自动重试，用户可以重新确认建立新请求。
- 完整输入、响应和错误信封以 `contracts/openapi/service-operations.v1.yaml` 为准。

## 任务与敏感信息

1. HTTP 请求只在本地事务中登记 `service_lifecycle_tasks`，复用续费、到期暂停的服务行锁和任务租约。
2. 同一实例已有 queued/processing/waiting/attention 任务时不受理其他运维。互斥任务短暂延后，让出批次，避免阻塞其他实例。未付款续费订单不会被当成已执行任务。
3. 请求键按认证身份、实例和用户请求键形成固定作用域；重复请求返回原任务，动作、镜像或密码不同则冲突。
4. 新密码限制为 8–64 位可打印 ASCII 非空格字符。只保存短期密文和用于幂等比对的慢哈希；密文有效期 24 小时。
5. 外呼意图在供应调用前持久化，同时清除数据库密码密文；只有该次执行内存可以解密。查单不携带密码。过期待执行密文由定时任务清理。
6. 新密码不进入订单、普通详情、React Query 缓存、URL、浏览器持久存储或审计。重装/重置受理后关闭原初始密码查看入口，按实例隔离，不影响同一订单其他实例。
7. 供应受理只显示等待确认，最终结果用供应任务详情确认。结果未知或进程租约恢复只查原任务，不重复发送开关机、重启、重装或重置请求。20 次未确认后转人工核查。
8. 重装和到期不能并发。已经提交的重装晚于到期完成时不会把订阅改成使用中，到期暂停任务继续按同实例互斥执行。

## 镜像与历史绑定

重装读取购买时的不可变销售版本，只允许原可用区、原供应映射中存在且满足 CPU、内存、架构和系统盘容量的密码登录镜像。浏览器不接收或提交供应镜像 ID。

连接、供应实例 ID、首次主机名和插件包摘要继续固定为原交付来源。调价、编辑连接、切换默认供应商或当前发布版本均不会改变操作目标。重装成功仅更新实例当前镜像和登录资料，不重写原订单快照。

## 插件 SDK

可选实现 `ProviderInstanceOperationsInterface` 的三个固定方法：

- `instanceOperationActions()`：声明明确支持的标准动作。
- `operateInstance(ConnectionContext, InstanceOperationRequest)`：首次提交。
- `queryInstanceOperation(ConnectionContext, InstanceOperationRequest)`：仅查单。

`InstanceOperationRequest` 包含固定请求标识、原实例与主机名、核定镜像选项、首次提交密码及已受理任务标识。其调试和 JSON 序列化隐藏密码。返回 `LifecycleResult` 的 `status/providerTaskId/powerStatus`；受理返回 pending，不得把 HTTP 成功当成任务完成。插件不需要新增自定义 API 文件夹。

插件更新改变包摘要后，历史任务会停止自动执行并要求核查原插件；不会把新包静默套用到旧任务。

## 魔方云对接

依据用户提供的本地《魔方云 API 文档》：

| 动作 | 上游端点 |
| --- | --- |
| 开机 | POST `/v1/clouds/{id}/on` |
| 关机 | POST `/v1/clouds/{id}/off` |
| 重启 | POST `/v1/clouds/{id}/reboot` |
| 重装 | PUT `/v1/clouds/{id}/reinstall` |
| 重置密码 | PUT `/v1/clouds/{id}/password` |
| 查询原任务 | GET `/v1/tasks/{id}` |
| 查询电源状态 | GET `/v1/clouds/{id}/status` |

任务详情必须匹配回执任务 ID 和原实例 `hostid`：0/1 等待，2 成功，3/4/5 失败或取消。开关机还需电源达到对应状态。重启、重装和重置密码不能仅凭电源 on 判定成功。

原实例详情必须同时匹配实例 ID 与首次主机名。所有动态路径使用审核过的正整数占位模板，沿用 SSRF、TLS、禁止重定向和连接限时保护。

**回执丢失边界**：没有收到 taskid 的请求不能用「最近任务」猜测归属，也不重新提交；保留待核查，由管理员到供应平台确认。此时再次点击重试仍只查原请求，不能伪造已完成。

## 部署与验收

执行前向迁移 `20261018000400` 和枚举注释补充迁移 `20261018000500`，先备份数据库。迁移扩展任务资料和实例电源/当前镜像字段，不修改旧迁移或交易历史。更新 PHP 类、DI、插件后完整 restart；构建后台和控制台，运行 `build:template`、`website:publish` 发布官方 PC/移动模板。

自动化涵盖隔离 MySQL、真实 HTTP 鉴权/RBAC/同源/输入校验、真实适配器的模拟协议，以及三端模拟接口浏览器交互。真实供应账号、真实资源操作及 iOS/Android 真机仍需部署环境验收。
