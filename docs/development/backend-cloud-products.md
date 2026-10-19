# 云服务器产品后端

## 代码归属

当前实现按业务所有权划分：`compute` 负责云服务器资源、价格、周期、工作台、供应绑定编排和后台控制器；`product` 负责通用产品身份、销售版本、类型注册及公开购买编排；`order` 负责交易事务、核价和实例分摊。具体依赖、已关闭入口和历史数据边界见[产品模块边界](../architecture/product-module-boundaries.md)。

云服务器后台为五个三级页面：地域和可用区、规格与套餐、线路和网络、镜像和存储、购买与计费。使用各自路由和抽屉编辑，不使用旧十项 Tab 或独立销售方案、交付规则编辑页。

## 配置与计价

- 当前可维护资源为九类，资源通过专属关联复用于多个可用区；旧网络策略停止维护，网络参数与价格归线路。
- 同一规格和存储类型保留默认价格，并支持可用区独立价格；接口金额为整数分。留空继承，显式零价免费。
- 付费周期及优惠按可用区维护。先按当前区月单价计算配置费用，再应用周期规则，系统盘赠送容量按每台抵扣。
- 配置、默认指针及 revision 使用既有事务和并发校验；完整配置保存后记录不可变生效版本，缺项明确返回 `configuration_ready = false`，不覆盖原生效版本。
- 当前保存请求必须为包年包月 v2。旧全局网络、策略指针和重复策略选择不再进入新草稿，新快照不封存内部零网络适配。
- 旧草稿需明确转换后保存；历史类型和供应绑定继续按原规则读取，不修改历史版本、订单金额或存量服务。

详细规则见[包年包月配置](cloud-server-prepaid.md)和[官网展示与会员购买](member-purchase.md)。

## 当前接口

统一前缀 `/api/admin/v1`，使用管理员认证、服务端权限及同源写入校验。唯一接口文档源为 `contracts/openapi/`，页面入口 `/api-docs`。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /cloud-servers/sections/{section}` | 读取有权访问的五分区数据，不写入草稿 |
| `GET /cloud-servers/configuration` | 当前配置、明确默认指针及已有选择 |
| `PUT /cloud-servers/configuration/live` | 保存当前 v2 配置并尝试生效 |
| `GET /cloud-products/{id}` | 配置详情 |
| `PATCH /cloud-products/{id}/status` | 启停当前配置 |
| `GET /cloud-products/{id}/versions` | 不可变历史配置 |
| `POST /cloud-products/{id}/preview` | 配置联动、整数分报价或交付参数预览 |
| `GET /compute-resources` | 九类可维护资源 |
| `POST /compute-resources/{kind}` | 新建资源 |
| `PUT /compute-resources/{kind}/{id}` | 修改资源 |
| `GET/PUT /cloud-servers/inventory` | 按可用区与规格查询、设置库存 |

独立销售方案 CRUD、独立发布、旧交付规则编辑和重复草稿保存入口已经移除。新增和修改权限仍由当前保存入口分别校验；移除旧 API 不授予其他入口更宽的权限。

## 购买与开通

公开目录、选项及报价由 `PublicProductPurchaseService` 通过注册契约调用类型逻辑；云服务器公开字段投影位于 `compute/application/ComputePurchaseHandler.php`，不在通用产品层定义。

PC 与移动端共用会员购买接口。匿名可选配，登录恢复后重新报价；下单再次校验销售版本、金额和库存。钱包付款同事务扣款、占用库存及逐台创建开通任务，外部调用在事务外执行，失败与未知结果按幂等查询及人工核查处理，见[自动开通](cloud-provisioning.md)。

`product` 内没有云服务器类依赖，订单 Application 不判断 `cloud_server`。未知类型不能默认放行购买或库存办理；第二产品的通用接入由测试证明，不代表其他产品业务已经实现。
