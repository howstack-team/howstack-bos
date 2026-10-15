<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 云服务器资源、销售版本与供应连接的前向迁移。 */
final class CreateCloudCommerce extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        $resources = [
            'compute_regions' => [],
            'compute_zones' => ['region_id' => 'integer'],
            'compute_lines' => ['zone_id' => 'integer'],
            'compute_flavor_groups' => [],
            'compute_flavors' => ['zone_id' => 'integer', 'group_id' => 'integer', 'cpu' => 'integer', 'memory_mib' => 'integer', 'architecture' => 'string', 'prices' => 'text'],
            'compute_image_types' => [],
            'compute_images' => ['zone_id' => 'integer', 'type_id' => 'integer', 'architecture' => 'string', 'min_disk_gib' => 'integer', 'login_methods' => 'text'],
            'compute_disk_types' => ['zone_id' => 'integer', 'min_gib' => 'integer', 'max_gib' => 'integer', 'step_gib' => 'integer', 'max_count' => 'integer', 'prices' => 'text'],
            'compute_protections' => ['line_id' => 'integer', 'prices' => 'text'],
        ];
        foreach ($resources as $name => $fields) {
            if ($this->hasTable($name)) continue;
            $table = $this->table($name, $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('code', 'string', ['limit' => 64])
                ->addColumn('name', 'string', ['limit' => 128])
                ->addColumn('description', 'string', ['limit' => 1000, 'default' => ''])
                ->addColumn('status', 'integer', ['default' => 1])
                ->addColumn('sort', 'integer', ['default' => 0]);
            foreach ($fields as $field => $type) {
                $table->addColumn($field, $type, $type === 'integer' ? ['signed' => false] : ($type === 'string' ? ['limit' => 32] : []));
            }
            $table->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex(['code'], ['unique' => true, 'name' => 'uk_' . $name . '_code'])->create();
        }
        $tables = [
            'provider_connections' => [
                'name' => ['string', ['limit' => 128]], 'adapter_id' => ['string', ['limit' => 64]],
                'status' => ['integer', ['default' => 0]], 'capabilities' => ['text', []],
                'credentials_encrypted' => ['text', ['null' => true]], 'tested_at' => ['datetime', ['null' => true]],
                'revision' => ['integer', ['default' => 1]],
            ],
            'provider_catalog_entries' => [
                'connection_id' => ['integer', ['signed' => false]], 'kind' => ['string', ['limit' => 24]],
                'external_id' => ['string', ['limit' => 128]], 'name' => ['string', ['limit' => 128]],
                'metadata' => ['text', []],
            ],
            'provider_resource_mappings' => [
                'connection_id' => ['integer', ['signed' => false]], 'resource_kind' => ['string', ['limit' => 24]],
                'local_id' => ['integer', ['signed' => false]], 'external_id' => ['string', ['limit' => 128]],
            ],
            'products' => [
                'name' => ['string', ['limit' => 128]], 'product_type' => ['string', ['limit' => 64]],
                'description' => ['string', ['limit' => 1000, 'default' => '']],
            ],
            'offers' => [
                'product_id' => ['integer', ['signed' => false]], 'code' => ['string', ['limit' => 64]],
                'name' => ['string', ['limit' => 128]], 'status' => ['integer', ['default' => 0]],
                'draft' => ['text', []], 'revision' => ['integer', ['default' => 1]],
                'published_version_id' => ['integer', ['signed' => false, 'null' => true]],
            ],
            'offer_versions' => [
                'offer_id' => ['integer', ['signed' => false]], 'version' => ['integer', ['signed' => false]],
                'type_version' => ['string', ['limit' => 32]], 'snapshot' => ['text', []],
                'snapshot_hash' => ['string', ['limit' => 64]], 'published_by' => ['integer', ['signed' => false]],
            ],
            'provider_bindings' => [
                'offer_version_id' => ['integer', ['signed' => false]], 'connection_id' => ['integer', ['signed' => false]],
                'mapping' => ['text', []], 'capability_snapshot' => ['text', []],
            ],

        ];
        $indexes = [
            'provider_catalog_entries' => [['connection_id', 'kind', 'external_id']],
            'provider_resource_mappings' => [['connection_id', 'resource_kind', 'local_id']],
            'offers' => [['code']], 'offer_versions' => [['offer_id', 'version']], 'provider_bindings' => [['offer_version_id']],
        ];
        foreach ($tables as $name => $fields) {
            if ($this->hasTable($name)) continue;
            $table = $this->table($name, $options)->addColumn('id', 'integer', ['identity' => true, 'signed' => false]);
            foreach ($fields as $field => [$type, $settings]) $table->addColumn($field, $type, $settings);
            foreach ($indexes[$name] ?? [] as $index) $table->addIndex($index, ['unique' => true]);
            $table->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')->create();
        }
        foreach ([['provider_catalog_entries', 'provider_connections', 'connection_id'],
            ['provider_resource_mappings', 'provider_connections', 'connection_id'], ['offers', 'products', 'product_id'], ['offer_versions', 'offers', 'offer_id'],
            ['provider_bindings', 'offer_versions', 'offer_version_id'], ['provider_bindings', 'provider_connections', 'connection_id'], ['compute_zones', 'compute_regions', 'region_id'],
            ['compute_lines', 'compute_zones', 'zone_id'], ['compute_flavors', 'compute_zones', 'zone_id'],
            ['compute_flavors', 'compute_flavor_groups', 'group_id'], ['compute_images', 'compute_zones', 'zone_id'],
            ['compute_images', 'compute_image_types', 'type_id'], ['compute_disk_types', 'compute_zones', 'zone_id'],
            ['compute_protections', 'compute_lines', 'line_id']] as [$name, $parent, $field]) {
            if ($this->getAdapter()->getOption('adapter') === 'sqlite') continue;
            $table = $this->table($name);
            if (!$table->hasForeignKey($field)) $table->addForeignKey($field, $parent, 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('销售版本和供应映射不能回滚删除，请使用前向修复迁移。');
    }
}
