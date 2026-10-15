# 云服务器供应参数对应规范

本文面向资源提供者插件开发者。配置发布与参数预览已经实现；公共供应SDK目前提供配置校验、连接测试、目录查询和绑定校验，尚无生产、续费、释放接口。本文的v2指云服务器销售/交付数据版本，不是新的插件SDK版本。

## 分工

- 核心校验购买组合、计算售价，封存销售版本与供应资源对应。
- 每个连接按本地资源、资源类型及可用区保存外部资源编号；指定可用区优先于默认对应。
- 交付规则引用连接，并保存标准字段到供应请求字段的参数对应。
- 插件负责供应平台的协议、鉴权、请求组装及响应解释，不决定订单金额、财务状态或库存恢复。
- 参数预览不发送外部请求，也不证明平台生产能力可用。

## v2标准交付配置

以下为字段示意；实际资源对象还保留发布时的必要资源属性。`id`是本系统编号，`external_id`是已封存的供应编号；插件不能用内部编号替代供应编号。

```json
{
  "contract_version": "2.0.0",
  "product_type": "cloud_server",
  "billing_mode": "prepaid",
  "region": {"id": 1, "external_id": null},
  "zone": {"id": 2, "external_id": "area-2"},
  "line": {"id": 3, "external_id": "bgp-3"},
  "flavor_group": {"id": 4, "external_id": "node-4"},
  "flavor": {"id": 5, "external_id": null, "cpu": 2, "memory_mib": 2048, "architecture": "x86_64"},
  "image": {"id": 6, "external_id": "ubuntu-24", "default_username": "root", "default_port": 22},
  "system_disk": {"type": {"id": 7, "external_id": "ssd"}, "size_gib": 40},
  "data_disks": [{"type": {"id": 7, "external_id": "ssd"}, "size_gib": 100}],
  "network": {"billing_mode": "fixed", "egress_mbps": 10, "ingress_mbps": 100, "ipv4_count": 1},
  "protection": null,
  "duration": {"unit": "month", "count": 12},
  "quantity": 1,
  "login_method": "password"
}
```

单位固定：内存MiB、磁盘GiB、带宽Mbps、防护Gbps、周期月数。上行指实例向外发送，下行指实例接收。周期优惠只改变收费金额；例如12月按10月收费，交付期限仍为12个月。

默认用户和端口是镜像元数据，不是密码。密码及私钥不能放入资源对象、销售快照、参数对应或日志；未来生产执行需通过核心提供的独立凭据边界取得，当前接口不提供凭据。

## 对应规则

在后台「交付规则 → 参数对应」维护。每条规则包含：

| 字段 | 含义 |
| --- | --- |
| source | 标准配置的白名单字段路径 |
| target | 供应请求字段路径，如instance.memory_gib |
| transform | copy、string、multiply、divide、lookup |
| factor | 乘除的正整数系数 |
| lookup | 枚举对应对象 |
| required | 来源缺失是否拒绝构建 |

例如：

```json
[
  {"source":"flavor.cpu","target":"instance.cpu","transform":"copy","required":true},
  {"source":"flavor.memory_mib","target":"instance.memory_gib","transform":"divide","factor":1024,"required":true},
  {"source":"image.external_id","target":"instance.image","transform":"copy","required":true},
  {"source":"network.egress_mbps","target":"network.upload","transform":"copy","required":true},
  {"source":"duration.unit","target":"billing.unit","transform":"lookup","lookup":{"month":"monthly"},"required":true},
  {"source":"protection.external_id","target":"network.protection","transform":"copy","required":false}
]
```

内存2048MiB无损转换为2GiB；来源1536MiB时除以1024不能整除，构建明确失败，不能自动截断为1。供应参数路径不能重复，也不能同时填写instance与instance.cpu造成覆盖。规则最多64项；枚举最多200项，值只允许短文本或整数。

当前允许的来源：

```text
region.external_id / zone.external_id / line.external_id
flavor_group.external_id / flavor.external_id
flavor.cpu / flavor.memory_mib / flavor.architecture
image.external_id / image.default_username / image.default_port
system_disk.type.external_id / system_disk.size_gib
data_disks
network.egress_mbps / network.ingress_mbps / network.ipv4_count
protection.external_id
quantity / duration.count / duration.unit / login_method
```

可选防护或未声明的目录可能没有external_id，相关字段必须设为可选。CPU、内存等数值可以直接传递，不要求平台一定提供“规格目录”。目录及绑定校验仍使用公共ProviderAdapterInterface，详见[资源供应插件规范](../development/provider-adapters.md)。

## 数组与平台特殊协议

data_disks可以整体复制为数组。需要数组逐项改名、不同层次嵌套、附加平台固定字段或签名时，由审核过的插件代码组装；宿主不执行管理员提交的PHP、SQL或任意表达式。

对应规则只负责请求数据转换，不允许修改已校验配置、赠送政策或价格。后续生产接入必须核对平台所支持的计费方式、上下行带宽、周期、多块磁盘和幂等能力；不能因为预览成功就开放购买。

## 版本与验证

- 发布封存资源编号、完整参数规则、插件及连接版本。更改当前规则不覆盖历史绑定。
- v1旧配置仍使用单带宽和旧周期；不得自动把其字段解释为v2上下行。
- 宿主在配置与交付预览中检查组合、字段缺失、单位转换和枚举对应；目录绑定在发布时逐可用区校验。当前不执行所有数值组合的穷举参数预览。
- 插件至少验证多可用区资源编号、CPU/内存单位、不同磁盘、上下行、无防护、周期、来源缺失及历史规则隔离。
- 真实生产、异常查询、重试和释放必须在对应SDK接口实现并完成平台联调后另行开放。

## 按可用区配置提供者

社区工作台在可用区中选提供者；资源对应仍由连接统一维护，高级参数在当前可用区设置。v2 草稿增加可选 `zone_provider_bindings`（zone_id、connection_id、parameter_mapping），参数规则继续使用本文标准源和受控转换。发布时按可售可用区验证并封存到 `provider_bindings.zone_bindings`；预览按购买选择的 zone_id 读取相应连接及映射。旧版本无此字段时仍读取原单连接绑定，禁止推断或改写历史提供者。连接凭据不进入草稿、映射或版本快照。
