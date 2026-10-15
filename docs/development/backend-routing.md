# Webman 后端路由开发规范

## 目标与适用范围

本规范适用于皓量云擎云业务运营系统 Core、运营后台 API、会员 API、公开 API 与业务插件。项目使用 Webman Framework 2.2，并采用“集中式系统路由 + Attribute 业务路由”的混合方案。

这里的“注解路由”指 PHP 8 原生 Attribute，不是 PHPDoc 注释。路由 Attribute 在 Worker 启动时被扫描并注册，请求期间复用已生成的 FastRoute 分发器。

## 路由方式选择

| 场景 | 路由方式 | 原因 |
| --- | --- | --- |
| 运营后台、会员、公开业务 API | Attribute 路由 | 路径、方法、中间件和控制器入口保持在同一模块内 |
| 上下游机器 API | Attribute 路由 | 独立认证、签名、幂等和合作方授权边界 |
| 官网、主题和页面入口 | `config/route.php` | 属于系统级页面分发，不是普通业务 API |
| `/install` 与 `/api/install` | `config/route.php` | 安装期必须在核心业务模块可用前保持显式、稳定 |
| `/console`、自定义后台路径和 SPA catch-all | `config/route.php` | 需要全局 fallback 与动态后台路径协作 |
| 健康检查与全局 fallback | `config/route.php` | 属于进程和部署边界，需要集中审查 |
| 插件业务动作与回调 | 宿主固定入口 + 审核过的清单；必要时受控 Attribute 扫描 | 固定处理器及授权边界，禁止任意插件路由；注册器按插件阶段建设 |
| 数据驱动的动态分发 | 专用注册服务或 `config/route.php` | 运行期映射不应伪装成静态控制器路由 |

现有系统路由无需为了统一形式立即迁移。修改相关业务时同步更新 URL、方法、中间件、返回结构和全部调用方；当前未上线阶段无需保留旧内部入口。

## 前端页面入口与构建资源

- 运营后台默认入口为 `/admin`，会员控制台入口为 `/console`，分别支持直接访问和深层路由刷新，不增加 `/app` 前缀。安装时自定义的后台首级路径继续由安装状态仓储校验，不自动重写客户配置。
- Vite 构建输出分别为 `backend/public/admin/`、`backend/public/console/`，资源 base 分别为 `/admin/`、`/console/`。后台 Router 从当前页面入口识别安装时的自定义路径，资源地址固定为 `/admin/assets/`。
- 静态资源路由在 SPA 路由前注册，只提供受控资源；缺失资源返回 404，不能返回入口 HTML 冒充脚本。`FrontendController` 提供页面入口，`FrontendAssetController` 提供预构建资源。
- 旧 `/app/admin`、`/app/console` 书签通过 308 跳转到对应入口并保留查询参数。后台旧资源转向 `/admin/`，旧页面转向当前配置的后台路径。
- API 前缀及后台、会员的独立认证 Cookie 保持不变。线上反向代理将未命中公开静态文件的请求交给 Webman，不额外配置 `/app` SPA 路由。

## 默认路由与暴露边界

`config/route.php` 必须保留：

```php
Route::disableDefaultRoute();
```

所有使用 Attribute 路由的控制器同时添加：

```php
#[DisableDefaultRoute]
```

双重限制用于避免配置调整、插件加载或后续重构意外重新暴露 `/controller/action` 形式的默认路由。禁止通过默认路由临时访问尚未注册的控制器方法。

## API 前缀

Core API 使用以下固定前缀：

| 调用入口 | 前缀 | 典型认证方式 |
| --- | --- | --- |
| 运营后台 | `/api/admin/v1` | 管理员身份、后台权限与审计 |
| 会员控制台 | `/api/console/v1` | 会员身份、对象归属与风控 |
| 官网公开接口 | `/api/public/v1` | 匿名或受限公开访问、限流 |
| 上下游机器接口 | `/api/partner/v1` | 合作方身份、请求签名、重放防护、幂等与商品授权 |
| 在线安装器 | `/api/install` | 安装会话与同源令牌，安装期特例 |

插件在入口前缀下增加 `plugins/<plugin_id>`：

```text
/api/admin/v1/plugins/example_plugin/...
/api/console/v1/plugins/example_plugin/...
/api/public/v1/plugins/example_plugin/...
/api/partner/v1/plugins/example_plugin/...
```

`plugin_id` 只能使用小写字母、数字和下划线，并与插件清单中的唯一 ID 完全一致。

`partner` 入口仅用于独立部署实例之间的机器调用，不接受运营后台或会员会话 Cookie 作为认证。每个写请求必须同时校验合作方身份、权限范围、时间戳、随机数、请求签名和业务幂等键；相同幂等键对应不同请求内容时返回冲突。具体交易状态、余额和资源归属仍由对应 Application 用例校验，不能由路由中间件直接修改业务数据。

## URL 与 HTTP 方法

- 路径必须以 `/` 开头，不使用结尾斜杠。
- 资源路径使用小写复数名词；多个单词使用短横线，例如 `/product-groups`。
- 路径不得包含 PHP 控制器名、方法名、命名空间或供应商内部类名。
- 标识参数使用与控制器参数一致的 lowerCamelCase 业务名称，例如 `{productId:\d+}`；可以确认上下文不会混淆时才使用 `{id:\d+}`。
- GET 只能读取，不得创建订单、修改状态或触发履约等副作用。
- POST 用于创建资源或执行无法自然表达为 CRUD 的业务命令。
- PUT 用于完整替换，PATCH 用于部分更新，DELETE 用于删除或撤销可删除资源。
- 普通业务接口禁止使用 `#[Any]`。兼容回调、协议入口或特殊兜底确需允许多个方法时，优先使用 `#[Route]` 明确列出允许的方法。
- 列表筛选、排序和分页使用查询参数；敏感数据、复杂命令和大对象不得塞入 URL。

状态流转可以使用动作路径，但必须表达明确业务语义：

```text
POST /api/admin/v1/refunds/{refundId}/approve
POST /api/console/v1/orders/{orderId}/cancel
```

动作接口必须保证状态检查、权限、幂等和审计，不得只修改一个状态字段。

## 路由名称

路由名称格式为：

```text
<入口>.<复数资源>.<动作>
```

示例：

```text
admin.products.index
admin.products.show
admin.products.store
admin.products.update
admin.products.destroy
console.orders.index
console.orders.cancel
public.products.show
```

插件路由名称增加插件命名空间：

```text
plugin.example_plugin.admin.resources.index
```

动作名称优先使用 `index`、`show`、`store`、`update`、`destroy`；领域命令使用稳定动词，例如 `approve`、`cancel`、`retry`。路由名称发布后属于跨端契约，改名时必须评估模板、重定向、测试、插件和外部调用方。

## Attribute 控制器模板

下面的产品查询控制器仅演示路由结构。Controller 仍然只负责 HTTP 边界，业务查询由 Application Service 完成。

```php
<?php

declare(strict_types=1);

namespace app\modules\catalog\controller;

use app\middleware\AdminAuthMiddleware;
use app\modules\catalog\application\ListProductsService;
use app\shared\http\ApiResponse;
use JsonException;
use Random\RandomException;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/products')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class ProductController
{
    /**
     * 初始化产品控制器。
     * @param ListProductsService $listProducts 产品列表查询服务
     * @param ApiResponse         $apiResponse  统一 API 响应生成器
     */
    public function __construct(
        private ListProductsService $listProducts,
        private ApiResponse $apiResponse,
    ) {
    }

    /**
     * 查询产品列表。
     * @param Request $request 当前 HTTP 请求
     * @return Response 统一格式的产品列表响应
     * @throws JsonException 响应数据无法序列化时抛出
     * @throws RandomException 无法生成请求追踪标识时抛出
     */
    #[Get(path: '', name: 'admin.products.index')]
    public function index(Request $request): Response
    {
        $criteria = [
            'keyword' => trim((string) $request->get('keyword', '')),
            'page' => max(1, (int) $request->get('page', 1)),
        ];

        return $this->apiResponse->success(
            $request,
            $this->listProducts->execute($criteria),
        );
    }

    /**
     * 查询产品详情。
     * @param Request $request   当前 HTTP 请求
     * @param string  $productId 产品标识
     * @return Response 统一格式的产品详情响应
     * @throws JsonException 响应数据无法序列化时抛出
     * @throws RandomException 无法生成请求追踪标识时抛出
     */
    #[Get(path: '/{productId:\d+}', name: 'admin.products.show')]
    public function show(Request $request, string $productId): Response
    {
        return $this->apiResponse->success(
            $request,
            $this->listProducts->find($productId),
        );
    }
}
```

Attribute 必须使用 `support\annotation\route` 命名空间下的路由类。禁止使用已经废弃的 `support\annotation\DisableDefaultRoute`。

## 中间件与权限

- 登录认证、入口级上下文和通用审计可以放在控制器类级 `#[Middleware]`。
- 限流、签名校验或接口专属保护可以放在方法级 `#[Middleware]`。
- 不得把细粒度权限只实现为前端菜单隐藏或 Attribute 声明；Application Service 仍需校验权限、对象归属、订单状态和领域不变量。
- 不得依赖 Attribute 的书写顺序猜测安全行为。涉及多个中间件时必须通过集成测试确认执行顺序、短路响应和异常转换。
- 全局中间件继续在 `config/middleware.php` 维护，避免在每个控制器重复声明安装守卫、请求追踪等真正全局能力。

## 插件路由

本系统业务插件的分类目录为 `backend/plugins/<category>/<plugin_id>/`，不依赖 Webman 应用插件 `plugin/` 的自动扫描。供应 SDK v1 通过宿主固定用例调用；插件没有自定义公开控制器。宿主 `/api/admin/v1/business-plugins` 提供列表和固定生命周期动作，各自执行认证、权限、来源校验与审计。注册器与固定 SDK 遵循 [业务插件体系优化方案](../architecture/business-plugin-optimization.md) 建设；动态插件页面、公开动作注册和渠道回调仍为后续能力。

渠道及供应插件由核心固定用例调用；必要回调通过宿主入口验签及核对业务证据。功能和产品扩展的路由由宿主从审核通过的清单注册；如果使用 Attribute，只允许在指定控制器目录进行受控扫描，必须同时满足：

1. 路径位于规定的插件前缀内，不能占用 Core 路径。
2. 路由名称以 `plugin.<plugin_id>.` 开头。
3. 插件清单声明 API 入口、权限、兼容版本、固定处理器和输入输出，不能传入任意类名或 PHP 方法名。
4. 安装前检查路径和名称冲突，安装后执行路由加载验证。
5. 插件启用、禁用、安装、升级或卸载后使所有 Worker 使用同一份路由表；自动加载器或启动注册绑定变化后完整 restart。
6. 插件卸载后不得残留可访问路由、菜单、权限或缓存清单。

应用商店不能仅通过反射扫描推断插件能力；插件清单是审核与兼容检查依据，宿主将它与固定处理器或已审核 Attribute 对照后注册。插件不能直接追加全局路由、中间件或绕过核心认证及权限。

## API 版本与兼容性

- `v1` 表示 API 主版本，不等于产品发布版本。
- 新增可选字段、可选查询条件或新接口通常保持当前主版本。
- 删除字段、改变字段类型、改变金额单位、改变分页结构、改变错误码语义或收紧既有必填规则属于破坏性变更。
- **当前系统未上线**：内部 API 以最新契约为准，直接更新当前 `/v1`，同步前后端、契约和测试；删除无调用的旧入口、字段别名及兼容分支，不为开发期间旧实现新增 `/v2`、双轨接口或弃用期。
- **正式发布且存在既有消费者后**：破坏性变更创建 `/v2`，并明确旧版本弃用期、迁移文档和删除计划。
- 第三方协议、安全约束、交易历史快照和已执行的数据库迁移继续按各自规则维护，不能以清理旧接口为由改写业务历史或删除校验。
- 数据结构以 `contracts/` 中的版本化契约为准；路由、请求 DTO、响应 DTO 和 OpenAPI 必须同步更新。
- 金额单位、时间格式、枚举值和可空语义不得只靠控制器实现隐式约定。

## 启动期与常驻进程约束

Attribute 路由和 `config/route.php` 都属于启动期配置：

- 路由文件或 Attribute 变更后必须 reload/restart，不能假设所有 Worker 自动发现变化。
- Attribute 参数必须是静态元数据，不执行数据库、Redis、HTTP、文件写入或随机逻辑。
- 控制器静态属性、单例和 Attribute 对象不得保存 Request、当前用户、Session 或租户状态。
- 插件数量较多时，扫描成本体现在启动与 reload 阶段；请求阶段使用已注册路由，不应在请求内重复反射扫描。
- 路由加载失败、重复方法与路径或无效控制器必须阻止发布，不能带病启动部分 Worker。

## 验证要求

新增或修改路由至少执行：

```bash
cd backend
composer validate --no-check-publish
php webman route:list
vendor/bin/phpunit
```

涉及 Attribute 路由的测试至少覆盖：

- 正确路径和 HTTP 方法能够命中目标控制器。
- 错误 HTTP 方法返回 405，而不是落入其他路由。
- 未注册路径返回 404 或预期 fallback。
- 未认证、无权限和对象不归属请求被拒绝。
- 路径参数正则、可选参数和边界值符合约定。
- 路由名称、方法与路径不存在重复。
- 插件禁用后路由消失，重新启用并 reload 后恢复。
- API 响应遵循 `code`、`message`、`data`、`request_id` 和 `X-Request-Id` 规范。

代码审查时同时确认：

- 是否错误使用默认路由或无路径 Attribute。
- 是否把系统级 fallback 写进业务控制器。
- 是否把业务逻辑、数据库访问或权限决策塞进 Controller。
- 是否遗漏版本前缀、路由名称、中间件、契约或迁移说明。
- 是否说明上线后需要 reload/restart。

## 渐进迁移策略

1. 新增正式业务模块直接使用本规范的 Attribute 路由。
2. 当前官网、安装器、控制台和后台 fallback 保持在 `config/route.php`。
3. 维护旧业务控制器时，可以按模块迁移，但一次变更只迁移一个明确边界。
4. 迁移前后对比 `php webman route:list`，确保方法、路径、名称和中间件一致。
5. 不为追求形式统一迁移稳定路由；只有可维护性、模块边界或测试收益明确时才迁移。

Webman 2.2 Attribute 路由的框架能力以官方路由文档为准：<https://webman.workerman.net/doc/zh-cn/route.html>。
