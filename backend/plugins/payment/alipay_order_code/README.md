# 支付宝订单码支付 v1

官方订单码支付插件，入口 `PaymentAdapter.php`。配置通过后台“系统管理 → 通道管理 → 支付通道”保存和加密。

- `create` 预创建订单并返回支付宝二维码；`query` 查单；`close` 关单；`verifyNotification` 验签并核对原付款快照。
- 公钥模式 RSA2，应用 ID、商户 PID、2048 位应用私钥、支付宝公钥和沙箱开关分别按通道配置。支付宝公钥与应用公钥不是同一个字段。
- SDK 由后端主 Composer 的 `alipaysdk/easysdk` 统一安装，本目录不安装 Composer/vendor。每次调用独立 Kernel，不使用静态 Factory。
- 超时、响应格式或验签异常不代表失败未产生交易；新建返回 unknown 时必须先查原付款单，不重发或切换通道。
- 插件不写核心订单或资金表；退款、证书模式、服务商代调用和完整收银流程未声明为可用能力。

完整配置、契约和联调说明见 [支付宝通道接入与插件开发](../../../../docs/plugins/alipay-channels.md)。
