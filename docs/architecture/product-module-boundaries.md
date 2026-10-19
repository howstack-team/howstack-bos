# 产品模块边界与旧配置清理

## 当前模块职责

| 模块 | 负责 | 不负责 |
| --- | --- | --- |
| `product` | 产品身份、明确作用域的销售记录与不可变版本、公开目录和报价编排、类型及购买接入注册 | 地域、可用区、云服务器参数、网络规则、具体类型的展示字段 |
| `compute` | 云服务器资源、可用区价格和周期、配置工作台、供应绑定编排、公开选项投影、购买输入校验及库存 | 钱包余额、支付、通用订单状态与退款账务 |
| `order` | 认证入口、订单事务、确认金额、幂等、实例金额分摊、付款后任务调度 | 判断产品类型字符串、解析云服务器选配字段 |
| `provider` | 插件、连接、资源映射、供应协议与凭据边界 | 会员报价、订单金额和资金扣减 |
| `service_instance` | 持久开通任务、幂等查询、人工核查、实例状态 | 改写原订单报价或销售版本 |

云服务器专属的 Application、Controller、Validator、交付规则 Contract/Repository/Model 已从 `product` 移至 `compute`，不保留旧命名空间别名。公共销售模型继续放在 `product/infrastructure/model`，表名、主键和历史外键不变。

`DatabaseProductRepository` 构造时必须明确指定 `definitionKey`，所有销售查询、写入和版本读取均限定产品身份。容器使用具名的 `compute.sales_repository` 绑定云服务器的消费方，不把 `ProductRepositoryInterface` 全局默认为云服务器；新增其他产品不能误用云服务器的默认配置指针。

## 注册与跨模块调用

组合根位于 `backend/config/dependence.php`，只有这里声明安装了哪些具体产品：

- `ProductTypeRegistry` 接收 `ProductTypeHandlerInterface` 列表，负责快照、联动、计价和标准交付配置的类型分派。
- `ProductPurchaseRegistry` 接收 `ProductPurchaseHandlerInterface` 列表，同时实现购买输入、库存检查、付款库存办理和公开购买展示四个既有边界。输入、可售检查、正式占用和释放使用同一注册来源。
- `ComputePurchaseHandler` 实现云服务器的公开购买能力、白名单投影、输入校验、余量检查与库存办理；通用产品层不依赖 compute 类，也不判断 `cloud_server`。
- 未注册的产品不能默认跳过库存检查；重复类型注册在初始化时失败。未开放公开购买的类型不出现在会员目录，也不能通过伪造请求下单。
- 公开目录的购买入口、描述和选项投影由具体产品提供。报价总额、分项金额及交易语义仍使用统一契约，内部营销 facts 不公开。
- 会员订单入口只负责认证归属，将类型校验交给共用的 `CreatePurchaseOrderService`；不在会员层先执行一次云服务器校验。报价和下单都使用销售版本确定类型，浏览器不能自行指定类型。

库存正式占用仍在原付款事务内。外部供应请求不进入数据库锁事务；本次未更改资金、库存及履约锁顺序。

新增物理服务器、域名或证书时，在自己的模块实现类型契约和购买接入，实现自己的配置结构和页面，再在组合根注册。不能为了复用产品层而把所有产品改成无约束属性表。测试中的 `certificate` 仅用于证明通用边界，并不代表已实现证书业务。

## 已清理的旧入口

当前后台只有 `PUT /api/admin/v1/cloud-servers/configuration/live` 负责保存配置并尝试生效，要求 `draft.schema_version = 2.0.0`。配置缺项仍保存草稿并明确返回未就绪；权限、并发及系统错误仍整笔回滚。

以下旧入口已从控制器、OpenAPI 和前端封装中移除，返回 404 或 405：

- `PUT /cloud-servers/configuration`：与当前保存入口重复的草稿写入。
- `GET/POST /cloud-products`、`PUT /cloud-products/{id}`：旧销售方案列表和独立增改。
- `GET /cloud-products/references`：由分区读取接口替代。
- `POST /cloud-products/{id}/publish`：独立发布入口。
- `/cloud-servers/delivery-rules`、`/references`、`/{id}`：旧交付规则列表、新增、编辑接口。

路径以上均以 `/api/admin/v1` 为前缀。配置详情、启停、历史版本、报价与交付预览仍保留；它们是当前工作台及历史交易查看所需能力。

后台移除无调用的接口包装、旧 `?tab=` 页面转换逻辑，以及已关闭入口对应的独立发布/交付规则权限项。五个当前三级页面路由、分区编辑和抽屉内 Modal 行为保持一致。

## 网络配置与历史边界

当前可维护资源只有地域、可用区、线路、规格组、规格、镜像类型、镜像、存储类型、防护九类。网络价格、范围、赠送及 IP 规则统一归线路：

- 旧 `network_policies` 不再进入资源编辑目录，HTTP 新建、修改和 Application 写入口均拒绝。
- 当前 v2 草稿不再保存无效的全局 `network`、`network_policy_id` 或旧网络策略选择。
- 当前 v2 销售快照不再封存内部零网络适配字段或旧网络策略资源。计算器复用旧校验所需的零网络结构只在内存中构建。
- 读取现有 v2 草稿不写数据库，下一次明确保存才清理旧字段；不批量改写客户配置。
- 旧类型处理器、旧策略模型/表、历史交付规则与供应绑定按原语义保留，以读取已有草稿或不可变快照；它们不再通过旧 HTTP 编辑入口开放。
- 不删除 `offers`、`offer_versions`、旧策略/交付规则表或订单字段。仍存在历史读取依赖，直接删表会破坏历史交易。这次没有修改已执行迁移，也没有新增表结构迁移。

默认价、可用区独立价、系统盘赠送、可用区周期、订单快照与付款自动开通沿用现有实现。报价预览和交付预览已共用一个历史版本查找、归属校验及类型兼容检查，避免两套判断分叉。

## 验证入口

- `tests/unit/modules/product/ProductDecouplingTest.php`：第二产品的公开目录、选项、报价和会员下单；单次输入校验；未知产品拒绝、重复注册拒绝及源码依赖边界。
- `tests/integration/ProductSalesScopeTest.php`：两种产品的配置、默认指针与历史版本隔离，跨产品读写拒绝。
- `PrepaidCloudConfigurationTest`、`CloudProductAdministrationTest`：当前配置清理、旧快照读取、可用区定价、周期和预览归属。
- `MemberPurchaseWorkflowTest`、`AdminOrderWorkflowTest`、`WalletPaymentTransactionTest`、`ServiceProvisioningWorkflowTest`：下单、资金、库存、付款后开通及历史金额回归。
- `tests/acceptance/product-boundaries-http.php`：显式设置 `HOWSTACK_PRODUCT_BOUNDARIES_HTTP_ACCEPTANCE=1`，仅限本机开发库，验证真实分区路由、权限、旧入口关闭及旧格式写入拒绝；不发布产品、不创建交易，清理临时管理员。
- 后台 `tests/prepaid-cloud-browser.mjs`、`tests/zone-pricing-browser.mjs`，会员 `tests/purchase-browser.mjs`：模拟接口验证五分区、保存与抽屉交互、PC/移动选配和登录恢复。模拟不能替代真机及供应商验收。

配置绑定与控制器位置改变后必须完整重启 Webman。OpenAPI 重新 build/check，后台按正常 Vite 构建交付；会员 API 的响应结构未改变。

续费与到期处理已接入独立生命周期任务，复用订单和钱包；详见[续费与到期处理](../development/service-renewal-and-expiry.md)。
