# 阿里云 OSS

本插件遵循通道 v1 固定契约，配置按连接保存。官方 SDK 由本插件目录的 Composer 和锁文件管理。构建命令：`composer install --no-dev --no-scripts --no-plugins --prefer-dist`；发行包须包含本目录生产 vendor，运行时由宿主审核加载。

配置及能力边界见 [通道插件说明](../../../../docs/plugins/cloud-channels.md)。生产调用前请配置真实凭据和审核通过的业务参数。网络异常返回结果未知，不自动重发有副作用的请求。
