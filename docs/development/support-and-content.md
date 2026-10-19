# 服务与内容管理

## 当前实现

运营后台的「业务管理」展示已创建的云服务器、物理服务器和服务器托管实例。「服务管理 → 工单管理」包含工单类型、工单列表和快捷回复。「内容管理」按「帮助文档」「新闻公告」分组，分别提供内容列表和分类管理。每个页面都有独立的查看权限，写操作另设权限，并由后端接口再次校验。

工单类型和快捷回复支持增删改查；会员 PC 与独立移动模板已接入工单提交、本人列表、详情、追问和关闭；后台支持查询、查看会话、快捷回复和关闭。已有工单引用的类型不可删除，禁用类型不影响历史会话。工单具体规则与验证见下方「工单最小闭环」。帮助文档和新闻公告共用内容表，但按 `kind` 严格隔离分类、记录和权限。帮助分类最多两级，公告分类保持一级；分类不设置业务编码。同级分类名称不能重复，不能将有子分类的一级分类改为二级分类；已有内容或子分类的分类不可删除。发布内容要求所属分类及其上级分类均已启用。

新结构由前向迁移 `20260927000500`、`20260927000600` 创建；迁移 `20260927000700` 为分类增加父级关系并移除旧编码列。执行前备份数据库，旧编码数据不会在迁移后保留。后台接口统一在 `/api/admin/v1` 下，使用管理员认证、请求校验和统一响应。帮助分类列表返回树形数据，不分页；新闻公告分类仍分页。

## 当前边界

工单附件、自动分配、SLA、重新打开及复杂审批继续暂缓。回复站内通知已接入现有持久消息运行时，关闭仅结束沟通，不代表退款或资源释放。内容编辑与官网公开读取以当前内容模块实现及对应文档为准；本次不扩展 CMS。工单正文始终是纯文本，不复用新闻的富文本编辑、公开文件库或 HTML 渲染。

新增接口或变更权限时，同步修改 `backend/app/modules/admin/access/domain/PermissionCatalog.php`、`frontend/admin/src/routes.ts` 及对应页面操作入口。

## 工单最小闭环（2026-10-19，R03）

### 入口与状态

- PC「服务与帮助 → 工单管理」及顶部工单、总览快捷入口；移动「我的 → 工单管理」及首页工单支持。列表路径 `/console/support/tickets`，详情 `/console/support/tickets/:id`。
- 选择启用类型、填写主题和问题描述；可选关联本人云服务器。服务选择可继续加载下一页，不以首屏 20 台冒充完整选项；后端通过服务模块公开契约再次核实归属。没有启用类型时明确提示联系运营方，不能创建空类型工单。
- 新建与会员追问为 `open`（等待后台），后台回复为 `waiting_member`（等待会员）；`processing`（处理中）历史状态保留。双方均可关闭，进入 `closed`（已关闭）并保存关闭时间；关闭后不受理新回复，不提供重新打开。
- 列表默认 20 条，状态和分页保存在 URL。初次详情显示最近 50 条回复，按 ID 正序显示；点击更早记录读取另一窗口，可返回最新记录。初始问题单独展示，不重复当作一条回复。
- 全部正文按纯文本渲染，HTML 字符作为文字，不使用 `dangerouslySetInnerHTML`。不收集附件，不增加财务或实例执行按钮。

### API 与授权

完整契约：[工单 API](../../contracts/openapi/support-tickets.v1.yaml)。后台原列表、类型与快捷回复 API 保留，详情和回复统一到最新契约。

| 入口 | 方法与路径 | 规则 |
| --- | --- | --- |
| 会员类型 | `GET /api/console/v1/tickets/types` | 仅启用类型 ID、名称 |
| 本人列表 | `GET /api/console/v1/tickets` | `page`、`page_size`、可选 `status`；页码 1～100000，条数 10/20/50/100 |
| 会员提交 | `POST /api/console/v1/tickets` | `type_id`、可选 `service_id`、`subject`、`content`、`request_key` |
| 本人详情 | `GET /api/console/v1/tickets/{id}` | `ticket`、`messages`、`next_before_id`；不存在或他人工单均 404 |
| 本人更早会话 | `GET /api/console/v1/tickets/{id}/messages` | 可选 `before_id`，最多 50 条，不接受作者或会员身份参数 |
| 会员追问 | `POST /api/console/v1/tickets/{id}/replies` | `content`、`request_key` |
| 会员关闭 | `POST /api/console/v1/tickets/{id}/close` | `request_key` |
| 后台更早会话 | `GET /api/admin/v1/tickets/{id}/messages` | 查看权限及会话窗口 |
| 后台回复 | `POST /api/admin/v1/tickets/{id}/replies` | 查看 + `admin.tickets.list.reply`；`content`、`request_key` |
| 后台关闭 | `POST /api/admin/v1/tickets/{id}/close` | 查看 + `admin.tickets.list.close`；`request_key` |

会员身份来自有效 HttpOnly Cookie，不能提交 `member_id`、`author_id`、优先级或状态。本人响应不输出内部会员 ID、工作人员 ID、幂等摘要、供应资料或联系方式。GET 私人数据禁止缓存；正常浏览器 GET 不需要 Origin。写入必须同源，会员新建每 5 分钟最多 5 次，已认证主体工单写入每分钟最多 20 次，超额 429，保护服务异常 503。后台新关闭权限可在角色分配中独立选择，不能用回复权限代替；后台动作复用含 IP/位置的现有操作日志，只记录目标与动作，不记录正文。

主题去除首尾空白后为 1～200 字符，正文或回复为 1～10000 字符。前端原生 Form 验证不通过不请求后端；422 字段错误只落到相应字段，其余接口反馈使用 PC Message 或移动 Toast。

### 一致性与通知

`request_key` 为 16～64 位字母数字、下划线或连字符，官方模板使用 32 位随机十六进制键。每个主体同键同规范化内容返回原 `ticket_id/message_id`，同键不同内容为 409；网络失败重试保持原键，改内容或目标后换键。刷新页面并重新填写视作一次新提交，不声称跨页面草稿去重。

`ticket_commands` 以主体类型、主体 ID、键唯一约束串行化重复命令，只保存规范化内容的 SHA256 摘要和结果。命令、工单状态、回复及站内通知任务在同一数据库事务提交。回复和关闭竞争时锁定同一工单：回复先提交则保留在关闭前的历史；关闭先提交则拒绝后来的回复，不重新打开。

后台回复复用消息 Outbox，`ticket_replied` 必须保留一条本人站内提醒，已有启用的站内渠道不重复生成；此必要提醒不依赖可选外部渠道是否开启。通知只包含工单号和主题，不包含回复正文。投递由原 Worker 执行，队列暂不可用可补投，外部发送失败不删除已提交回复。消息中心按固定业务类型和 ID 构造本机工单路由，不能将正文或外部 URL 当作跳转指令。

### 迁移与验证

前向迁移 `20261019000100_complete_ticket_conversations.php` 增加可空的 `tickets.service_id` 和 `ticket_commands`，新字段及新表均有中文数据库注释；历史工单不回填关联、不改正文、状态或回复。执行前备份，使用 `php webman system:migration-status`、`php webman system:migrate --force`；无回退删除历史数据方案，异常使用前向修复。新增容器绑定和路由需要完整 restart。

本地开发库已备份并执行迁移。后台、PC、移动构建后，默认会员主题通过 `php webman website:publish` 发布；已有非创始人角色需授权「关闭工单」后才能显示该动作。

验证证据：

- `TicketConversationTest`：独立 SQLite/MySQL 真迁移和 ORM，覆盖提交、状态、幂等、两会员隔离、本人服务限制、禁用类型、列表分页筛选、50 条会话窗口、关闭拒绝、必要站内通知及外部投递异常。MySQL 另用独立进程验证重复新建、重复回复、回复与关闭竞争。
- `TicketConversationMigrationTest`：独立数据库历史升级及重复执行，自定义 `orm_` 前缀；MySQL 查询 `information_schema` 检查新增字段和表的中文注释。与业务测试共同覆盖全新安装和已有数据升级。
- `ticket-conversations-http.php`：真实 Webman/MySQL 和正常会员登录，验证接口、归属、来源、字段反馈、后台权限、审计和频率限制。仅本机显式启用，使用临时会员、管理员、类型与服务，最终清理；外部工单通知开启时拒绝运行，不发送真实厂商通知。
- `tickets-real-browser.mjs`：由上述验收脚本传入临时会话，经真实 API/数据库完成 PC 1440、移动 390/320 的提交→后台回复→Worker 站内通知→点击返回原工单→追问→会员或后台关闭，验证空字段不请求、HTML 不执行、刷新持久化及页面溢出；包含后台 390px 抽屉。没有模拟业务 API，不代表 Safari 真机验收。
- `tickets.test.ts`：命令键复用、会话响应边界、身份字段投影和错误分类。两端 lint/build、默认 PC/移动模板构建及 API 文档 test/build/check。

消息相关既有回归 `testReplyAndRealNameReviewPublishInTheSameTransactionAndOnlyToCustomers` 已按新工单契约调整并通过。执行整个旧 `MessageRuntimeTest` 时，另有订单付款测试仍以 5 个参数构造目前需要 7 个参数的 `PayOrderByWalletService`，与本次工单变更无关；未修改该订单测试来凑全绿，发行候选回归仍需处理。

真实手机、生产发行包、外部邮件/微信等提醒及所有首发业务的联合验收仍单独保留，不将本次 R03 的开发闭环当作社区版 1.0 发布放行。
