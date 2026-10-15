<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 新增页面覆盖值；默认SEO原值不变，按原基础设置权限补齐新页面权限。 */
final class CreateWebsitePageSeo extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('website_page_seo')) {
            $this->table('website_page_seo', ['id' => false, 'primary_key' => ['scope', 'page_id'], 'comment' => '官网逐页SEO覆盖配置，独立于主题包保存'])
                ->addColumn('scope', 'string', ['limit' => 70, 'null' => false, 'comment' => '页面归属：core为核心首页，theme:主题标识为自定义页面'])
                ->addColumn('page_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '宿主或主题声明的稳定页面标识，与scope联合唯一'])
                ->addColumn('title', 'string', ['limit' => 120, 'default' => '', 'null' => false, 'comment' => 'SEO标题，空白继承页面默认标题或官网默认首页标题'])
                ->addColumn('description', 'string', ['limit' => 500, 'default' => '', 'null' => false, 'comment' => 'SEO描述，空白逐级继承页面声明和官网默认描述'])
                ->addColumn('keywords', 'string', ['limit' => 255, 'default' => '', 'null' => false, 'comment' => 'SEO关键词，空白继承官网默认关键词'])
                ->addColumn('indexable', 'boolean', ['default' => null, 'null' => true, 'comment' => '索引策略：NULL继承页面默认，1允许，0禁止；预览始终禁止'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '页面配置首次保存时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '页面配置最后保存时间'])->create();
        }
        $pdo = $this->getAdapter()->getConnection();
        $table = $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'admin_role_permissions');
        foreach (['view', 'update'] as $action) {
            $code = 'admin.website.seo.' . $action;
            $statement = $pdo->prepare("INSERT INTO {$table} (role_id, permission_code) SELECT source.role_id, ? FROM {$table} source WHERE source.permission_code = ? AND NOT EXISTS (SELECT 1 FROM {$table} existing WHERE existing.role_id = source.role_id AND existing.permission_code = ?)");
            $statement->execute([$code, 'admin.website.base.' . $action, $code]);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('逐页SEO配置需保留，请备份后使用前向迁移修复。');
    }
}
