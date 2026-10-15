# 官网页面 SEO

## 管理与标题规则

入口：内容管理 → 官网管理 → 页面 SEO，后台相对路由 `/website/seo`。列表来自核心首页和当前主题声明的页面，不允许在 SEO 页面创建任意路由。

- 首页：`网站名称 - 副标题`；副标题为空只显示网站名称。
- 其他页面：`页面标题 - 网站名称`；未填写标题时使用主题声明的页面名称。
- 网站名称取自系统设置的平台名称；已包含相同前缀或后缀时不重复拼接。
- 描述和关键词：逐页非空编辑值 → 当前页面主题声明 → 不输出。首页内容不作为其他页面的全站默认值。
- 索引：逐页布尔值 → 页面声明 → 默认允许；`false` 禁止、`null` 恢复默认。未知页面和独立预览始终禁止索引。
- 官网 canonical 从官网地址和真实页面路径生成，不提供任意 canonical 编辑。

## API 与权限

唯一契约：`contracts/openapi/admin-website.v1.yaml`。

| 方法 | 地址 | 权限 |
|---|---|---|
| GET | `/api/admin/v1/website/seo` | `admin.website.seo.view` |
| PUT | `/api/admin/v1/website/seo/pages/{id}` | view + `admin.website.seo.update` |

保存必须带当前官网 revision 和页面 scope；首页为 `core`，其他页面为 `theme:<主题ID>`。与基础设置、导航、主题切换共用修订，过期返回 409 并保留输入；主题不符 422、页面不存在 404。写入需同源验证。

```json
{"revision": 2, "scope": "core", "settings": {"title": "专业云计算服务", "description": "提供云计算服务。", "keywords": "云服务器,云计算", "indexable": null}}
```

响应包含 `revision`、`active_theme_id` 和 `pages`。每页返回编辑值 settings、生效值 effective、逐字段来源 sources；不再返回 defaults，也不再提供 `/seo/defaults` 写入接口。

## 存储与渲染

`website_page_seo` 联合主键 `(scope,page_id)`：标题正文120字、描述500字、关键词255字，索引开关可空。首页跨主题保留，其他页面按主题隔离；停用主题的 SEO 保留，切回恢复。正式配置和主题快照在一致事务内读取；预览封存创建时的页面 SEO，不随后续正式编辑改变。Twig 转义标题和元信息，主题应使用统一 seo 上下文。

审计只保留页面标识、作用域及修订，不记录全文。基础、导航、主题页面不得重复提交 SEO 字段。

## 前向迁移

`20261011000300` 创建逐页 SEO 表和权限。`20261011000500` 将原默认标题、描述、关键词迁到核心首页，已有首页非空编辑值和索引开关优先保留，然后移除 website_settings 中的三个旧键。其他页面不再继承全站默认值。迁移可重试，旧值移除后不会覆盖新编辑。

升级先备份数据库，再执行 `php webman system:migrate --force`，重载后端。已执行迁移不可修改，回退使用备份恢复或前向修复。
