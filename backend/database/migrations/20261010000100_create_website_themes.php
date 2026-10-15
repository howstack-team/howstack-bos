<?php

declare(strict_types=1);

use app\modules\website\infrastructure\FileThemeRegistry;
use app\modules\website\validation\WebsiteSettings;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 官网配置与图片引用；不重复品牌数据，不变更其他业务表。 */
final class CreateWebsiteThemes extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('website_settings')) {
            $this->table('website_settings', ['id' => false, 'primary_key' => ['id'], 'comment' => '官网单例配置及启用的不可变主题版本'])
                ->addColumn('id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '单例主键，固定为1'])
                ->addColumn('active_theme_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '启用主题的稳定标识'])
                ->addColumn('active_theme_version', 'string', ['limit' => 32, 'null' => false, 'comment' => '已发布主题的语义版本'])
                ->addColumn('active_theme_digest', 'string', ['limit' => 64, 'null' => false, 'comment' => '主题包内容的SHA256摘要，用于固定模板与静态资源'])
                ->addColumn('settings', 'json', ['null' => false, 'comment' => '官网Logo、网站图标、导航及页脚配置，不含系统品牌或密钥'])
                ->addColumn('revision', 'biginteger', ['signed' => false, 'default' => 1, 'null' => false, 'comment' => '配置修订号，每次保存递增，用于并发控制'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '配置创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '配置最后修改时间'])->create();
        }
        if (!$this->hasTable('website_theme_settings')) {
            $this->table('website_theme_settings', ['id' => false, 'primary_key' => ['id'], 'comment' => '每个官网主题的全局及自定义页面设置'])
                ->addColumn('id', $this->getAdapter()->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'integer' : 'biginteger', ['identity' => true, 'signed' => false, 'null' => false, 'comment' => '主题配置自增主键'])
                ->addColumn('theme_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '主题稳定标识，每个主题唯一一条配置'])
                ->addColumn('schema_version', 'integer', ['default' => 1, 'null' => false, 'comment' => '设置契约版本，当前为1'])
                ->addColumn('settings', 'json', ['null' => false, 'comment' => 'global全局配置与pages按页面标识隔离的配置'])
                ->addColumn('revision', 'biginteger', ['signed' => false, 'default' => 1, 'null' => false, 'comment' => '主题配置修订号，每次保存递增'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '主题配置创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '主题配置最后修改时间'])
                ->addIndex(['theme_id'], ['unique' => true, 'name' => 'uk_website_theme'])->create();
        }
        if (!$this->hasTable('website_images')) {
            $this->table('website_images', ['id' => false, 'primary_key' => ['id'], 'comment' => '官网公开图片的存储引用及上传结果'])
                ->addColumn('id', 'string', ['limit' => 32, 'null' => false, 'comment' => '随机公开图片标识，不包含文件路径或凭证'])
                ->addColumn('channel_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '上传时使用的存储通道标识'])
                ->addColumn('channel_revision', 'integer', ['null' => false, 'comment' => '上传时封存的存储通道配置修订'])
                ->addColumn('object_key', 'string', ['limit' => 512, 'null' => false, 'comment' => '宿主生成的website/images前缀存储对象键'])
                ->addColumn('mime_type', 'string', ['limit' => 64, 'null' => false, 'comment' => '服务端确认的图片MIME类型'])
                ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => false, 'comment' => '图片大小，单位字节，最大5MiB'])
                ->addColumn('created_by', 'integer', ['signed' => false, 'null' => false, 'comment' => '上传图片的管理员标识'])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending', 'null' => false, 'comment' => '上传状态：pending预留、stored完成、failed失败、unknown未确认'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '图片引用创建时间'])
                ->addIndex(['status', 'created_at'], ['name' => 'idx_website_images_state'])->create();
        }
        $root = dirname(__DIR__, 2);
        $version = require $root . '/config/version.php';
        $package = (new FileThemeRegistry($root . '/themes', $root . '/runtime/website-themes', $root . '/public/theme-assets', $version['current']))->read('default');
        $now = date('Y-m-d H:i:s');
        // migration 支持失败重试，但不得覆盖用户已经保存的配置。
        $settings = $this->table('website_settings');
        if ($settings->getAdapter()->fetchRow('SELECT id FROM ' . $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'website_settings') . ' WHERE id = 1') === false) {
            $settings->insert(['id' => 1, 'active_theme_id' => 'default', 'active_theme_version' => $package['manifest']['version'], 'active_theme_digest' => $package['digest'],
                'settings' => json_encode(WebsiteSettings::defaults(), JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
        }
        $themes = $this->table('website_theme_settings');
        if ($themes->getAdapter()->fetchRow("SELECT theme_id FROM " . $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . 'website_theme_settings') . " WHERE theme_id = 'default'") === false) {
            $themes->insert(['theme_id' => 'default', 'schema_version' => 1, 'settings' => json_encode($package['defaults'], JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('官网配置及图片引用必须保留，请使用备份恢复或前向修复。');
    }
}
