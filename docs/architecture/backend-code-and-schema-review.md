# 后端代码与数据库遗留审查

基准日期：2026-10-10。范围：当前 `howstack-bos` 后端工作区，以及 `.env` 指向的本地开发数据库。这里的行数是本地检查时的快照，不代表其他环境或平台规模。

**总体判断：目录架构比较清楚，但实现尚未收敛。最值得整理的是云服务器的新旧配置链路、财务大用例、重复查询逻辑和已经建表却未接入的能力。存在具体遗留代码和字段，但没有证据支持“数据库里大部分表都废弃了”。**

本次是代码与结构审查，不修改业务代码、表结构或记录。所有数据库查询使用只读事务，仅读取结构、数量、空值比例及配置形状；不输出会员资料、正文、凭据或连接秘密。未执行真实付款、发送、外部生产或全套业务测试。

配套清单：[逐表使用定位](backend-table-usage.csv)、[重点字段结论](backend-field-review.csv)。整个系统的能力缺口见[系统缺陷与优化建议](system-improvement-review.md)。

## 1 审查覆盖和判断方式

- `backend/app` 有 521 个 PHP 文件、约 34,457 行，包含框架进程代码；业务按 23 个一级模块组织。
- 检查了 56 个核心迁移文件，以及插件入口、容器绑定、Attribute 路由、进程、队列和相关测试的调用证据。
- 本地库实际存在 81 张带项目配置前缀的表、773 个字段；均纳入逐表定位清单。
- 没有把供应商 SDK、运行缓存、前端构建产物纳入业务源码体量，也没有据文件数量推断质量。
- 表名/字段名搜索只生成候选；再核对模型映射、动态拼接、框架行为、允许的查询资源及数据库数据形状。

“引用”与“使用”必须分开：模型声明不等于业务读写；空表不等于废表；动态字段没有完整字面引用也可能被真实写入；类由容器、命令发现或插件清单创建，不一定有显式 `new`。

## 2 架构哪些地方是合理的

Controller、Application、Contract、Infrastructure 的目录和职责总体成立。导入扫描和本轮重点文件核查没有发现明显的 Controller 直连数据库、Domain 引入 ORM，或 shared 反向依赖业务模块的问题；这不等同于证明所有动态调用都满足边界。

以下设计应继续保留：

- 订单项和订单实例金额单元分开，支持一次购买多台后按单台退款。
- 资金以整数分处理；余额流水、充值资金来源和消耗恢复有明确记录。
- 产品版本、订单配置、供应绑定保留不可变快照。
- 普通消息以数据库任务为事实源，Redis 传投递标识，并区分受理、送达和未知结果。
- 跨模块依赖主要走 Contract，插件经宿主固定接口创建。
- 所有业务模块 PHP 文件均有 `strict_types`；少量缺失集中在框架模板及初始化残留。

因此适合渐进整理，不建议重新推翻目录或将核心业务拆成更多插件/服务。

## 3 代码质量的主要问题

### C01 新云配置通过旧结构间接执行

**依据**：[PrepaidComputeTypeHandler](../../backend/app/modules/compute/domain/PrepaidComputeTypeHandler.php) 第 16–20、57–70、106–108、185–195 行：新配置删掉 `schema_version`，造零网络结构，再调用旧 `ComputeTypeHandler` 的快照、联动和计价；随后重新拼接新网络和周期结果。

**影响**：修改新带宽、磁盘、镜像规则时，需要同时理解两套结构。旧处理器的变化会影响新业务；旧网络字段无法真正收敛。`resolve()` 还为派生分组重复调用旧联动计算。

**建议**：把可售资源组合校验、镜像兼容、磁盘合法性等稳定逻辑提取成类型内的纯领域组件。新版处理器直接调用组件；若确有历史交易，旧快照放入独立只读解释器。不要让新版写入、报价依赖先转换成旧字段。

### C02 隐藏了旧功能的界面，后端入口仍保留

**依据**：[ComputeResources](../../backend/app/modules/compute/domain/ComputeResources.php) 第 10 行仍注册 `network_policies`；[ComputeResourceValidator](../../backend/app/modules/compute/validation/ComputeResourceValidator.php) 第 36–37 行允许其保存；[ManageCloudProductsService](../../backend/app/modules/product/application/ManageCloudProductsService.php) 第 46–48、101–104、191–247 行仍查询旧交付规则并保留回退。

[AdminCloudProductController](../../backend/app/modules/product/controller/AdminCloudProductController.php) 第 49–67 行保留销售方案新增/更新和独立发布入口；[CloudOfferValidator](../../backend/app/modules/product/validation/CloudOfferValidator.php) 第 28、81–95 行仍允许旧版本写入。[前端 API 包装](../../frontend/admin/src/api/cloudProductApi.ts) 第 134、138、156 行仍声明独立发布和旧交付规则方法，是否从页面触发应在前端会话收敛时核对。

**影响**：系统同时维护社区工作台保存生效和旧销售发布两种可写模型。隐藏菜单不能消除接口、权限、契约和代码的维护成本。

**建议**：社区版明确最新配置写入入口；新请求只接收最新结构。清理无实际调用的旧写接口、权限和契约；历史解释与不可变版本继续独立保留。涉及前端调用方的修改需与负责前端的会话协作，本次未改前端。

### C03 旧单值字段与多关联表保存同一事实

**依据**：[ComputeResourceValidator](../../backend/app/modules/compute/validation/ComputeResourceValidator.php) 第 52–70 行既接受旧单值，又把多关联第一项回写为 `zone_id/line_id`；[DatabaseComputeResourceRepository](../../backend/app/modules/compute/infrastructure/DatabaseComputeResourceRepository.php) 第 43–48、76–78 行同步保存两种结构；[ComputeTypeHandler](../../backend/app/modules/compute/domain/ComputeTypeHandler.php) 第 39–51 行保留回退并继续生成主关联。

**影响**：同一资源的关联有两份表达，后续新增或批量维护需保证一致。当前本地线路、规格、镜像、存储类型的主可用区均在其关系表中，未发现该项数据不一致；仍不应让新接口长期双写。

**建议**：新资源写入以关系表为唯一事实来源。先确认主关联是否还有业务意义，再选择派生输出或删除字段；“可用区所属地域”的 `compute_zones.region_id` 是真实层级，不属于这类冗余。

### C04 财务门面聚合了多种单据和业务决策

**依据**：[ManageFinanceService](../../backend/app/modules/finance/application/ManageFinanceService.php) 共 335 行，`create()` 用资源字符串分派充值、退款、发票、提现；`act()` 再按资源和动作组合判断，私有方法内同时处理额度分配、账户冻结、资金来源恢复与单据状态。

**影响**：新增某种退款或开票规则，容易触碰不相关的单据和锁顺序。发票额度分配等纯规则和事务编排混合，不便单独验证。

**建议**：保留统一后台入口和共享幂等/审计设施，内部逐步拆成明确用例，如登记充值、申请退款、执行余额退款、申请开票、确认开票、冻结提现、确认打款。将可独立验证的额度和状态规则提取到财务 Domain；不把一个事务拆成多次远程调用，也不机械给每个小方法建接口。

### C05 查询逻辑重复，旧关键词与独立条件并存

**依据**：[DatabaseMemberRepository](../../backend/app/modules/member/infrastructure/DatabaseMemberRepository.php) 第 38–52 行同时存在关键词 OR 查询和独立字段 AND 查询；关键词使用原始 LIKE，独立字段对 `%/_/!` 转义。同类关键词分支在 12 个 Infrastructure 文件中仍存在。类似 LIKE 转义和时间范围应用逻辑在订单、产品、工单、实名认证、服务实例等仓储重复。

**影响**：同样的输入在两种查询入口含义不同；新增筛选条件需要反复复制。当前有绑定参数，不据此声称存在 SQL 注入。

**建议**：逐接口核对当前调用方与 OpenAPI，删去无调用的旧关键词参数。保留模块内明确查询对象，少量稳定的字面匹配/时间范围操作可以复用；沿用已有 `TableQueryOrder` 的排序白名单，避免做任意列名、任意操作符的万能筛选器。

### C06 模型写入白名单被当成响应白名单

**依据**：[DatabaseComputeResourceRepository](../../backend/app/modules/compute/infrastructure/DatabaseComputeResourceRepository.php) 第 67–71 行用 `getFillable()` 遍历返回字段，未显式定义公开输出列。

**影响**：以后为内部业务增加可写字段，会自动扩展 API 响应。当前没有在这里找到已经泄露的密钥，但这与仓库“输出白名单独立于模型字段”的规范不一致。

**建议**：按资源类型建立明确响应映射/DTO；写入白名单、持久化字段、输出字段分别维护。无需为每张表做一套庞大的通用序列化框架。

### C07 页面拆分后，读取仍返回所有资源

**依据**：[GetCloudConfigurationSectionService](../../backend/app/modules/product/application/GetCloudConfigurationSectionService.php) 第 36–39 行，每个配置子页仍取得共用完整 references 和库存；[ManageCloudProductsService](../../backend/app/modules/product/application/ManageCloudProductsService.php) 第 46 行遍历全部 10 类资源。[GetCloudServerWorkspaceService](../../backend/app/modules/product/application/GetCloudServerWorkspaceService.php) 第 26–28 行为了统计数量，读取完整资源列表。

**影响**：页面拆开了，API 与查询范围没有随之收敛。资源多时增加 SQL、序列化和响应体积；本轮没有测量生产延迟，不给出性能提升百分比。

**建议**：子页返回需要的资源，公共引用按需读取；概览用仓储聚合计数。跨页关联校验可以继续在写用例中完整执行。

### C08 压缩写法和 HTTP 异常耦合降低可读性

**依据**：[ComputeTypeHandler](../../backend/app/modules/compute/domain/ComputeTypeHandler.php) 第 44–51 行多层 `foreach`、引用和修改挤在一起；[DatabaseComputeResourceRepository](../../backend/app/modules/compute/infrastructure/DatabaseComputeResourceRepository.php) 第 43、47 行嵌套单行 `if`；多处 PHPDoc 将多个标签放在同一行。10 个 Domain 文件直接引用 `BusinessException` 或 `ApiErrorCode`；例如 [FinanceRules](../../backend/app/modules/finance/domain/FinanceRules.php) 第 27–29 行直接决定 HTTP 409。

**影响**：不易定位规则步骤和异常边界；领域计算与 HTTP 响应约定绑定，复用到队列或 CLI 时仍带 HTTP 语义。

**建议**：在实际整理模块时展开控制流、使用有业务含义的中间变量，补全方法契约。复杂领域用明确业务异常，由应用/HTTP 边界映射状态；不做全仓库纯格式化，也不把文件长直接当成设计错误。

## 4 当前未接入的表

| 表 | 当前证据 | 本地数据 | 结论 |
| --- | --- | --- | --- |
| `payment_notifications` | 仅模型及 `DatabaseFinanceRecordRepository::modelQuery()` 中的分支；允许查询的 RESOURCES 和调用清单不包含该表 | 0 行 | 尚无支付回调入账流程使用 |
| `refund_attempts` | 同上，未找到创建、更新或业务查询调用 | 0 行 | 尚无原渠道退款尝试流程使用 |

依据：[财务查询仓储](../../backend/app/modules/finance/infrastructure/DatabaseFinanceRecordRepository.php) 第 28–56、106–117、130–147 行；模型 [PaymentNotificationModel](../../backend/app/modules/finance/infrastructure/model/PaymentNotificationModel.php) 和 [RefundAttemptModel](../../backend/app/modules/finance/infrastructure/model/RefundAttemptModel.php)。两个模型虽然被私有方法引用，相关分支在当前调用范围内不可达，不应算成已使用。

这两张表属于**提前建立的功能基础**，不能仅凭本地为空就认定业务以后不需要。推荐在线支付和原渠道退款落地时按真实状态机重审结构、完成读写链路；若近期明确不交付，再以新迁移移除预建结构。不要删除历史建表迁移。

同一 `modelQuery()` 中 `finance_outbox`、`invoice_allocations`、`wallet_consumptions`、`wallet_funding_lots` 的映射也未被该仓储的当前入口调用。可以收窄这个私有映射，但这些表在其他仓储真实使用，不能一起删表。

## 5 遗留或未接入字段

| 字段 | 判定 | 数据和代码证据 | 建议 |
| --- | --- | --- | --- |
| `system_update_runs.package_checksum` | 当前明确无业务读写 | 核心运行代码无引用；45 条记录全 NULL；只出现在迁移定义和注释 | 优先清理候选；维护任务的签名清单/文件摘要校验继续保留 |
| `invoice_records.attachment_path` | 发票附件能力预留 | 仅模型声明；输入验证、发票创建/开具、详情输出均未处理；0 条记录 | 若不做附件，移除预留；若做，接入受控存储对象引用及权限 |
| `withdrawal_requests.payee_snapshot` | 收款信息能力预留 | 仅模型声明和 hidden；提现创建/打款不保存、不读取；0 条记录 | 按人工登记或真实出款方案决定，不直接开放任意文本收款快照 |
| `compute_lines/compute_flavors/compute_images/compute_disk_types.zone_id` | 多关联后的重复字段 | 仍被写入和回退读取，关系表已存在 | 先重构调用和当前草稿，再评估前向删除 |
| `compute_protections.line_id` | 同上 | 与 `compute_protection_lines` 并存且仍读写 | 同上；保留真实线路选择参数，不混淆字段语义 |
| `finance_outbox.attempts/processed_at` | 消费流程未完成 | 创建时写 attempts=0，未找到消费回写 | 保留并实现调度，不作为废字段删除 |
| `cloud_delivery_rules.priority` | 字段意义不够准确 | 注释说后续调度预留，仓储已经用于排序；发布按 ID 选规则 | 明确仅显示排序，或随旧规则模型一同收敛 |

`attachment_path/payee_snapshot` 的结论仅针对当前核心业务路径，不说明它们已被其他系统使用或存在历史数据。其他环境的数据须在删除前单独检查。

## 6 有遗留数据但仍可被代码使用的表

`compute_network_policies` 与 `cloud_delivery_rules` 本地各有 1 条记录。当前唯一销售草稿为 `schema_version=2.0.0`，没有引用任何网络策略或交付规则；本地 `offer_versions`、订单、实例表为空。

这说明本地已有旧配置残留，而且当前草稿没有依赖它们；**不说明表的 API 已失效**。旧资源管理、旧交付规则管理和旧处理器仍可读取/写入。先撤销旧写入口并统一新版组合/映射流程，再核查其他草稿、版本和环境，最后决定数据迁移和删表。

本地没有历史交易不代表可以任意删除其他环境的交易解释能力。历史快照一旦存在，必须继续可解释；可以与新版写路径隔离，不必保持两套活跃写模型。

## 7 可以整理的代码残留

| 文件或入口 | 当前状态 | 处理方向 |
| --- | --- | --- |
| [app/model/Test.php](../../backend/app/model/Test.php) | 初始化测试模型，无运行/测试调用，本地不存在对应 `test` 表 | 可以删除；不用新增删表迁移 |
| [app/middleware/StaticFile.php](../../backend/app/middleware/StaticFile.php) | 仅 `config/static.php` 中注释掉的注册；没有实际挂载 | 移除模板残留，或明确接入用途；先核对部署静态资源访问边界 |
| [ServiceInstanceModel](../../backend/app/modules/service_instance/infrastructure/model/ServiceInstanceModel.php) | 当前无调用；实例查询仓储使用明确联表 SQL | 可以在履约写用例落地时使用，或删除闲置模型；`service_instances` 表仍被实例与财务查询使用，必须保留 |
| [RealNameApplicationService](../../backend/app/modules/member/application/RealNameApplicationService.php) | 有单元/仓储测试，未找到控制器或生产调用 | 未接入的会员申请能力，不是无意义模板；接入前完成验证/授权，不把测试通过等同于已提供功能 |
| 支付/实名/第三方登录/人机验证执行服务 | 多数提供适配器能力，缺少最终业务调用；短信消息发送目前直接使用适配器 | 分清公共低层能力和已接入业务，按需要收敛重复门面；不误删用户明确要求保留的插件能力 |

依赖中还存在 OpenAI、Crontab 等未找到核心业务使用的包。`webman/push` 虽未找到业务发布者，却有进程/路由配置，不能当成完全没有运行影响的依赖直接删除。依赖裁剪需要单独检查引导配置和发行范围，本轮未卸载依赖。

## 8 不应误删的表和字段

- `members.mobile_verified_at/email_verified_at`：注册仓储用 `$method . '_verified_at'` 动态写入，修改联系人时清空。它们当前本地均为空，但有真实使用路径。
- `mail_delivery_attempts`：邮件服务和后台测试邮件使用的幂等表，有领取/结果保存，作用不同于 `message_delivery_attempts`。统一前应先设计测试邮件归属和幂等边界。
- `system_update_runs`：迁移升级记录，本地 45 行；`software_installation_jobs` 是软件/应用安装编排任务，层级和职责不同。
- `migration_versions` 及其字段：由 Phinx 管理，不要求每个字段在业务源码中出现。
- `compute_*_zones/compute_protection_lines`：ORM 的多对多关系表；没有独立 Model 类不代表废表。
- 库存池和预占表：虽然本地为空，已经有配置、预占、转换和释放读写用例。
- 发票额度、充值资金来源、资金消耗、订单实例金额单元和服务来源关联：财务和历史交易解释需要，不能为了减少表数合并删除。
- 消息精细接收组和封存任务：社区界面简化不代表底层策略失效。

## 9 结构规范的小缺口

本地 `information_schema.COLUMNS` 显示仅两处字段没有 COMMENT：`compute_inventory_pools.id`、`compute_inventory_reservations.id`。对应库存迁移为自动主键添加了表和业务列注释，但没有补主键注释。

补充方式应是新的前向迁移，保留字段原类型、identity、索引和默认属性；不编辑已执行的 [CreateCloudInventory](../../backend/database/migrations/20261007001000_create_cloud_inventory.php)。其他字段有注释不代表注释都准确，`priority` 就需要校正实际语义。

## 10 建议整理顺序

1. **先做小范围清理**：删除测试模型和明确不启用的模板，收窄不可达的财务查询映射，清理 `package_checksum` 并补库存主键注释。每项保留独立差异与验证。
2. **优先收敛云服务器**：新版写路径独立；提取资源约束；清理旧网络/交付规则入口；统一多关联。新老快照解释明确分开，保留订单需要的不可变版本。
3. **整理财务用例**：按充值、退款、发票、提现拆清事务和领域规则。补交易 Outbox 和履约闭环后，再决定支付通知与退款尝试表最终结构。
4. **统一查询与响应边界**：逐接口核对旧关键词参数，提取稳定查询操作，显式响应字段，缩小云配置子页数据范围。
5. **建立持续门禁**：检查分层导入、未引用代码候选、API 契约、数据库注释及重要业务回归。扫描结果需要人工复核，不能自动 DROP 无字面引用的字段。

### 每次删除前的最低核查

- 确认 HTTP、CLI、队列、插件、容器绑定、前端调用、OpenAPI 和测试均已收敛。
- 检查真实目标环境中的非空值、关系、唯一键及不可变快照引用；数据量大时另行设计统计方式和迁移窗口。
- 修改行为和迁移采用新文件；不篡改已执行的历史迁移。
- 需要移除持久化结构时先备份；验证全新安装和现有数据升级，数据库检查使用独立测试库。
- 格式、结构清理与资金状态变更分开，不用大范围重写掩盖业务变化。

**建议先完成一个云服务器模块的收敛，再按相同方式整理其他模块。现在最值得优化的是规则的单一来源和完整业务路径，减少文件数量本身不是目标。**
