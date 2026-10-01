# 运营后台登录日志

`GET /api/admin/v1/login-logs` 返回管理员登录尝试，受 `admin.page.login-logs.view` 服务端权限保护。支持 `keyword`（账号、昵称、IP、地点）、`status`（`1` 成功、`0` 失败）、`page`、`page_size`。响应 `data` 包含 `data` 列表和 `total`。序号由前端按页码计算；表内持久化登录时间、昵称快照、状态、`client_ip`、`client_ip_location`、浏览器和操作系统。未知账号的昵称列记录当时输入的账号，不补查当前用户资料。

登录成功和账号密码错误、账号停用都会写入数据库。密码、Cookie、JWT 和原始 User-Agent 不进入表；浏览器与操作系统仅保存归纳结果。限流中间件提前拒绝、表单格式错误和数据库不可用不属于已验证账号密码的登录尝试，不写入此表。IP 地点在事件发生时使用离线 ip2region 数据库解析；本机、内网、未知使用统一文案。IPv4 数据库随 Composer 包提供；IPv6 数据库未随发行包内置，未配置时 IPv6 地点为“未知”。如需 IPv6 地点，部署时提供本地 xdb 文件并设置 `IP2REGION_IPV6_DB`，不得在请求期间下载。

默认只信任连接对端 IP。部署在代理后，按实际网络拓扑配置 `TRUSTED_PROXY_IPS`，支持逗号分隔的 IP 或 CIDR；只有连接来自所列代理才读取 `X-Forwarded-For`，并从右侧剥离可信代理。不要将任意来源 IP 或全网段配置为可信代理。

首次使用前运行 `php webman system:migration-status`，完成数据库备份后运行 `php webman system:migrate`，再重载 Webman worker。清理策略为默认保留 180 天，不在请求中自动删除。运维先备份并执行 `php webman admin:prune-login-logs --days=180` 预览范围，再按批准的保留期运行 `php webman admin:prune-login-logs --days=180 --execute`。命令每批最多删除 500 条，`--days` 允许 30–3650；可纳入维护窗口的定期任务。
