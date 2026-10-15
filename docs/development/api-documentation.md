# 统一 API 文档

## 入口与权限

统一入口为 `/api-docs`，后台右上角头像菜单提供 **API 文档** 入口。支持中文界面、模块目录、搜索、参数与响应说明、代码示例、下载和同源接口调试。

| 分组 | 原始 OpenAPI 地址 | 范围 |
| --- | --- | --- |
| 运营后台 | `/api-docs/specs/admin.json` | `/api/admin/v1` |
| 会员控制台 | `/api-docs/specs/console.json` | `/api/console/v1` |
| 公开与安装 | `/api-docs/specs/public.json` | 已注册的公开及安装接口 |
| 合作方 | `/api-docs/specs/partner.json` | 预留，不显示空分组 |

页面和原始文件均要求有效管理员会话及 `admin.api-docs.view`，角色权限分配中可选择。未登录的页面请求跳往客户配置的后台登录地址，原始文件返回 401；无权限返回 403，撤销权限后下次读取立即生效。创始人沿用现有全权限规则。

“公开与安装”是接口分类，文档仍不匿名开放。安装流程和会员流程各自执行认证/临时会话校验；管理员 Cookie 不能代替会员身份。文档访问权限不会授予任何业务操作权限。

可设置后端 `API_DOCS_ENABLED=false` 并重启，页面和原始文件均返回 404。静态 JS/CSS 是公开组件资源，不含真实数据、密钥或业务契约。

## 技术与目录

使用 [Scalar](https://github.com/scalar/scalar) 本地组件呈现，使用 [Redocly CLI](https://github.com/Redocly/redocly-cli) 打包和校验。契约唯一源为 `contracts/openapi/*.vN.yaml`，不在 Controller 中再维护一份文档。

构建时读取 Webman 实际注册路由、HTTP 方法、路径正则和认证中间件；运行时不扫描源码、不运行 Node.js。模块组件加命名空间，外部 JSON Schema 引用打包成本地组件，OpenAPI 3.0 可空类型转换成 3.1 联合类型。

```text
contracts/openapi/                       按模块维护的契约源
tools/api-docs/                           工具、锁文件和检查
backend/app/modules/api_documentation/    认证、授权和文件读取
backend/resources/api-docs/               生成的 HTML、契约和报告，位于 Web 根目录外
backend/public/api-docs/assets/           生成的本地 Scalar 和配套资源
```

`admin-list-filters.v1.yaml` 补充现有列表的查询参数：有主业务契约时只合并参数和说明，不覆盖请求/响应。其他重复接口直接报错。

## 开发阶段契约规则

当前系统未上线，内部 API 文档只描述最新实现。字段改名、必填调整和入口删除直接修改当前 v1 契约，同步前后端与测试，不保留开发期间旧字段别名、过时接口或缓存兜底，也不为此新增 v2 或标注弃用。正式发布且存在既有消费者后再执行版本迁移策略；第三方协议和业务历史快照按各自约束维护。

## 构建、检查和发行

需要 PHP 8.4、已安装的后端 Composer 依赖、Node.js 24 和 pnpm 11.22.0。构建只注册路由，不执行业务请求、连接数据库或启动服务，不依赖客户 `.env`。

```bash
cd tools/api-docs
pnpm install --frozen-lockfile
pnpm test
pnpm build
pnpm check
```

`build` 生成契约、覆盖报告和静态资源；`check` 重新计算并核对生成结果及全部动态模块。未记录路由、失效契约、重复操作、重复 operationId、失效引用、新增响应缺口、缺失或过期资源均阻断检查。

GitHub Actions 的 `api-documentation.yml` 执行契约测试、构建一致性、文档权限测试及角色入口测试；不代表全部业务接口已联调。

发行包必须包含 `backend/resources/api-docs/` 和 `backend/public/api-docs/assets/`，后台菜单也须重新构建。客户服务器无需文档工具和 Node.js。Scalar 许可证及内嵌第三方许可声明随包保留，工具不改变项目及第三方各自的许可证。

Web 根目录仍为 `backend/public/`，禁止公开映射 resources。代理将 `/api-docs` 和 `/api-docs/specs/` 转发给 Webman且不缓存，只有 assets 子目录可作为静态资源。后台 Vite 已代理页面/契约，并流式提供本地资源。改动路由或权限后按部署流程重启后端。

## 现有字段缺口

路由正则的多个合法取值可拆成具体操作。当前路由及契约操作覆盖结果见 `backend/resources/api-docs/coverage.json`。

部分当前开发中的成功响应仅有说明，或复杂业务数据未完整定义（云服务器、供应映射、财务、安装等），报告当前列出 39 个响应缺口。**路由覆盖不等于全部响应字段已定义**；明确未完成结构通过 `x-schema-status: partial` 标记。

`response-gaps.v1.json` 固定已有缺口，只能随字段补齐缩减；禁止为新接口或回归增加豁免。修改缺口接口时补充真实 DTO Schema，再移除对应基线项。无正文 204 和错误说明不误算为成功数据缺口。

## 新增与修改接口规则

1. 更新所属模块契约，提供方法、路径、中文 summary 和稳定唯一的 operationId。
2. 参数写明位置、类型、范围、枚举、默认值与必填；path 参数必须 required。请求体明确输入白名单。
3. 定义成功和关键错误响应，保留 `code/message/data/request_id`，成功 code 为 0。金额为整数分，ID、时间与 null 的语义跟真实 DTO 一致。
4. 写明权限码、认证、同源、幂等、并发和状态限制；页面已登录不能替代业务授权。
5. 示例用虚构信息，秘密字段 writeOnly；禁止写入验证码、密码、JWT、Cookie、Webhook、密钥和真实客户信息。
6. 执行 build/check，交付契约源、锁文件与构建结果，不手改生成内容。

## 实际响应与浏览器验收

`pnpm test:responses` 只调用固定 GET：管理员资料、权限目录、会员列表、订单列表和会员注册选项。Ajv 使用完整 OpenAPI 引用根校验实际响应，并检查错误类型可被拒绝及 `X-Request-Id` 一致。

设置 `API_DOCS_TEST_ORIGIN`（默认 `http://127.0.0.1:8989`）和 `API_DOCS_TEST_SESSION`，后者指向禁止提交的受保护 JSON 文件，内容为测试账号 Cookie：

```json
{"name":"hlyq_admin_token","value":"有效测试会话值"}
```

脚本不创建账号、签发令牌、修改数据库或自动遍历写接口；过期会话与权限不足明确失败。只读响应验收不代表支付、短信、邮件等外部链路通过。

浏览器应检查：未登录跳转、受保护文件 401、分组切换、搜索、下载、只读请求调试、390px 无页面横向溢出、无外部资源请求和控制台错误。

文档禁用 AI、遥测、外部字体及外部请求代理，认证输入不持久化；HTML/契约 no-store，CSP 限制同源资源及请求。浏览器同源调试自动携带 Cookie，但调试发送的是真实请求，写入、删除、付款须在测试环境操作。
