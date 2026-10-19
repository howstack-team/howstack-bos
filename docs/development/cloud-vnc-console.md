# 云服务器 VNC 远程控制台

## 使用入口

后台、PC 会员中心和独立移动会员中心：云服务器实例列表 → 管理 → VNC 远程控制台 → 连接。

PC 使用大弹窗，提供连接、断开、适应窗口、原始尺寸、只读、全屏、Ctrl+Alt+Del 和 Esc。移动端使用独立控制面板，支持横屏、适应/拖动画面、软键盘、Ctrl+Alt+Del、Esc、Tab、回车及退格。关闭界面、离开页面时释放连接和定时器，重新连接重新申请凭据。

连接只在用户点击时申请，打开界面不会自动签发，避免 React StrictMode 重复执行。接口反馈分别使用 PC Message 和 Mobile Toast。

## API 与访问边界

| 入口 | 接口 | 职责 |
| --- | --- | --- |
| 会员 | GET `/api/console/v1/services/{id}/vnc` | 复核本人实例访问资格，不签发凭据 |
| 会员 | POST `/api/console/v1/services/{id}/vnc` | 本次连接参数 |
| 后台 | GET `/api/admin/v1/service-instances/{id}/vnc` | 复核实例查看与独立 VNC 操作权限 |
| 后台 | POST `/api/admin/v1/service-instances/{id}/vnc` | 本次连接参数 |

完整输入、响应、错误码见 `contracts/openapi/service-vnc.v1.yaml`。管理选项的 `vnc_available` 为受控能力标志，不代表客户端可以绕过实际签发权限检查。

- 会员只访问本人云服务器。实例须已经确认开通、订阅有效且未暂停/终止。
- 后台同时要求实例查看权限和 `admin.services.cloud-servers.vnc`；角色权限目录、路由操作权限和服务端校验同步登记。
- 所有签发要求同源请求，按认证身份限制每分钟 10 次、同一实例每 30 秒 3 次；保护服务故障时拒绝签发。
- 网络调用不持有数据库事务。调用前后均重新检查实例归属、订阅和原供应身份；后台返回前再核验权限。
- 重启、重装任务进行中仍允许有效订阅访问 VNC，便于排障；不会占用或重放生命周期任务。
- 会员和后台的签发成功/失败写入私有系统日志 `service.vnc.access`，仅包含认证操作者、实例 ID、结果；后台另有现成操作日志。没有新增业务表。

## 凭据保护与会话

API 只返回直接 WSS 地址、VNC 认证密码、客户端连接超时秒数和客户端会话上限秒数。系统登录密码、供应 API 账号、私钥、原始响应和第三方 HTML 页面不回传。

连接响应为 `Cache-Control: no-store, private`、`Pragma: no-cache`、`Referrer-Policy: no-referrer`。凭据只在当前界面的连接内存存在，不进入浏览器导航 URL、React Query、持久存储或审计报文。后端 VNC DTO 的 JSON/调试输出会隐藏全部连接凭据。

正常客户端连接超时为 30 秒，会话最长 15 分钟且不超过剩余订阅时间；每 30 秒 GET 复核当前账号、权限、实例资格和插件能力，失败即断开，不自动签发新凭据或自动重连。关闭界面清理 RFB、画布、事件监听、未完成请求和定时器。

**供应会话边界**：浏览器直接连接供应商 WSS 网关，核心不转发 WebSocket 流量。上述时限和续检约束本系统正常客户端，不宣称能从核心强制撤销供应商已签发的令牌或终止独立客户端；上游令牌有效期、Origin 校验和远程会话撤销由供应平台控制。正式部署须验证这些行为。

## 插件契约

供应插件可选实现 `ProviderVncInterface` 的一个方法：

```php
public function openVnc(ConnectionContext $context, InstanceIdentity $instance): VncConnection;
```

`InstanceIdentity` 由宿主从原服务读取，包含上游实例 ID 和首次主机名；不能使用浏览器输入重选实例。插件须先精确核验实例身份，再返回完整的 WSS 地址和 VNC 密码。未声明能力时不展示入口。拒绝明文 `ws:`、HTML 页面地址、用户登录信息、URL 片段和异常控制字符。

每次新 VNC 连接使用当前审核通过的同 ID 提供者插件；原供应连接密文、资源身份和历史交易快照保持固定。这样同一提供者经审核升级后可以为存量实例新增 VNC 能力。停用、卸载或包摘要未通过宿主审核时拒绝新连接。既有开通、续费和运维任务的固定包与未知结果查单规则不变。

VNC 不要求插件提供新的 `api/` 文件夹、任意路由或额外 JSON 配置。

## 魔方云接入

按用户 Downloads 下《魔方云 API 文档》：先 GET `/v1/clouds/{id}` 核对 ID 与首次主机名，再 POST `/v1/clouds/{id}/vnc`。

- 使用 `vnc_url` 和 `vnc_pass`。
- 忽略 `password`（实例系统密码）、外部页面地址和其他无关字段。
- 不拼接猜测的 `/cloud_ws` 路径，不把供应商 `/v1/novnc` 页面嵌入本系统。
- API 调用继续使用受限 HTTP 客户端的原主机、已审核数字路径模板、TLS、禁止重定向和超时保护。

供应侧必须配置可供使用者浏览器访问的 WSS 网关、有效证书、正确 DNS/端口及允许的平台 Origin。网关只有内网地址时，浏览器也必须具有相应网络路由。核心 API 可达不代表浏览器到 VNC 网关可达。

## 前端依赖与发布

后台和会员中心使用同一锁定版本 `@novnc/novnc@1.7.0`，通过官方 RFB API 接入，不修改第三方源码。共享 `frontend/packages/vnc-runtime` 处理协议生命周期、输入、超时和访问续检；PC 与 Mobile 使用各自组件，不交叉引用应用源码。

noVNC 延迟加载并本地打包，没有运行时 CDN。各后台、PC、移动构建均交付 `licenses-novnc.json`，包含 MPL、BSD、pako MIT 等原始许可及源码来源。官方 API 和许可证参考：[noVNC 1.7.0](https://github.com/novnc/noVNC/tree/v1.7.0)、[RFB API](https://novnc.com/noVNC/docs/API.html)。

运行两端 lint/build，控制台额外执行 `build:template`，随后 `php webman website:publish` 和后端完整 restart。PC 与移动开发服务器使用独立依赖预构建缓存，避免并行开发时动态导入出现过期缓存。

## 验收范围

- 隔离 MySQL：对象归属、状态、到期、网络调用前后再次核验、原连接、安全日志及错误脱敏。
- 真实 HTTP：匿名、路径校验、同源、跨会员、后台独立权限、限流。
- 浏览器：运行真实 noVNC 模块，以模拟供应商完成 RFB 3.8/VNC challenge-response、画面像素、按键、关闭断连、三端布局及凭据隔离。测试仅适配 Playwright WebSocket shim 的属性布局，不修改生产客户端。
- 单元测试：客户端时限、访问撤销、输入、清理和错误信息处理。
- 真实魔方云账号、真实实例 WSS 网络与证书、Origin、供应令牌撤销，以及 iOS/Android 真机软键盘和横屏仍需部署环境验收。
