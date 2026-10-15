<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 扩展资源复用、硬件参数和按可用区的供应映射，保留旧快照及兼容字段。 */
final class ExpandCloudResourceConfiguration extends AbstractMigration
{
    private const array RELATIONS = [
        ['compute_line_zones', 'compute_lines', 'line_id', 'compute_zones', 'zone_id'],
        ['compute_flavor_zones', 'compute_flavors', 'flavor_id', 'compute_zones', 'zone_id'],
        ['compute_image_zones', 'compute_images', 'image_id', 'compute_zones', 'zone_id'],
        ['compute_disk_type_zones', 'compute_disk_types', 'disk_type_id', 'compute_zones', 'zone_id'],
        ['compute_protection_lines', 'compute_protections', 'protection_id', 'compute_lines', 'line_id'],
    ];

    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        foreach (self::RELATIONS as [, $child, , $parent, $field]) {
            $bad = $this->fetchRow("SELECT c.id FROM `{$prefix}{$child}` c LEFT JOIN `{$prefix}{$parent}` p ON p.id=c.$field WHERE p.id IS NULL LIMIT 1");
            if ($bad !== false) throw new RuntimeException('配置资源关联无效：' . $child . '#' . $bad['id']);
        }
        $this->column('compute_flavor_groups', 'cpu_model', 'string', ['limit' => 128, 'default' => '']);
        foreach (['base_clock_mhz', 'boost_clock_mhz'] as $field) $this->column('compute_flavor_groups', $field, 'integer', ['signed' => false, 'null' => true]);
        $this->column('compute_images', 'min_cpu', 'integer', ['signed' => false, 'default' => 1]);
        $this->column('compute_images', 'min_memory_mib', 'integer', ['signed' => false, 'default' => 128]);
        foreach (['read_mbps', 'write_mbps', 'read_iops', 'write_iops'] as $field) $this->column('compute_disk_types', $field, 'integer', ['signed' => false, 'null' => true]);
        $this->column('cloud_delivery_rules', 'resource_ids', 'text', ['null' => true]);

        foreach (self::RELATIONS as [$name, $child, $childKey, $parent, $parentKey]) {
            if (!$this->hasTable($name)) {
                $table = $this->table($name, ['id' => false, 'primary_key' => [$childKey, $parentKey], 'engine' => 'InnoDB'])
                    ->addColumn($childKey, 'integer', ['signed' => false, 'null' => false])->addColumn($parentKey, 'integer', ['signed' => false, 'null' => false])
                    ->addIndex($parentKey);
                if ($this->getAdapter()->getAdapterType() === 'mysql') {
                    $table->addForeignKey($childKey, $child, 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
                        ->addForeignKey($parentKey, $parent, 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT']);
                }
                $table->create();
            }
            // 可重试的回填只补充旧主关联，不覆盖迁移后设置的其他关联。
            $this->execute("INSERT INTO `{$prefix}{$name}` ($childKey,$parentKey) SELECT c.id,c.$parentKey FROM `{$prefix}{$child}` c WHERE NOT EXISTS (SELECT 1 FROM `{$prefix}{$name}` r WHERE r.$childKey=c.id AND r.$parentKey=c.$parentKey)");
        }
        $this->column('provider_resource_mappings', 'scope_zone_id', 'integer', ['signed' => false, 'default' => 0]);
        $mappings = $this->table('provider_resource_mappings');
        if (!$mappings->hasIndexByName('uk_provider_mapping_scope')) {
            $mappings->addIndex(['connection_id', 'resource_kind', 'local_id', 'scope_zone_id'], ['unique' => true, 'name' => 'uk_provider_mapping_scope'])->update();
        }
        if ($mappings->hasIndex(['connection_id', 'resource_kind', 'local_id'])) {
            $mappings->removeIndex(['connection_id', 'resource_kind', 'local_id'])->update();
        }
    }

    /** 扩展字段具备重复执行保护。
     * @param string $table 逻辑表名
     * @param string $field 字段名
     * @param string $type 字段类型
     * @param array<string, mixed> $options 类型选项
     * @return void
     */
    private function column(string $table, string $field, string $type, array $options): void
    {
        $target = $this->table($table);
        if (!$target->hasColumn($field)) $target->addColumn($field, $type, $options)->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('资源复用已形成业务关联，使用备份或前向修复恢复。');
    }
}
