# 统一主题架构

当前规则以[第三方主题开发指南](../themes/development.md)为准。

- 一个主题统一启用，官网与会员中心不再独立选择。
- 官网集中在website，使用自适应Twig。会员集中在console，PC必备、mobile可选，不再支持responsive类型。
- 可只开发一个组件，缺少整个组件时使用完整default；存在的会员组件必须提供PC；移动端开关关闭时手机也使用本主题PC。
- theme.json只维护元数据及必要页面能力。官网页面声明、配置规则、默认值及资源全部位于website；配置默认值写入Schema，不单独维护默认值文件。
- site_theme_releases每个主题ID只保存一条当前包记录，version仅为包资料；site_theme_configurations维护每个主题每个组件的展示配置。更新保留记录ID与兼容配置，不保存历史版本目录。
- site_theme_bindings单例维护release_id、default_release_id、mobile_enabled与revision，切换使用一个乐观锁。
- website_settings.active_release_id是解析后的官网主题记录，随统一选择在同一事务更新；品牌、公司和导航仍属于官网公共设置。
- website_page_seo维护独立SEO覆盖。console_theme_bindings已通过前向迁移删除。
- ConsoleThemeRuntimeService读取同一个会员组件主题，仅在移动端开关开启且主题具备移动入口时按设备选入口，其他情况使用PC，注入公开配置及base。不会跨主题拼接文件。
- FileThemeRegistry校验目录、页面、Schema及资源并发布私有快照与公开资源；ZIP安装不接受旧清单。
- 历史解析仅用于已执行迁移重放和旧快照转换，新管理API和主题包统一使用当前契约。
- 新迁移保留公共资料、配置修订、素材引用和旧快照；旧格式版本转换为新版本，统一选择原官网，原官网内置且会员为第三方时优先会员主题。

后台“内容管理 → 主题管理”在主题卡片直接启用、设置受模板能力限制的移动端开关及配置官网，支持上传安装或更新与查看当前主题包资料。使用PUT /api/admin/v1/themes/bindings统一切换，不提供独立预览。
