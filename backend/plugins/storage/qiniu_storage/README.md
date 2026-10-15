# 七牛云存储

本插件遵循通道 v1 固定契约，配置按连接保存。通过宿主受限 HTTP 实现平台协议，无额外 Composer SDK。

配置及能力边界见 [通道插件说明](../../../../docs/plugins/cloud-channels.md)。生产调用前请配置真实凭据和审核通过的业务参数。网络异常返回结果未知，不自动重发有副作用的请求。

按七牛 REST API 自行生成 HMAC-SHA1 上传令牌、V2 管理凭证与私有下载签名；不使用七牛 SDK。已安装旧包需 drain → upgrade → enable，再审核保存原连接配置以更新包快照，最后完整重启 Webman。
