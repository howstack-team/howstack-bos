<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 补齐现有核心表中文注释；释义固化在本迁移，不依赖以后变化的模型。 */
final class AddCoreDatabaseComments extends AbstractMigration
{
    private const array COMMENTS = [
        'admin_departments' => [
            'comment' => '系统部门',
            'columns' => [
                'id' => '系统部门主键标识',
                'name' => '系统部门名称',
                'sort_order' => '排序值，按升序排列',
                'description' => '描述，可为空',
                'system_key' => '系统内置部门标识，root表示根部门，NULL表示普通部门',
                'parent_id' => '上级部门标识，关联admin_departments.id；NULL表示根部门',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'admin_login_logs' => [
            'comment' => '系统用户登录日志',
            'columns' => [
                'id' => '系统用户登录日志主键标识',
                'admin_user_id' => '登录系统用户标识，关联admin_users.id；失败且未匹配用户时可为NULL',
                'username' => '登录时使用的管理员账号快照',
                'nickname' => '登录系统用户昵称快照',
                'status' => '登录结果：1成功，0失败',
                'client_ip' => '业务发生时的客户端IP快照',
                'client_ip_location' => '业务发生时的IP地点快照，未知或私网使用统一降级文本',
                'browser' => '浏览器名称及版本快照',
                'operating_system' => '操作系统名称及版本快照',
                'failure_reason' => '登录失败原因，成功时为空',
                'logged_at' => '登录发生时间',
            ],
        ],
        'admin_operation_logs' => [
            'comment' => '系统用户操作日志',
            'columns' => [
                'id' => '系统用户操作日志主键标识',
                'admin_user_id' => '系统用户标识，关联admin_users.id',
                'operator_username' => '操作系统用户账号快照',
                'operator_nickname' => '操作系统用户昵称快照',
                'module' => '操作所属业务模块',
                'operation' => '操作内容摘要',
                'method' => 'HTTP请求方法',
                'request_url' => '请求URL路径，不包含敏感查询参数',
                'status' => '操作结果：1成功，0失败',
                'http_status' => 'HTTP响应状态码',
                'client_ip' => '业务发生时的客户端IP快照',
                'client_ip_location' => '业务发生时的IP地点快照，未知或私网使用统一降级文本',
                'duration_ms' => '请求处理耗时，单位毫秒',
                'browser' => '浏览器名称及版本快照',
                'operating_system' => '操作系统名称及版本快照',
                'request_id' => '请求追踪标识，用于关联业务日志',
                'request_params' => '经过白名单筛选和脱敏的请求参数，JSON对象',
                'operated_at' => '操作发生时间',
            ],
        ],
        'admin_role_departments' => [
            'comment' => '角色自定义数据权限部门关联',
            'columns' => [
                'role_id' => '角色标识，关联admin_roles.id',
                'department_id' => '部门标识，关联admin_departments.id',
            ],
        ],
        'admin_role_permissions' => [
            'comment' => '角色权限关联',
            'columns' => [
                'role_id' => '角色标识，关联admin_roles.id',
                'permission_code' => '后端权限目录中的稳定权限编码',
            ],
        ],
        'admin_roles' => [
            'comment' => '系统角色',
            'columns' => [
                'id' => '系统角色主键标识',
                'name' => '系统角色名称',
                'code' => '系统角色稳定业务编码',
                'sort_order' => '排序值，按升序排列',
                'description' => '描述，可为空',
                'data_scope' => '数据范围：all全部，department_and_children本部门及下级，department本部门，self本人，custom指定部门',
                'status' => '状态：1启用，0禁用',
                'is_system' => '是否系统内置角色：1是，0否',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'admin_user_roles' => [
            'comment' => '系统用户角色关联',
            'columns' => [
                'admin_user_id' => '系统用户标识，关联admin_users.id',
                'role_id' => '角色标识，关联admin_roles.id',
            ],
        ],
        'admin_users' => [
            'comment' => '系统用户',
            'columns' => [
                'id' => '系统用户主键标识',
                'username' => '管理员登录账号',
                'nickname' => '用户昵称',
                'employee_no' => '工号，可为空',
                'mobile' => '手机号码',
                'email' => '电子邮箱',
                'description' => '描述，可为空',
                'department_id' => '部门标识，关联admin_departments.id',
                'created_by' => '创建该用户的系统用户标识，关联admin_users.id；NULL表示无创建人',
                'is_founder' => '是否创始管理员：1是，0否',
                'password_hash' => '密码哈希，不保存明文密码',
                'status' => '状态：1启用，0禁用',
                'last_login_at' => '最近一次成功登录时间，NULL表示未登录',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'cloud_delivery_rules' => [
            'comment' => '云服务器交付规则',
            'columns' => [
                'id' => '云服务器交付规则主键标识',
                'product_id' => '产品标识，关联products.id',
                'name' => '云服务器交付规则名称',
                'description' => '描述，可为空',
                'connection_id' => '资源提供商连接标识，关联provider_connections.id',
                'line_ids' => '适用线路标识列表，JSON数组；空列表表示不额外限定线路',
                'priority' => '交付规则优先级，保留用于后续调度',
                'status' => '状态：1启用，0禁用',
                'revision' => '配置修订编号，用于检测并发修改',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'resource_ids' => '适用资源标识集合，JSON对象；空值表示不额外限定资源',
            ],
        ],
        'compute_disk_type_zones' => [
            'comment' => '存储类型可用区关联',
            'columns' => [
                'disk_type_id' => '存储类型标识，关联compute_disk_types.id',
                'zone_id' => '可用区标识，关联compute_zones.id',
            ],
        ],
        'compute_disk_types' => [
            'comment' => '云服务器存储类型',
            'columns' => [
                'id' => '云服务器存储类型主键标识',
                'code' => '云服务器存储类型稳定业务编码',
                'name' => '云服务器存储类型名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'zone_id' => '兼容旧版的主可用区标识；完整可用区关联见compute_disk_type_zones',
                'min_gib' => '单块磁盘最小容量，单位GiB',
                'max_gib' => '单块磁盘最大容量，单位GiB',
                'step_gib' => '磁盘容量调整步长，单位GiB',
                'max_count' => '该存储类型允许的磁盘数量上限',
                'prices' => '各计费周期每GiB售价，JSON对象；价格为整数最小货币单位',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'read_mbps' => '读取吞吐量，单位MB/s；NULL表示未说明',
                'write_mbps' => '写入吞吐量，单位MB/s；NULL表示未说明',
                'read_iops' => '随机读取IOPS；NULL表示未说明',
                'write_iops' => '随机写入IOPS；NULL表示未说明',
            ],
        ],
        'compute_flavor_groups' => [
            'comment' => '云服务器规格组',
            'columns' => [
                'id' => '云服务器规格组主键标识',
                'code' => '云服务器规格组稳定业务编码',
                'name' => '云服务器规格组名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'cpu_model' => 'CPU型号，空字符串表示未说明',
                'base_clock_mhz' => 'CPU基础主频，单位MHz；NULL表示未说明',
                'boost_clock_mhz' => 'CPU睿频，单位MHz；NULL表示未说明',
            ],
        ],
        'compute_flavor_zones' => [
            'comment' => '规格可用区关联',
            'columns' => [
                'flavor_id' => '规格标识，关联compute_flavors.id',
                'zone_id' => '可用区标识，关联compute_zones.id',
            ],
        ],
        'compute_flavors' => [
            'comment' => '云服务器规格',
            'columns' => [
                'id' => '云服务器规格主键标识',
                'code' => '云服务器规格稳定业务编码',
                'name' => '云服务器规格名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'zone_id' => '兼容旧版的主可用区标识；完整可用区关联见compute_flavor_zones',
                'group_id' => '规格组标识，关联compute_flavor_groups.id',
                'cpu' => '虚拟CPU核数',
                'memory_mib' => '内存容量，单位MiB',
                'architecture' => '处理器架构，如x86_64、aarch64',
                'prices' => '各计费周期规格售价，JSON对象；价格为整数最小货币单位',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_image_types' => [
            'comment' => '云服务器镜像类型',
            'columns' => [
                'id' => '云服务器镜像类型主键标识',
                'code' => '云服务器镜像类型稳定业务编码',
                'name' => '云服务器镜像类型名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_image_zones' => [
            'comment' => '镜像可用区关联',
            'columns' => [
                'image_id' => '镜像标识，关联compute_images.id',
                'zone_id' => '可用区标识，关联compute_zones.id',
            ],
        ],
        'compute_images' => [
            'comment' => '云服务器镜像',
            'columns' => [
                'id' => '云服务器镜像主键标识',
                'code' => '云服务器镜像稳定业务编码',
                'name' => '云服务器镜像名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'zone_id' => '兼容旧版的主可用区标识；完整可用区关联见compute_image_zones',
                'type_id' => '镜像类型标识，关联compute_image_types.id',
                'architecture' => '处理器架构，如x86_64、aarch64',
                'min_disk_gib' => '镜像要求的系统盘最小容量，单位GiB',
                'login_methods' => '镜像支持的登录方式，JSON数组，如password、ssh_key',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'min_cpu' => '镜像要求的最少虚拟CPU核数',
                'min_memory_mib' => '镜像要求的最小内存容量，单位MiB',
            ],
        ],
        'compute_line_zones' => [
            'comment' => '线路可用区关联',
            'columns' => [
                'line_id' => '线路标识，关联compute_lines.id',
                'zone_id' => '可用区标识，关联compute_zones.id',
            ],
        ],
        'compute_lines' => [
            'comment' => '云服务器网络线路',
            'columns' => [
                'id' => '云服务器网络线路主键标识',
                'code' => '云服务器网络线路稳定业务编码',
                'name' => '云服务器网络线路名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'zone_id' => '兼容旧版的主可用区标识；完整可用区关联见compute_line_zones',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_network_policies' => [
            'comment' => '云服务器网络配置策略',
            'columns' => [
                'id' => '云服务器网络配置策略主键标识',
                'code' => '云服务器网络配置策略稳定业务编码',
                'name' => '云服务器网络配置策略名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'bandwidth_min' => '最小带宽，单位Mbps',
                'bandwidth_max' => '最大带宽，单位Mbps',
                'bandwidth_step' => '带宽调整步长，单位Mbps',
                'ip_min' => '最少IP地址数量',
                'ip_max' => '最多IP地址数量',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_protection_lines' => [
            'comment' => '防护方案线路关联',
            'columns' => [
                'protection_id' => '防护方案标识，关联compute_protections.id',
                'line_id' => '线路标识，关联compute_lines.id',
            ],
        ],
        'compute_protections' => [
            'comment' => '云服务器防护方案',
            'columns' => [
                'id' => '云服务器防护方案主键标识',
                'code' => '云服务器防护方案稳定业务编码',
                'name' => '云服务器防护方案名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'line_id' => '兼容旧版的主线路标识；完整线路关联见compute_protection_lines',
                'prices' => '各计费周期防护售价，JSON对象；价格为整数最小货币单位',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_regions' => [
            'comment' => '云服务器地域',
            'columns' => [
                'id' => '云服务器地域主键标识',
                'code' => '云服务器地域稳定业务编码',
                'name' => '云服务器地域名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'compute_zones' => [
            'comment' => '云服务器可用区',
            'columns' => [
                'id' => '云服务器可用区主键标识',
                'code' => '云服务器可用区稳定业务编码',
                'name' => '云服务器可用区名称',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'sort' => '排序值，按升序排列',
                'region_id' => '所属地域标识，关联compute_regions.id',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'content_categories' => [
            'comment' => '新闻和帮助文档分类',
            'columns' => [
                'id' => '新闻和帮助文档分类主键标识',
                'kind' => '内容类型：help帮助文档，news新闻公告',
                'name' => '新闻和帮助文档分类名称',
                'sort_order' => '排序值，按升序排列',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'parent_id' => '上级分类标识，关联content_categories.id；NULL表示一级分类',
            ],
        ],
        'content_entries' => [
            'comment' => '新闻和帮助文档内容',
            'columns' => [
                'id' => '新闻和帮助文档内容主键标识',
                'kind' => '内容类型：help帮助文档，news新闻公告',
                'category_id' => '所属分类标识，关联content_categories.id',
                'title' => '内容标题',
                'summary' => '内容摘要',
                'body' => 'Markdown正文',
                'sort_order' => '排序值，按升序排列',
                'status' => '发布状态：draft草稿，published已发布',
                'published_at' => '发布时间，NULL表示未发布',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'finance_outbox' => [
            'comment' => '资金和订单事务待投递事件',
            'columns' => [
                'id' => '事件记录主键',
                'event_key' => '业务事件幂等键，防止重复写入',
                'event_type' => '业务事件类型，如order.paid',
                'aggregate_id' => '事件所属业务对象标识，按event_type解释',
                'payload' => '经过白名单控制的业务事件载荷，JSON对象',
                'status' => '投递状态，pending表示等待处理',
                'attempts' => '消息投递尝试次数',
                'created_at' => '记录创建时间',
                'processed_at' => '处理完成时间，NULL表示尚未处理',
            ],
        ],
        'installation' => [
            'comment' => '系统安装记录',
            'columns' => [
                'id' => '系统安装记录主键标识',
                'version' => '安装时的系统版本',
                'admin_path' => '安装时设置的后台访问路径',
                'agreement_version' => '已接受的安装协议版本',
                'agreement_accepted_at' => '安装协议接受时间',
                'installed_at' => '系统安装完成时间',
            ],
        ],
        'invoice_allocations' => [
            'comment' => '发票订单单位金额分配',
            'columns' => [
                'id' => '发票订单单位金额分配主键标识',
                'invoice_id' => '发票记录标识，关联invoice_records.id',
                'order_unit_id' => '订单单位标识，关联order_item_units.id',
                'amount_minor' => '该订单单位占用的开票金额，整数最小货币单位',
            ],
        ],
        'invoice_records' => [
            'comment' => '发票申请及开具记录',
            'columns' => [
                'id' => '发票申请及开具记录主键标识',
                'invoice_no' => '系统内唯一的发票申请编号',
                'member_id' => '所属会员标识，关联members.id',
                'order_id' => '订单标识，关联orders.id',
                'red_of_id' => '红冲关联的原发票记录标识，关联invoice_records.id；NULL表示普通开票',
                'amount_minor' => '金额，整数最小货币单位；CNY单位为分',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'invoice_type' => '发票类型标识',
                'title' => '发票抬头',
                'status' => '发票申请、审核、开具或红冲处理状态',
                'external_number' => '外部开具的发票号码，未开具时为空',
                'attachment_path' => '发票附件的受控存储路径',
                'reviewer_id' => '审核系统用户标识，关联admin_users.id，NULL表示未审核',
                'reviewed_at' => '审核时间，NULL表示未审核',
                'issued_at' => '实际开票时间，NULL表示未开具',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'member_groups' => [
            'comment' => '会员分组',
            'columns' => [
                'id' => '会员分组主键标识',
                'name' => '会员分组名称',
                'code' => '会员分组稳定业务编码',
                'sort_order' => '排序值，按升序排列',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'member_levels' => [
            'comment' => '会员等级',
            'columns' => [
                'id' => '会员等级主键标识',
                'name' => '会员等级名称',
                'code' => '会员等级稳定业务编码',
                'level_order' => '等级次序，按升序排列',
                'growth_threshold' => '达到该等级所需的成长值门槛',
                'description' => '描述，可为空',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'member_real_name_authentications' => [
            'comment' => '会员实名认证记录',
            'columns' => [
                'id' => '会员实名认证记录主键标识',
                'member_id' => '所属会员标识，关联members.id',
                'authentication_type' => '认证类型：personal个人认证，enterprise企业认证',
                'status' => '审核状态：pending待审核，approved已通过，rejected已拒绝',
                'subject_name' => '认证主体姓名',
                'id_type' => '个人身份证件类型标识',
                'id_number_ciphertext' => '个人身份证件号码密文',
                'id_number_hash' => '个人身份证件号码不可逆检索摘要',
                'id_number_masked' => '个人身份证件号码脱敏展示值',
                'company_name' => '企业名称',
                'registration_code_ciphertext' => '企业统一社会信用代码密文',
                'registration_code_hash' => '企业统一社会信用代码不可逆检索摘要',
                'registration_code_masked' => '企业统一社会信用代码脱敏展示值',
                'legal_representative' => '企业法定代表人姓名',
                'upgrade_from_id' => '升级企业认证所关联的个人认证记录标识；NULL表示非升级申请',
                'reviewed_by' => '审核系统用户标识，关联admin_users.id；NULL表示未审核',
                'rejection_reason' => '认证拒绝原因',
                'submitted_at' => '认证申请提交时间',
                'reviewed_at' => '审核时间，NULL表示未审核',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'members' => [
            'comment' => '会员账户',
            'columns' => [
                'id' => '会员账户主键标识',
                'nickname' => '用户昵称',
                'group_id' => '会员分组标识，关联member_groups.id；NULL表示未分组',
                'level_id' => '会员等级标识，关联member_levels.id；NULL表示未设置',
                'mobile' => '会员登录手机号码',
                'email' => '电子邮箱',
                'password_hash' => '密码哈希，不保存明文密码',
                'status' => '状态：1启用，0禁用',
                'description' => '描述，可为空',
                'last_login_at' => '最近一次成功登录时间，NULL表示未登录',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'migration_versions' => [
            'comment' => '核心数据库迁移版本记录',
            'columns' => [
                'version' => '已经执行的Phinx迁移版本号',
                'migration_name' => '迁移类名',
                'start_time' => '迁移开始时间',
                'end_time' => '迁移完成时间',
                'breakpoint' => 'Phinx迁移断点标记：1是，0否',
            ],
        ],
        'offer_versions' => [
            'comment' => '已发布销售方案版本',
            'columns' => [
                'id' => '已发布销售方案版本主键标识',
                'offer_id' => '销售方案标识，关联offers.id',
                'version' => '该销售方案的发布版本号',
                'type_version' => '产品配置契约版本',
                'snapshot' => '不可变销售配置及价格政策快照，JSON对象',
                'snapshot_hash' => '销售配置快照校验摘要',
                'published_by' => '发布系统用户标识，关联admin_users.id',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'offers' => [
            'comment' => '产品销售方案',
            'columns' => [
                'id' => '产品销售方案主键标识',
                'product_id' => '产品标识，关联products.id',
                'code' => '产品销售方案稳定业务编码',
                'name' => '产品销售方案名称',
                'status' => '状态：1启用，0禁用',
                'draft' => '待发布销售配置草稿，JSON对象',
                'revision' => '配置修订编号，用于检测并发修改',
                'published_version_id' => '当前发布版本标识，关联offer_versions.id；NULL表示未发布',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'description' => '描述，可为空',
            ],
        ],
        'order_item_units' => [
            'comment' => '订单项单台资源单位',
            'columns' => [
                'id' => '订单项单台资源单位主键标识',
                'order_item_id' => '订单项标识，关联order_items.id',
                'unit_index' => '同一订单项内的资源单位序号',
                'service_id' => '关联服务实例标识，关联service_instances.id；NULL表示尚未关联',
                'allocated_minor' => '该资源单位分摊的订单实付金额，整数最小货币单位',
                'refunded_minor' => '该资源单位已成功退款金额，整数最小货币单位',
                'reserved_refund_minor' => '该资源单位已预留的待处理退款金额，整数最小货币单位',
                'created_at' => '记录创建时间',
            ],
        ],
        'order_items' => [
            'comment' => '订单项',
            'columns' => [
                'id' => '订单项主键标识',
                'order_id' => '订单标识，关联orders.id',
                'line_no' => '订单内订单项序号',
                'product_type' => '下单时的产品类型标识快照',
                'product_type_version' => '下单时的产品配置契约版本快照',
                'sales_version' => '下单时的销售方案版本快照',
                'product_name' => '下单时的产品名称快照',
                'configuration_snapshot' => '下单时的产品配置快照，JSON对象',
                'quantity' => '订单项购买数量',
                'unit_price_minor' => '订单项单价，整数最小货币单位',
                'total_minor' => '订单项总金额，整数最小货币单位',
                'created_at' => '记录创建时间',
            ],
        ],
        'orders' => [
            'comment' => '会员订单',
            'columns' => [
                'id' => '会员订单主键标识',
                'order_no' => '系统内唯一的订单编号',
                'member_id' => '所属会员标识，关联members.id',
                'member_nickname_snapshot' => '下单时的会员昵称快照',
                'order_type' => '订单类型：purchase购买，renewal续费，upgrade升级，trial试用',
                'status' => '订单状态：pending_payment待支付，paid已支付，fulfilling交付中，completed完成，cancelled取消，failed失败，refunding退款中，refunded已退款',
                'payment_status' => '收款状态：unpaid未支付，partial部分支付，paid已支付，refunded已退款，partial_refund部分退款',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'subtotal_minor' => '优惠前商品合计，整数最小货币单位',
                'discount_minor' => '优惠减免金额，整数最小货币单位',
                'payable_minor' => '订单应付金额，整数最小货币单位',
                'paid_minor' => '已确认实收金额，整数最小货币单位',
                'refunded_minor' => '已成功退款金额，整数最小货币单位',
                'remark' => '订单备注',
                'paid_at' => '支付成功时间，NULL表示尚未支付',
                'completed_at' => '业务完成时间，NULL表示尚未完成',
                'cancelled_at' => '订单取消时间，NULL表示未取消',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'member_mobile_snapshot' => '下单时的会员手机号码快照',
            ],
        ],
        'payment_notifications' => [
            'comment' => '支付渠道通知去重记录',
            'columns' => [
                'id' => '支付渠道通知去重记录主键标识',
                'channel' => '支付渠道标识，如wechat、alipay、wallet',
                'notification_id' => '支付渠道通知唯一标识，与channel联合去重',
                'payment_id' => '支付交易标识，关联payment_transactions.id',
                'status' => '通知处理状态',
                'received_at' => '渠道通知接收时间',
                'processed_at' => '通知处理完成时间，NULL表示尚未处理',
            ],
        ],
        'payment_transactions' => [
            'comment' => '支付交易记录',
            'columns' => [
                'id' => '支付交易记录主键标识',
                'payment_no' => '系统内唯一的支付交易编号',
                'member_id' => '所属会员标识，关联members.id',
                'purpose' => '支付业务类型：recharge充值，order订单',
                'purpose_id' => '支付业务对象标识，按purpose关联充值记录或订单',
                'channel' => '支付渠道标识，如wechat、alipay、wallet',
                'channel_trade_no' => '外部支付渠道交易号，余额支付时为空',
                'amount_minor' => '金额，整数最小货币单位；CNY单位为分',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'status' => '支付交易状态，如pending待支付、paid已支付',
                'paid_at' => '支付成功时间，NULL表示尚未支付',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'products' => [
            'comment' => '内置产品定义',
            'columns' => [
                'id' => '内置产品定义主键标识',
                'name' => '内置产品定义名称',
                'description' => '描述，可为空',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'definition_key' => '产品定义稳定标识，如cloud_server；每种内置产品仅有一个定义',
            ],
        ],
        'provider_bindings' => [
            'comment' => '已发布销售方案供应绑定',
            'columns' => [
                'id' => '已发布销售方案供应绑定主键标识',
                'offer_version_id' => '销售方案发布版本标识，关联offer_versions.id',
                'connection_id' => '资源提供商连接标识，关联provider_connections.id',
                'mapping' => '交付参数及外部资源映射快照，JSON对象',
                'capability_snapshot' => '绑定时的供应能力声明快照，JSON对象',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'provider_catalog_entries' => [
            'comment' => '资源提供商同步目录',
            'columns' => [
                'id' => '资源提供商同步目录主键标识',
                'connection_id' => '资源提供商连接标识，关联provider_connections.id',
                'kind' => '外部资源目录类型，按提供商插件契约解释',
                'external_id' => '外部系统资源标识',
                'name' => '资源提供商同步目录名称',
                'metadata' => '经过脱敏的外部资源属性，JSON对象',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'provider_connections' => [
            'comment' => '资源提供商连接',
            'columns' => [
                'id' => '资源提供商连接主键标识',
                'name' => '资源提供商连接名称',
                'adapter_id' => '供应插件的稳定标识，如magic_cloud',
                'status' => '状态：1启用，0禁用',
                'capabilities' => '该连接支持的产品、动作和限制，JSON能力声明',
                'credentials_encrypted' => '外部连接配置及凭证的加密数据，不保存明文密钥',
                'tested_at' => '最近一次连接测试成功时间，NULL表示未测试',
                'revision' => '配置修订编号，用于检测并发修改',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'provider_resource_mappings' => [
            'comment' => '内部与外部资源映射',
            'columns' => [
                'id' => '内部与外部资源映射主键标识',
                'connection_id' => '资源提供商连接标识，关联provider_connections.id',
                'resource_kind' => '内部配置资源类型，如regions、zones、flavors、images',
                'local_id' => '内部资源标识，按resource_kind关联对应配置表',
                'external_id' => '该连接下的外部资源标识',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'scope_zone_id' => '映射适用可用区标识；0表示默认映射，指定可用区优先匹配',
            ],
        ],
        'recharges' => [
            'comment' => '会员余额充值记录',
            'columns' => [
                'id' => '会员余额充值记录主键标识',
                'recharge_no' => '系统内唯一的充值编号',
                'member_id' => '所属会员标识，关联members.id',
                'amount_minor' => '金额，整数最小货币单位；CNY单位为分',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'channel' => '支付渠道标识，如wechat、alipay、wallet',
                'status' => '充值状态，如paid已支付、credited已入账',
                'paid_at' => '支付成功时间，NULL表示尚未支付',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'refund_attempts' => [
            'comment' => '渠道退款执行尝试',
            'columns' => [
                'id' => '渠道退款执行尝试主键标识',
                'refund_id' => '退款申请标识，关联refund_requests.id',
                'channel' => '支付渠道标识，如wechat、alipay、wallet',
                'channel_refund_no' => '外部渠道退款单号，重试和查询沿用同一业务标识',
                'status' => '渠道退款执行状态，区分受理、成功、失败和结果未知',
                'error_code' => '渠道退款失败错误码，成功时为空',
                'created_at' => '记录创建时间',
                'confirmed_at' => '渠道退款结果确认时间，NULL表示未确认',
            ],
        ],
        'refund_requests' => [
            'comment' => '会员退款申请',
            'columns' => [
                'id' => '会员退款申请主键标识',
                'refund_no' => '系统内唯一的退款申请编号',
                'member_id' => '所属会员标识，关联members.id',
                'source_type' => '退款来源类型，区分订单资源单位与充值本金',
                'source_id' => '退款来源对象标识，按source_type解释',
                'payment_id' => '支付交易标识，关联payment_transactions.id',
                'amount_minor' => '金额，整数最小货币单位；CNY单位为分',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'destination' => '退款去向，区分原支付渠道与原余额来源',
                'status' => '退款申请及执行状态，审核通过不代表退款完成',
                'reason' => '申请或处理原因',
                'reviewer_id' => '审核系统用户标识，关联admin_users.id，NULL表示未审核',
                'reviewed_at' => '审核时间，NULL表示未审核',
                'completed_at' => '业务完成时间，NULL表示尚未完成',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'service_instances' => [
            'comment' => '已交付业务服务实例',
            'columns' => [
                'id' => '已交付业务服务实例主键标识',
                'instance_no' => '系统内唯一的服务实例编号',
                'product_type' => '服务所属产品定义标识，如cloud_server',
                'member_id' => '所属会员标识，关联members.id',
                'display_name' => '服务实例展示名称',
                'status' => '服务状态：provisioning开通中，active已开通，suspended暂停，expired到期，terminated终止，failed开通失败；不表示开关机状态',
                'activated_at' => '服务成功交付并生效的时间，NULL表示未生效',
                'expires_at' => '服务有效期截止时间，NULL表示无确定期限',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'origin_order_unit_id' => '首次交付订单单位标识，唯一关联order_item_units.id；历史无来源服务可为NULL',
            ],
        ],
        'system_setting_categories' => [
            'comment' => '系统设置分类',
            'columns' => [
                'id' => '系统设置分类主键标识',
                'code' => '系统设置分类稳定业务编码',
                'name' => '系统设置分类名称',
                'sort_order' => '排序值，按升序排列',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'system_settings' => [
            'comment' => '系统设置项',
            'columns' => [
                'id' => '系统设置项主键标识',
                'setting_key' => '稳定设置键，如site.title',
                'setting_value' => '设置值，按设置项契约解析字符串或JSON',
                'is_public' => '是否允许公开接口输出：1是，0否',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'category_id' => '设置分类标识，关联system_setting_categories.id；NULL表示内部设置',
            ],
        ],
        'system_update_runs' => [
            'comment' => '系统版本更新执行记录',
            'columns' => [
                'id' => '系统版本更新执行记录主键标识',
                'from_version' => '更新前的系统版本',
                'target_version' => '目标系统版本',
                'status' => '更新执行状态',
                'current_step' => '当前更新步骤',
                'error_message' => '经过脱敏的更新错误摘要',
                'package_checksum' => '更新包校验摘要',
                'operator_id' => '发起更新的系统用户标识，关联admin_users.id',
                'started_at' => '更新开始时间',
                'finished_at' => '更新结束时间，NULL表示尚未结束',
            ],
        ],
        'ticket_messages' => [
            'comment' => '工单对话消息',
            'columns' => [
                'id' => '工单对话消息主键标识',
                'ticket_id' => '工单标识，关联tickets.id',
                'author_type' => '作者类型：member会员，admin系统用户',
                'author_id' => '作者标识，按author_type关联会员或系统用户',
                'content' => '工单回复正文',
                'created_at' => '记录创建时间',
            ],
        ],
        'ticket_quick_replies' => [
            'comment' => '工单快捷回复',
            'columns' => [
                'id' => '工单快捷回复主键标识',
                'title' => '快捷回复标题',
                'content' => '快捷回复正文',
                'sort_order' => '排序值，按升序排列',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'ticket_types' => [
            'comment' => '工单类型',
            'columns' => [
                'id' => '工单类型主键标识',
                'name' => '工单类型名称',
                'code' => '工单类型稳定业务编码',
                'sort_order' => '排序值，按升序排列',
                'status' => '状态：1启用，0禁用',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'tickets' => [
            'comment' => '会员工单',
            'columns' => [
                'id' => '会员工单主键标识',
                'ticket_no' => '系统内唯一的工单编号',
                'member_id' => '所属会员标识，关联members.id',
                'type_id' => '工单类型标识，关联ticket_types.id',
                'subject' => '工单主题',
                'content' => '会员提交的工单问题正文',
                'priority' => '优先级：low低，normal普通，high高，urgent紧急',
                'status' => '工单状态：open待处理，processing处理中，waiting_member等待会员，closed已关闭',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
                'closed_at' => '工单关闭时间，NULL表示未关闭',
            ],
        ],
        'wallet_accounts' => [
            'comment' => '会员资金账户',
            'columns' => [
                'id' => '会员资金账户主键标识',
                'member_id' => '所属会员标识，关联members.id',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'fund_type' => '资金类型，recharge_cash表示充值本金；不同资金类型分账',
                'available_minor' => '可用余额，整数最小货币单位',
                'frozen_minor' => '已冻结余额，整数最小货币单位',
                'version' => '账户并发控制版本号',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
        'wallet_consumptions' => [
            'comment' => '订单余额消费来源分配',
            'columns' => [
                'id' => '订单余额消费来源分配主键标识',
                'order_id' => '订单标识，关联orders.id',
                'funding_lot_id' => '充值来源批次标识，关联wallet_funding_lots.id',
                'consumed_minor' => '该订单从来源批次扣减的金额，整数最小货币单位',
                'restored_minor' => '已退回该来源批次的金额，整数最小货币单位',
                'created_at' => '记录创建时间',
            ],
        ],
        'wallet_entries' => [
            'comment' => '会员不可变资金流水',
            'columns' => [
                'id' => '会员不可变资金流水主键标识',
                'entry_no' => '系统内唯一的资金流水编号',
                'account_id' => '资金账户标识，关联wallet_accounts.id',
                'member_id' => '所属会员标识，关联members.id',
                'entry_type' => '流水业务类型，如credit入账、spend消费',
                'source_type' => '流水来源类型，如recharge充值、order订单',
                'source_id' => '流水来源业务对象标识，按source_type解释',
                'amount_minor' => '资金变动金额，整数最小货币单位；正数入账，负数支出',
                'available_after_minor' => '该笔流水入账后的可用余额，整数最小货币单位',
                'created_at' => '记录创建时间',
            ],
        ],
        'wallet_funding_lots' => [
            'comment' => '会员充值本金来源批次',
            'columns' => [
                'id' => '会员充值本金来源批次主键标识',
                'account_id' => '资金账户标识，关联wallet_accounts.id',
                'recharge_id' => '充值记录标识，关联recharges.id',
                'credited_minor' => '来源批次确认入账金额，整数最小货币单位',
                'remaining_minor' => '来源批次剩余未消费金额，整数最小货币单位',
                'created_at' => '记录创建时间',
            ],
        ],
        'withdrawal_requests' => [
            'comment' => '余额例外退回及提现申请',
            'columns' => [
                'id' => '余额例外退回及提现申请主键标识',
                'withdrawal_no' => '系统内唯一的提现申请编号',
                'member_id' => '所属会员标识，关联members.id',
                'source_type' => '提现资金来源类型',
                'source_id' => '提现资金来源业务对象标识，按source_type解释',
                'amount_minor' => '金额，整数最小货币单位；CNY单位为分',
                'currency' => '币种代码，如CNY；金额字段使用该币种最小货币单位',
                'status' => '提现处理状态，区分待审、通过、拒绝和已打款',
                'payee_snapshot' => '收款对象及账户快照，JSON对象；按敏感信息规范控制访问',
                'reason' => '申请或处理原因',
                'reviewer_id' => '审核系统用户标识，关联admin_users.id，NULL表示未审核',
                'reviewed_at' => '审核时间，NULL表示未审核',
                'payout_reference' => '实际打款凭证或外部流水标识',
                'payout_at' => '实际打款时间，NULL表示尚未打款',
                'created_at' => '记录创建时间',
                'updated_at' => '记录最后更新时间',
            ],
        ],
    ];

    /** 只补充缺失中文释义的核心元数据，保留客户已有中文注释。 */
    public function up(): void
    {
        // Phinx 的前缀包装器返回自身类型；以实际 PDO 驱动判断数据库。
        $pdo = $this->getAdapter()->getConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // SQLite 没有原生表/字段 COMMENT，不能以其结构测试替代 MySQL 注释验收。
            return;
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('中文注释迁移仅支持项目使用的 MySQL 数据库。');
        }

        $settings = $pdo->query('SELECT @@SESSION.lock_wait_timeout AS wait_seconds, @@SESSION.sql_quote_show_create AS quote_names, @@SESSION.sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
        $noBackslashEscapes = in_array('NO_BACKSLASH_ESCAPES', explode(',', (string) $settings['sql_mode']), true);
        try {
            $pdo->exec('SET SESSION sql_quote_show_create = 1');
            $pdo->exec('SET SESSION lock_wait_timeout = 5');
            // 先解析所有定义，发现结构异常时，在执行任何 ALTER 之前中止。
            $plan = $this->plan($pdo, $noBackslashEscapes);
            foreach ($plan as $sql) {
                // 注释变更不允许自动降级到复制整表；元数据锁等待超时后可重试。
                $pdo->exec($sql);
            }
        } finally {
            $pdo->exec('SET SESSION lock_wait_timeout = ' . (int) $settings['wait_seconds']);
            $pdo->exec('SET SESSION sql_quote_show_create = ' . (int) $settings['quote_names']);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('中文注释不自动删除或覆盖，修正说明请新增前向迁移。');
    }

    /** @return list<string> 每张表一次元数据修改，字段定义来自数据库自身。 */
    private function plan(PDO $pdo, bool $noBackslashEscapes): array
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $tables = $pdo->prepare('SELECT TABLE_COMMENT, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $columns = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $plan = [];
        foreach (self::COMMENTS as $logicalName => $comments) {
            $name = $prefix . $logicalName;
            $quotedName = $this->identifier($name);
            $tables->execute([$name]);
            $table = $tables->fetch(PDO::FETCH_ASSOC);
            if ($table === false || $table['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('核心表不存在或不是 InnoDB，停止注释迁移：' . $logicalName);
            }
            $columns->execute([$name]);
            $existing = $columns->fetchAll(PDO::FETCH_KEY_PAIR);
            $missing = array_diff_key($comments['columns'], $existing);
            if ($missing !== []) {
                throw new RuntimeException('核心字段缺失，停止注释迁移：' . $logicalName . '.' . implode(',', array_keys($missing)));
            }
            $definition = $pdo->query('SHOW CREATE TABLE ' . $quotedName)->fetch(PDO::FETCH_NUM)[1];
            $definitions = $this->columnDefinitions($definition, $noBackslashEscapes);
            $changes = [];
            foreach ($comments['columns'] as $column => $comment) {
                if ($this->hasChinese((string) $existing[$column])) {
                    continue;
                }
                if (!isset($definitions[$column])) {
                    throw new RuntimeException('无法完整解析字段定义：' . $logicalName . '.' . $column);
                }
                $changes[] = 'MODIFY COLUMN ' . $this->identifier($column) . ' '
                    . $this->withComment($definitions[$column], $pdo->quote($comment), $noBackslashEscapes);
            }
            if (!$this->hasChinese((string) $table['TABLE_COMMENT'])) {
                $changes[] = 'COMMENT = ' . $pdo->quote($comments['comment']);
            }
            if ($changes !== []) {
                $plan[] = 'ALTER TABLE ' . $quotedName . ' ' . implode(', ', $changes) . ', ALGORITHM=INPLACE, LOCK=NONE';
            }
        }
        return $plan;
    }

    /** @return array<string, string> 保留原始类型、默认值、字符集及全部字段属性。 */
    private function columnDefinitions(string $create, bool $noBackslashEscapes): array
    {
        $start = strpos($create, "(\n");
        if ($start === false) {
            throw new RuntimeException('SHOW CREATE TABLE 未返回可识别的完整定义。');
        }
        $depth = 1;
        $partStart = $start + 1;
        $definitions = [];
        for ($index = $partStart, $length = strlen($create); $index < $length; ++$index) {
            $character = $create[$index];
            if (in_array($character, ["'", '"', '`'], true)) {
                $index = $this->quotedEnd($create, $index, $noBackslashEscapes);
                continue;
            }
            if ($character === '/' && ($create[$index + 1] ?? '') === '*') {
                $index = $this->blockCommentEnd($create, $index);
                continue;
            }
            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')') {
                --$depth;
            }
            if (($character === ',' && $depth === 1) || $depth === 0) {
                $part = trim(substr($create, $partStart, $index - $partStart));
                if (preg_match('/^`((?:``|[^`])+)`\s+(.+)$/sD', $part, $matches) === 1) {
                    $definitions[str_replace('``', '`', $matches[1])] = $matches[2];
                }
                $partStart = $index + 1;
            }
            if ($depth === 0) {
                return $definitions;
            }
        }
        throw new RuntimeException('SHOW CREATE TABLE 字段定义不完整。');
    }

    /** 仅替换引号外的 COMMENT 属性，默认值中的 COMMENT 等文本不受影响。 */
    private function withComment(string $definition, string $quotedComment, bool $noBackslashEscapes): string
    {
        for ($index = 0, $length = strlen($definition); $index < $length; ++$index) {
            if (in_array($definition[$index], ["'", '"', '`'], true)) {
                $index = $this->quotedEnd($definition, $index, $noBackslashEscapes);
                continue;
            }
            if ($definition[$index] === '/' && ($definition[$index + 1] ?? '') === '*') {
                $index = $this->blockCommentEnd($definition, $index);
                continue;
            }
            if (preg_match('/\GCOMMENT\s+(?=[\'\"])/i', $definition, $matches, 0, $index) === 1
                && ($index === 0 || !preg_match('/[a-zA-Z0-9_]/', $definition[$index - 1]))) {
                $end = $this->quotedEnd($definition, $index + strlen($matches[0]), $noBackslashEscapes);
                return substr($definition, 0, $index) . 'COMMENT ' . $quotedComment . substr($definition, $end + 1);
            }
        }
        return $definition . ' COMMENT ' . $quotedComment;
    }

    /** 找到完整 SQL 字符串或标识符的结束位置，不截断转义或重复引号。 */
    private function quotedEnd(string $sql, int $start, bool $noBackslashEscapes): int
    {
        $quote = $sql[$start];
        for ($index = $start + 1, $length = strlen($sql); $index < $length; ++$index) {
            if ($sql[$index] === '\\' && $quote !== '`' && !$noBackslashEscapes) {
                ++$index;
            } elseif ($sql[$index] === $quote) {
                if (($sql[$index + 1] ?? '') === $quote) {
                    ++$index;
                } else {
                    return $index;
                }
            }
        }
        throw new RuntimeException('字段定义中的 SQL 引号不完整，停止注释迁移。');
    }

    /** 跳过 MySQL SHOW CREATE 中的版本条件属性，保持原文。 */
    private function blockCommentEnd(string $sql, int $start): int
    {
        $end = strpos($sql, '*/', $start + 2);
        if ($end === false) {
            throw new RuntimeException('字段定义中的 SQL 条件属性不完整，停止注释迁移。');
        }
        return $end + 1;
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function hasChinese(string $comment): bool
    {
        return preg_match('/\p{Han}/u', $comment) === 1;
    }
}
