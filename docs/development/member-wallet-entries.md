# 会员本人资金明细

实施日期：2026-10-19。对应社区版 1.0 清单 R02。

## 范围与入口

复用 `wallet_entries` 不可变账本，提供本人只读查询。PC 财务管理、总览与资金账户恢复「资金明细」；移动端由「我的 → 资金账户 → 资金明细」进入。两端业务路径均为 `/console/finance/transactions`，默认每页 20 条，分页保存在 URL，可刷新、前进和后退。

PC 提供表格及 10/20/50/100 条分页，横向滚动限定在表格内部。独立移动模板使用卡片和上一页/下一页，长单号换行。空数据、接口失败重试及 Message/Toast 已接入。此次不增加明细编辑、删除、提现受理、下载、复杂筛选或新的财务门户。

## API

`GET /api/console/v1/wallet/entries`

需要有效会员 HttpOnly Cookie，仅接受以下查询参数：

| 参数 | 默认 | 约束 |
| --- | --- | --- |
| `page` | 1 | 整数，1～100000 |
| `page_size` | 20 | 10、20、50、100 |

其他参数（包括 `member_id`、`account_id` 和任意排序参数）返回 422，字段反馈放在统一错误信封的 `data.errors`。会员身份来自服务端会话，不能由客户端指定。列表按发生时间倒序、内部流水 ID 倒序稳定分页；持续有新流水时，跨请求的偏移分页不承诺快照冻结。

成功响应保留 `{code,message,data,request_id}` 信封与 `X-Request-Id`，数据结构：

```json
{
  "data": [{
    "entry_no": "WL202610190001",
    "entry_type": "spend",
    "amount_minor": -1200,
    "available_after_minor": 700,
    "currency": "CNY",
    "business_type": "order",
    "business_no": "ORDER202610190001",
    "created_at": "2026-10-19 12:00:00"
  }],
  "total": 1,
  "page": 1,
  "page_size": 20
}
```

金额是人民币整数分，前端以元显示，保留两位。`amount_minor` 是**可用余额变动**，`available_after_minor` 是该笔发生后的可用余额快照，不能当成当前余额。冻结扣减可用余额、驳回解冻增加可用余额；从冻结金额完成真实打款时，可用余额不再扣减，因此 `withdrawal_payout` 的变动为 0。现有账本没有每笔冻结余额快照，本次不推算或伪造历史冻结余额。

`business_type` 为 `recharge/order/refund/withdrawal/other`。只补充本人的充值号、订单号、退款号或退还号；来源记录缺失、来源不属于本人或无法识别时，单号为 `null`。前端将未知流水类型显示为「其他变动」，保留历史金额事实。

完整契约：[`console-wallet-entries.v1.yaml`](../../contracts/openapi/console-wallet-entries.v1.yaml)。

## 归属、只读与缓存

- 控制器鉴权并验证分页，Application 通过专用只读 Contract 调用 Infrastructure ORM 投影；不修改现有记账门面。
- 同时限定账本的会员归属和资金账户的会员归属，账户限于 CNY 充值现金。补充业务单号的每个关联也限定本人；只读订单号联表显式选列，不写订单模块。
- 输出字段白名单，不返回会员/账户/来源内部 ID、支付平台流水、付款凭证、审核原因或私有备注。
- 空读不创建账户，不补记账本，不修改余额；没有按内部 ID 获取他人流水或写入明细的接口。
- 响应禁止缓存：`Cache-Control: no-store, private`、`Pragma: no-cache`。
- React Query 的键包含本人公开 ID 和分页，复用现有会话切换机制取消请求和清空本人缓存，退出后不能由迟到响应写回旧明细。

## 运行与验证

无数据库迁移。新增路由与容器绑定需要完整重启 Webman；默认 PC/移动主题须重新构建并通过 `php webman website:publish` 发布。

已完成本地验证：

- `MemberWalletEntriesTest.php`：SQLite 及独立 MySQL 测试库各 4 项 / 60 断言。通过真实账务门面生成充值、订单付款、退款、冻结、解冻、打款流水，再与余额核对；验证两会员、错误账户/来源关联、空读、同时间分页、非法参数和只读性。不调用支付或供应商。
- `member-wallet-entries-http.php`：真实 Webman/MySQL 会话登录、匿名拒绝、双会员隔离、默认 20 条及分页、字段白名单、422、禁止写接口、停用/退出旧会话、禁止缓存及空读不建钱包。仅创建临时会员和合成账本，`finally` 清理，不产生真实交易；脚本必须显式启用且限本地开发数据库。
- `wallet-entries.test.ts`：3 项金额精度、分页及响应 DTO 边界测试。
- `wallet-entries-browser.mjs`：PC 1440px、移动 390px/320px，模拟 API 下验证入口、正负/零金额、默认条数、翻页/刷新/后退、PC 改条数、空态、错误重试、跨标签账号切换、旧响应取消、退出及布局；不请求业务写接口。
- 控制台 lint/build、默认 PC/移动模板构建及 API 文档 test/build/check。契约覆盖 230/230 注册路由、246 个操作；仍有 27 个既有成功响应结构缺口，本次没有新增缺口豁免。

上述证据不代替真实商户支付、真实资金清退、手机真机及生产发行验收。发布清单 D04 仍需对发行候选的真实业务记录核对，工单 R03 等后续阻断项仍保留。
