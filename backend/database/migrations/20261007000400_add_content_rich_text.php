<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\IrreversibleMigrationException;

/** 原有正文保持 Markdown；新公告显式声明 HTML。 */
final class AddContentRichText extends AbstractMigration
{
    public function up(): void
    {
        $entries = $this->table('content_entries');
        $entries->changeColumn('body', 'text', [
            'limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => '正文内容，格式由body_format声明，最大100000字符',
        ])->update();
        if (!$entries->hasColumn('body_format')) {
            $entries->addColumn('body_format', 'string', [
                'null' => false, 'limit' => 16, 'default' => 'markdown', 'comment' => '正文格式：markdown技术文档或历史公告，html富文本公告',
            ])->update();
        }
        if (!$this->hasTable('content_images')) {
            $this->table('content_images', ['id' => false, 'primary_key' => ['id'], 'comment' => '内容编辑器公开图片的存储引用'])
                ->addColumn('id', 'string', ['null' => false, 'limit' => 32, 'comment' => '随机公开图片标识，不包含文件路径或凭证'])
                ->addColumn('channel_id', 'integer', ['null' => false, 'signed' => false, 'comment' => '上传时使用的存储通道标识'])
                ->addColumn('channel_revision', 'integer', ['null' => false, 'comment' => '上传时封存的存储通道配置修订'])
                ->addColumn('object_key', 'string', ['null' => false, 'limit' => 512, 'comment' => '宿主生成的存储对象键'])
                ->addColumn('mime_type', 'string', ['null' => false, 'limit' => 64, 'comment' => '服务端确认的图片MIME类型'])
                ->addColumn('byte_size', 'integer', ['null' => false, 'signed' => false, 'comment' => '图片大小，单位字节，最大5MiB'])
                ->addColumn('created_by', 'integer', ['null' => false, 'signed' => false, 'comment' => '上传管理员标识'])
                ->addColumn('status', 'string', ['null' => false, 'limit' => 16, 'default' => 'pending', 'comment' => '存储状态：pending预留、stored完成、failed失败、unknown结果未确认'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '图片引用创建时间'])
                ->create();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('正文格式及图片引用必须保留，使用备份恢复或前向修复。');
    }
}
