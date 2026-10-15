# 支付宝身份验证 v1

个人身份验证插件，入口 `IdentityAdapter.php`。配置通过后台“系统管理 → 通道管理 → 实名认证”保存和加密。

- `initialize` 使用 FACE 场景获取认证号并返回已签名认证 URL；`query` 查询并验签 passed 结果。
- 公钥模式 RSA2，配置应用 ID、2048 位应用私钥、支付宝公钥及沙箱开关。产品开通和沙箱能力以支付宝应用实际权限为准。
- SDK 由后端主 Composer 的 `alipaysdk/easysdk` 统一安装，本目录不安装 Composer/vendor。每次调用独立 Kernel。
- 浏览器返回不能标记通过；宿主持久化申请与 certify_id 归属，再以主动查验结果更新状态。不返回姓名、证件原文或人脸材料。
- 企业实名认证、证书模式、完整会员认证页面与状态流程未包含在本插件内。

完整配置、契约和联调说明见 [支付宝通道接入与插件开发](../../../../docs/plugins/alipay-channels.md)。
