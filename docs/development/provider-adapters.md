# 资源供应插件开发规范

## 当前实现与能力边界

业务插件使用 `backend/plugins/provider/<plugin_id>/`，固定入口为 `plugins\provider\<plugin_id>\ProviderAdapter`。Webman 的 `backend/config/plugin/` 与依赖保留，旧 `backend/plugin/`、`provider.json` 和 `config/app.php` 已移除，不同时扫描两种格式。

魔方云版本 `1.1.0` 使用公共供应 SDK `1.0`。当前能力为配置校验、连接认证、只读目录查询与供应绑定校验，**没有开通、续费、释放及资金处理能力**。认证和目录协议仍须在指定魔方云版本的测试环境联调。

宿主供应 HTTP 路径、权限码及连接返回结构保持兼容。新建连接只可选择已审核、已启用且兼容的插件；多个连接各自保存加密凭据，插件实例不缓存客户配置。

## 标准包与配置

固定入口 `ProviderAdapter.php` 放在插件根目录，命名空间为 `plugins\provider\<plugin_id>`。内部辅助代码按需放入 `app/`；不要求空目录，不加载旧 `api/ProviderAdapter.php`。调整后请校验插件并完整重启 Webman。

参照 [魔方云清单](../../backend/plugins/provider/magic_cloud/plugin.json) 和 [配置声明](../../backend/plugins/provider/magic_cloud/configuration.schema.json)。

| 文件或字段 | 作用 |
| --- | --- |
| `plugin.json` | 身份、类别、版本、Core/PHP/SDK 兼容范围与固定能力 |
| `configuration.schema.json` | `schema_version=1.0` 的受限配置 DSL，当前供应插件使用 `scope=connection` |
| `provider` | 类型版本、产品标识与 `catalogs`，资源种类对应外部目录 |
| `network` | 配置中平台地址字段，以及固定 HTTP 方法和相对端点白名单 |
| `permissions/frontend/migrations` | 当前必须为空；动态页面、插件迁移与其他类别 SDK 后续建设 |
| `data_policy` | 当前仅允许 `retain`，停用或卸载登记保留历史记录 |

JSON 文件不超过 64 KiB、深度 16；最多 16 个配置字段。支持 `text/password/url/integer/boolean/select`，文本有限长，整数有上下界，枚举只有固定文本值。默认值须符合字段规则；密码不能在包内设默认值。未知声明、未知提交字段与不支持能力明确拒绝，不执行脚本或远程 Schema 引用。

本协议是受限 DSL，**不兼容任意 JSON Schema**。`password` 代表秘密字段：加密保存、响应仅返回 `configured_secrets`。更新留空保留旧值；清除必须显式提供 `clear_secrets`，必填秘密不允许清空。规范化后宿主再次验证类型和范围；保存不发起外部请求。

包 ID 在全部类别中唯一。运行包拒绝符号链接、`.env`、配置 PHP、依赖树及超限文件；摘要绑定全部文件。静态扫描检查 SDK v1 对核心内部实现、ORM、原始网络和动态执行的常见违规依赖。**静态检查及受限接口不能隔离恶意 PHP，代码仍须人工审核。**

## 固定 SDK v1 接口

入口实现 [公共 ProviderAdapterInterface](../../backend/app/shared/plugin/contract/ProviderAdapterInterface.php)，构造只接收 [ScopedHttpClientInterface](../../backend/app/shared/plugin/contract/ScopedHttpClientInterface.php)。

| 方法 | 输入 → 输出 | 边界 |
| --- | --- | --- |
| `validateConfiguration` | `Configuration` → `Configuration` | 纯校验及规范化；不能网络访问 |
| `testConnection` | `ConnectionContext` → `ConnectionTestResult` | 只读认证；不生产或扣款 |
| `listCatalog` | `ConnectionContext`、`CatalogQuery` → `CatalogPage` | 声明目录、有限分页、白名单元数据 |
| `validateBinding` | `ProviderBinding` → `void` | 校验宿主提供的资源、映射与目录；无数据库、HTTP 和生产 |

DTO 位于 `app/shared/plugin/dto/`。使用 `PluginException` 提供安全错误码及中文提示，不能拼接响应正文、凭据或内部路径。核心通过桥接器转换为既有业务异常，不提供任意方法调用入口。

注入的 `ScopedHttpClientInterface` 同时提供 `get/post/put/delete` 快捷方法及 `send`，调用示例见 [HTTP 请求指南](../plugins/http-client.md#供应插件的快捷请求)。HTTP 门面固定本连接地址、审核端点、TLS 校验、10 秒超时、禁止重定向及 2 MiB 响应上限。使用该门面时不能注入其他 URL、关闭证书校验或设置自动重试。地址由有权限的管理员配置，允许合法内网供应平台；当前没有 DNS/IP 固定或进程网络沙箱，不声称具有完整 SSRF 隔离能力。

系统尚未上线，当前协议统一为 **v1**，清单中的 `manifest_version`、`requires.plugin_sdk`、`requires.provider_sdk` 和配置 `schema_version` 均为 `1.0`。全部供应插件实现上述公共 DTO 接口；常规请求可使用宿主受限 HTTP 客户端，特殊平台可按需使用独立 Composer SDK，访问范围和请求结果仍须按平台契约审核；不维护旧数组入口或双版本兼容分支。`app/modules/provider/contract/ProviderAdapterInterface` 只属于宿主内部调用端口，不是另一个插件协议。`ProviderAdapterSelectionTest` 用第二个插件验证同一协议下的扩展、连接隔离及目录查询。

## 连接、映射与版本

- 保存明确选择平台，不允许更换已有连接的平台身份。
- 插件包、配置或字段变化使连接需要重新测试，启用和发布前强制检查。
- 配置变更或新版本重测按既有规则清除当前目录与映射，管理员重新确认对应关系；历史发布绑定、订单与凭据身份不改写。
- 对应键为 `(connection_id, resource_kind, local_id, scope_zone_id)`；可用区作用域优先于默认关系。单个资源可以对应多个可用区。
- 发布逐可用区检查外部资源所属及启用状态；CPU、内存、各块磁盘容量、带宽、IP 等通过完整标准配置传递。
- “停止接单”禁止新增或修改供应商、重新启用供应商及发布新交付绑定，允许现有只读测试和目录查询。尚无真实生产任务，任务排空与存量资源续期的能力在后续阶段增加。

## 安装、升级与运维

本地插件维护通过后端命令执行；连接设置位于 **产品管理 → 资源供应商**，不同连接分别配置与测试。

```bash
cd backend
php webman business-plugin:validate magic_cloud
php webman business-plugin install magic_cloud
php webman business-plugin enable magic_cloud
php webman business-plugin list
```

安装是登记运维审核后已放到服务器的本地包，不提供上传压缩包、远程下载或 Shell 安装脚本。新部署默认不会自动启用未经登记的包。数据库先备份，再通过 `php webman system:migrate --force` 创建安装和审计表。

更新时先 `drain` 停止接单，禁用关联供应商，再 `upgrade` 审核当前文件、`enable` 启用插件，并重测连接。`disable` 不会批准新文件；`uninstall` 有任何供应商引用时拒绝。卸载只撤销安装登记，保留事件，文件由运维清理。审批和启停带乐观锁，状态与事件同事务写入；供应商写入先锁插件，再锁连接。

改自动加载或启动绑定后完整 `restart`，其他包文件变化由监控重载。包文件部署须由运维完成并使所有 Worker 使用同一版本；当前没有多进程热切换协调器。

## 开发工具与验证

```bash
php webman business-plugin:make-provider example_cloud "示例平台"
php webman business-plugin:validate example_cloud
```

脚手架生成固定目录、JSON、四个固定方法和说明，拒绝覆盖已存在的全局 ID。骨架方法明确失败，必须实现协议及测试后再审核。校验命令不改数据库和启停状态，也不代替外部联调。

验证至少包括多连接隔离、未知字段、私密字段保留/清除、目录分页、端点越界、异常与超大响应、能力不足、引用保护、重复操作、版本变化、事务回滚及权限拒绝。已提供生命周期、第二适配器、魔方云 HTTP 和云服务器发布集成测试；使用独立 SQLite/MySQL 数据库，不调用生产接口。

其他类别、真实生产、可靠任务、渠道回调、包上传/签名发布与动态前端加载见 [业务插件方案](../architecture/business-plugin-optimization.md) 的后续阶段。
