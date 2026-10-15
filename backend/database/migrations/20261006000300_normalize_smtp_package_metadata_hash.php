<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 修正旧首次安装摘要包含 Finder 元数据的问题，不核准任何运行文件变更。 */
final class NormalizeSmtpPackageMetadataHash extends AbstractMigration
{
    /** @return void 只处理摘要与当前完整包完全匹配的内置 SMTP */
    public function up(): void
    {
        if (!$this->hasTable('business_plugin_installations')) {
            return;
        }
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $table = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_installations');
        $row = $this->fetchRow("SELECT * FROM {$table} WHERE plugin_id = 'smtp' AND category = 'mail'");
        $folder = dirname(__DIR__, 2) . '/plugins/mail/smtp';
        if ($row === false || !is_file($folder . '/plugin.json')) {
            return;
        }
        $manifest = json_decode(file_get_contents($folder . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['id'] ?? '') !== 'smtp' || ($manifest['category'] ?? '') !== 'mail'
            || ($manifest['version'] ?? '') !== $row['version']) {
            return;
        }
        [$original, $runtime] = $this->hashes($folder);
        $this->normalize($row, $original, $runtime);
    }

    /**
     * 冻结摘要算法，不引用可变的应用实现；唯一排除项为非运行文件 .DS_Store。
     * @param string $folder 固定插件目录
     * @return array{string, string} 含元数据摘要、运行文件摘要
     */
    private function hashes(string $folder): array
    {
        $all = [];
        $runtime = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('内置 SMTP 包不能包含符号链接');
            }
            if (!$file->isFile()) {
                continue;
            }
            $path = substr($file->getPathname(), strlen($folder) + 1);
            $all[$path] = hash_file('sha256', $file->getPathname());
            if ($file->getFilename() !== '.DS_Store') {
                $runtime[$path] = $all[$path];
            }
        }
        ksort($all);
        ksort($runtime);
        return [
            hash('sha256', json_encode($all, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($runtime, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * 原摘要须完全相同；真正代码变更仍要求维护人员审核升级。
     * @param array<string, mixed> $row 安装记录快照
     * @param string $original 包含元数据的当前完整摘要
     * @param string $runtime 当前运行文件摘要
     * @return void 保留版本与启停状态，仅规范化摘要并记录审计
     */
    private function normalize(array $row, string $original, string $runtime): void
    {
        if ($original === $runtime || $row['package_hash'] !== $original) {
            return;
        }
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $table = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_installations');
        $events = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_events');
        $pdo = $this->getAdapter()->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $now = date('Y-m-d H:i:s');
            $update = $pdo->prepare("UPDATE {$table} SET package_hash = ?, revision = revision + 1, updated_at = ?
                WHERE id = ? AND plugin_id = 'smtp' AND category = 'mail' AND package_hash = ? AND revision = ?");
            $update->execute([$runtime, $now, $row['id'], $original, $row['revision']]);
            if ($update->rowCount() === 1) {
                $event = $pdo->prepare("INSERT INTO {$events}
                    (plugin_id, action, from_state, to_state, actor_id, package_hash, created_at)
                    VALUES ('smtp', 'metadata_normalize', ?, ?, NULL, ?, ?)");
                $event->execute([$row['state'], $row['state'], $runtime, $now]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return never @throws IrreversibleMigrationException 摘要与审计仅允许前向修正 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请使用前向修复保留安装摘要及审计记录');
    }
}
