<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateOrderRequests extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('order_requests')) return;
        $this->table('order_requests', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'id' => false, 'primary_key' => ['id'], 'comment' => '后台创建订单幂等结果映射'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => '订单请求记录标识'])
            ->addColumn('request_key', 'string', ['limit' => 100, 'comment' => '管理员标识与唯一创建请求键'])
            ->addColumn('request_hash', 'string', ['limit' => 64, 'comment' => '白名单请求内容SHA256摘要，不存放明文敏感资料'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'null' => true, 'comment' => '已创建订单标识；NULL为当前事务尚未完成'])
            ->addColumn('created_at', 'datetime', ['comment' => '创建请求时间'])
            ->addIndex(['request_key'], ['unique' => true])->addIndex(['order_id'])->create();
    }
    public function down(): void
    {
        throw new IrreversibleMigrationException('订单幂等结果须保留，使用前向修复。');
    }
}
