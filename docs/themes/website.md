# 官网主题开发

官网由Webman + Twig渲染，一律使用自适应页面。所有官网文件放在主题 `website/` 下，缺少整个目录时使用default；目录存在但缺少必需页面不能安装。

## 页面与资源

- 首页入口：`website/index.html.twig`。
- 404入口：`website/errors/404.html.twig`。
- 公共布局、片段按需放在 `layout.html.twig`、`partials/`。
- CSS、JS、图片放在 `website/assets/`，通过 `asset('css/main.css')` 获得当前快照资源URL。
- `route('home')`、`route('console.home')` 和 `route('theme.company')` 获取宿主路径。
- 使用Twig默认转义，不用raw绕过转义；沙箱限制标签、函数和过滤器。

## 自定义页面

可选 `website/pages.json`：

```json
{
  "company": {
    "path": "/company",
    "template": "pages/company.html.twig",
    "title": "公司介绍",
    "seo": {"indexable": true}
  }
}
```

模板路径相对website。页面ID在升级时保持稳定，避免破坏导航和SEO。不能占用API、后台、会员、帮助、新闻、产品和资源路径；不注册后端Controller。

## 展示配置

可选 `website/settings.schema.json` 统一保存全局及逐页字段：

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": ["global", "pages"],
  "properties": {
    "global": {
      "type": "object", "title": "全局配置", "additionalProperties": false,
      "properties": {
        "hero_title": {"type": "string", "title": "首页主标题", "maxLength": 80, "default": "欢迎访问"}
      }
    },
    "pages": {
      "type": "object", "title": "页面配置", "additionalProperties": false,
      "properties": {
        "company": {
          "type": "object", "title": "公司介绍", "additionalProperties": false,
          "properties": {"show_contact": {"type": "boolean", "title": "显示联系方式", "default": true}}
        }
      }
    }
  }
}
```

系统从default生成初始配置，缺少必填默认值会拒绝安装。支持有限对象、数组、文字、数字、布尔及枚举；对象须additionalProperties:false，数组maxItems不超过100，文字maxLength不超过10000。图片使用format:image-id，仅保存宿主素材ID。没有配置文件时提供空global/pages。

所有显示给管理员的字段和分组都应填写中文 `title`，包括对象、页面分组和数组表格列；属性名仅作为内部标识，不作为展示文案。例如 `global` 为“全局配置”、`pages` 为“页面配置”、`company` 为“公司介绍”。

模板读取 `theme.settings` 获取global，`page.settings` 获取当前页面配置。公司资料来自 `site.company`，导航、品牌、SEO由宿主提供，不能重复在主题配置录入。可用上下文见 `contracts/themes/v1/page-context.schema.json`。

## SEO与生命周期

首页标题：网站名称 - 副标题；其他页面：页面标题 - 网站名称。后台页面SEO按core或theme:<id>保存覆盖，不受主题展示配置控制。

切换主题统一切换整套主题；同ID包更新当前记录，保留兼容展示配置。导航只迁移仍存在的自定义页目标，公共基础资料和SEO记录保留。使用 `php webman website:publish` 发布当前官网源码时也更新当前主题记录；若当前选择仅会员主题，官网来自default，发布命令会拒绝执行；维护默认源码应使用安装与主题选择流程，避免意外改变当前选择。
