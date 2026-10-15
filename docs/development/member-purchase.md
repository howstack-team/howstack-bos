# 官网展示与会员购买

## 页面职责

| 页面 | 路径 | 职责 |
| --- | --- | --- |
| 官网首页 | `/` | 展示真实可售产品和选配入口 |
| 官网云服务器 | `/products/cloud-server` | 产品介绍，不放下单表单 |
| 会员购买 | `/console/purchase/cloud-server?offer=<配置ID>` | 匿名选配、服务端报价、登录后下单 |
| 本人订单 | `/console/finance/orders` | 本人订单分页列表 |
| 订单详情 | `/console/finance/orders/<订单ID>` | 金额、状态、数量和余额付款 |

PC 与独立移动模板共用 URL、业务 Hook、API 和 Cookie，分别使用 Arco Design React 与 Arco Design Mobile。云服务器页面与订单页面都有购买入口。

产品页是宿主核心页面，导航管理可选择“云服务器”，页面 SEO 可单独配置标题、描述和关键词。目录为空时显示未供应状态，不创建演示产品或猜测起价。

## 购买与恢复

1. 匿名用户进入购买页，选择地域、可用区、线路、规格组、规格、镜像、磁盘、网络、数量及周期。周期取自当前可用区。
2. 参数变化使旧报价失效，由现有产品处理器重新计算整数分金额，过期请求不能覆盖新配置。
3. 点击“登录后继续”保存选配并进入登录。登录、注册后登录及刷新均保留返回路径；成功后恢复选配并重新报价，不自动下单。
4. 点击“提交订单”后，后端再次校验销售版本、参数、金额及库存，创建待付款订单、订单项和每实例金额分摊。
5. 创建成功清理草稿并进入订单详情。余额付款独立确认，资金及库存仍在原事务中处理。

匿名报价与未付款订单不预占库存。下单前仅检查当前余量，付款时再次原子检查；库存不足则回滚付款。当前开放人民币正价订单，单笔上限100万元，暂不开放按量计费、密钥登录、试用下单或直接在线支付商品订单。在线充值到账后可返回订单并确认余额付款，见[在线充值说明](online-payments.md)。订单写入和通知 Outbox 共同提交；待付款订单不代表供应商已创建实例，实际履约按提供者能力接入。

## 存储与权限

选配保存在当前标签页的 `sessionStorage`，键为 `console.purchase.cloud-server.v1`，有效期两小时。仅保存公开产品 ID、配置白名单、请求键和更新时间；磁盘、网络同样逐层白名单。不保存账号密码、实例密码、验证码、令牌、报价、订单或供应商参数。存储被浏览器禁止时，跳转登录前提示无法保留，不静默丢弃选配。

同一选配意图的请求键跨登录和刷新保留；修改配置生成新键，提交中阻止重复请求。服务端幂等键隔离后台操作者与会员，同键同内容返回原订单，不同内容返回409。成功下单后即使停售，相同请求重试也返回原订单。

会员归属由 Cookie 认证确定，请求中的 `member_id` 和订单状态不能覆盖身份。列表及详情在数据库条件中约束归属，其他会员订单返回404。不回显后台备注、配置快照、供应绑定及成本。公开产品选项与报价也按白名单投影，不公开内部映射或凭据。

复用 `orders`、`order_items`、`order_item_units` 和 `order_requests`，无需新增数据库表。后台与会员共用 `CreatePurchaseOrderService`，统一计价、幂等、分摊、Outbox及通知。

## 接口与验收

- `GET /api/public/v1/products`：匿名可售目录。
- `POST /api/public/v1/products/{id}/options`：匿名联动选项。
- `POST /api/public/v1/products/{id}/quote`：匿名完整配置报价。
- `GET /api/console/v1/orders`、`GET /api/console/v1/orders/{id}`：本人订单。
- `POST /api/console/v1/orders`：认证及同源校验后创建订单。
- `POST /api/console/v1/orders/{id}/pay-wallet`：原有本人钱包付款。

契约源：`contracts/openapi/public-purchase.v1.yaml`、`console-orders.v1.yaml`，统一文档入口 `/api-docs`。

后端测试：`MemberPurchaseWorkflowTest`、`AdminOrderWorkflowTest`、`WebsiteThemeTest`、`SiteThemeTest`。使用独立迁移数据库，不写开发客户数据。并发锁相关用例在 MySQL 下验证，SQLite不代替并发验收。

前端执行 `pnpm test`、`pnpm lint`、`pnpm build`、`pnpm build:template`；`tests/purchase-browser.mjs` 使用模拟响应覆盖匿名选配、刷新、登录恢复、数量/磁盘/网络保留、显式下单、防重复提交、订单详情及 PC/移动布局。模拟不代表真实短信、支付、供应商开通或线上验收。
