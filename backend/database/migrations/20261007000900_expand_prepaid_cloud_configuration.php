<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use Phinx\Db\Adapter\MysqlAdapter;

/** 仅前向扩展；旧网络数据保持原意，不自动猜测上下行关系。 */
final class ExpandPrepaidCloudConfiguration extends AbstractMigration
{
    public function up(): void
    {
        foreach ([
            ['cloud_delivery_rules', 'parameter_mapping', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'comment' => '标准交付字段到供应商参数的白名单路径、单位与枚举转换规则；不含凭据或可执行代码']],
            ['compute_lines', 'network_configuration', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'comment' => '线路固定网络配置：上下行Mbps范围及月费整数分、赠送和IP规则；空值表示未配置v2网络']],
            ['compute_images', 'default_username', 'string', ['limit' => 64, 'default' => 'root', 'comment' => '镜像默认实例登录用户名；不含密码']],
            ['compute_images', 'default_port', 'integer', ['default' => 22, 'comment' => '镜像默认远程登录端口，1至65535']],
            ['compute_image_types', 'icon_url', 'string', ['limit' => 500, 'default' => '', 'comment' => '镜像分类图标公开HTTPS地址或站内路径']],
            ['compute_protections', 'capacity_gbps', 'integer', ['default' => 0, 'comment' => '离散防护容量，单位Gbps；旧记录0需人工确认']],
        ] as [$name, $field, $type, $options]) {
            $table = $this->table($name);
            if (!$table->hasColumn($field)) $table->addColumn($field, $type, $options)->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('产品销售快照相关配置只能使用前向迁移修复');
    }
}
