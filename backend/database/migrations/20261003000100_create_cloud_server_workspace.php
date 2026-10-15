<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 固定云服务器身份，保留旧方案与快照，并补充网络及交付配置。 */
final class CreateCloudServerWorkspace extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $products = '`' . $prefix . 'products`';
        $offers = '`' . $prefix . 'offers`';
        $versions = '`' . $prefix . 'offer_versions`';
        $invalid = $this->fetchRow("SELECT o.id FROM $offers o LEFT JOIN $products p ON p.id=o.product_id WHERE p.id IS NULL LIMIT 1");
        if ($invalid !== false) throw new RuntimeException('销售方案关联的产品不存在：' . $invalid['id']);
        $invalid = $this->fetchRow("SELECT v.id FROM $versions v LEFT JOIN $offers o ON o.id=v.offer_id WHERE o.id IS NULL LIMIT 1");
        if ($invalid !== false) throw new RuntimeException('发布版本关联的销售方案不存在：' . $invalid['id']);
        $invalid = $this->fetchRow("SELECT o.id FROM $offers o LEFT JOIN $versions v ON v.id=o.published_version_id WHERE o.published_version_id IS NOT NULL AND (v.id IS NULL OR v.offer_id<>o.id) LIMIT 1");
        if ($invalid !== false) throw new RuntimeException('销售方案的发布版本归属无效：' . $invalid['id']);
        $productTable = $this->table('products');
        if ($productTable->hasColumn('product_type')) {
            $invalid = $this->fetchRow("SELECT id FROM $products WHERE product_type <> 'cloud_server' LIMIT 1");
            if ($invalid !== false) throw new RuntimeException('发现其他产品定义，请先核查产品：' . $invalid['id']);
        }
        if (!$productTable->hasColumn('definition_key')) {
            $productTable->addColumn('definition_key', 'string', ['limit' => 64, 'null' => true])->update();
        }
        if (!$productTable->hasIndexByName('uk_product_definition_key')) {
            $productTable->addIndex(['definition_key'], ['unique' => true, 'name' => 'uk_product_definition_key'])->update();
        }
        $offerTable = $this->table('offers');
        // NULL 是回填标记；DDL 后重试不会覆盖已迁移的描述。
        if (!$offerTable->hasColumn('description')) {
            $offerTable->addColumn('description', 'string', ['limit' => 1000, 'null' => true])->update();
        }
        $fixed = $this->fetchRow("SELECT id FROM $products WHERE definition_key='cloud_server'");
        if ($fixed === false) {
            $now = date('Y-m-d H:i:s');
            $data = ['name' => '云服务器', 'definition_key' => 'cloud_server', 'description' => '', 'created_at' => $now, 'updated_at' => $now];
            if ($productTable->hasColumn('product_type')) $data['product_type'] = 'cloud_server';
            $productTable->insert($data)->saveData();
            $fixed = $this->fetchRow("SELECT id FROM $products WHERE definition_key='cloud_server'");
        }
        $this->execute("UPDATE $offers SET description=(SELECT p.description FROM $products p WHERE p.id=$offers.product_id) WHERE description IS NULL");
        $this->execute("UPDATE $offers SET product_id=" . (int) $fixed['id']);
        $offerTable->changeColumn('description', 'string', ['limit' => 1000, 'default' => '', 'null' => false])->update();
        // 处理器由固定定义注册；产品表不再重复保存产品类型。
        if ($productTable->hasColumn('product_type')) $productTable->removeColumn('product_type')->update();

        $options = ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        if (!$this->hasTable('compute_network_policies')) {
            $this->table('compute_network_policies', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('code', 'string', ['limit' => 64])->addColumn('name', 'string', ['limit' => 128])
                ->addColumn('description', 'string', ['limit' => 1000, 'default' => ''])
                ->addColumn('status', 'integer', ['default' => 1])->addColumn('sort', 'integer', ['default' => 0])
                ->addColumn('bandwidth_min', 'integer', ['signed' => false])->addColumn('bandwidth_max', 'integer', ['signed' => false])
                ->addColumn('bandwidth_step', 'integer', ['signed' => false])
                ->addColumn('ip_min', 'integer', ['signed' => false])->addColumn('ip_max', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex('code', ['unique' => true, 'name' => 'uk_compute_network_policy_code'])->create();
        }
        if (!$this->hasTable('cloud_delivery_rules')) {
            $this->table('cloud_delivery_rules', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('product_id', 'integer', ['signed' => false])
                ->addColumn('name', 'string', ['limit' => 128])->addColumn('description', 'string', ['limit' => 1000, 'default' => ''])
                ->addColumn('connection_id', 'integer', ['signed' => false])->addColumn('line_ids', 'text')
                ->addColumn('priority', 'integer', ['default' => 0])->addColumn('status', 'integer', ['default' => 1])
                ->addColumn('revision', 'integer', ['default' => 1])
                ->addColumn('created_at', 'datetime')->addColumn('updated_at', 'datetime')
                ->addIndex('product_id')->addIndex('connection_id')->create();
        }
        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $rules = $this->table('cloud_delivery_rules');
            foreach (['product_id' => 'products', 'connection_id' => 'provider_connections'] as $field => $parent) {
                if (!$rules->hasForeignKey($field)) $rules->addForeignKey($field, $parent, 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('固定产品及方案归属不能回滚删除，请使用前向修复。');
    }
}
