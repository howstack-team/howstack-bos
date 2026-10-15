# API 文档工具

通过现有版本化契约生成受保护的 `/api-docs`，详见 [统一 API 文档](../../docs/development/api-documentation.md)。

```bash
pnpm install --frozen-lockfile
pnpm test
pnpm build
pnpm check
```

Scalar 1.73.1 和 Redocly CLI 2.60.0 均为 MIT；上游完整许可见 `licenses/`，Scalar 许可与内嵌第三方声明随静态资源交付。

`response-gaps.v1.json` 是历史响应结构缺口基线，只能随字段补齐缩减，不为新接口添加占位豁免。
