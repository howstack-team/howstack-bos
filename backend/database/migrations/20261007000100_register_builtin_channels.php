<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 首次登记随发行包交付的官方基础通道；不创建业务配置，不覆盖管理员已有状态。 */
final class RegisterBuiltinChannels extends AbstractMigration
{
    private const PACKAGES = [
        'payment' => ['alipay_order_code', 'wechat_native'],
        'sms' => ['aliyun_sms', 'tencent_sms', 'submail_sms', 'smsbao'],
        'mail' => ['smtp', 'aliyun_dm', 'tencent_ses'],
        'identity' => ['alipay_identity', 'tencent_faceid'],
        'storage' => ['aliyun_oss', 'tencent_cos', 'qiniu_storage', 'local_storage'],
        'oauth' => ['wechat_login', 'qq_login'],
        'captcha' => ['image_captcha', 'geetest_v4', 'yidun_captcha', 'tencent_captcha', 'aliyun_captcha'],
    ];

    /** @return void 只处理固定内置清单，不扫描并自动启用第三方插件 */
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $table = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_installations');
        $events = $this->getAdapter()->quoteTableName($prefix . 'business_plugin_events');
        $pdo = $this->getAdapter()->getConnection();
        $exists = $pdo->prepare("SELECT id FROM {$table} WHERE plugin_id = ?");
        $pending = [];
        foreach (self::PACKAGES as $category => $ids) {
            foreach ($ids as $id) {
                $exists->execute([$id]);
                if ($exists->fetchColumn() !== false) {
                    // installed、disabled、draining、uninstalled 等已有状态均原样保留。
                    continue;
                }
                $folder = dirname(__DIR__, 2) . '/plugins/' . $category . '/' . $id;
                if (!is_dir($folder)) {
                    continue;
                }
                foreach ([dirname($folder, 2), dirname($folder), $folder] as $path) {
                    if (is_link($path)) {
                        throw new RuntimeException('内置通道目录不能使用符号链接');
                    }
                }
                $hash = $this->packageHash($folder);
                $manifest = json_decode(file_get_contents($folder . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
                if (($manifest['id'] ?? '') !== $id || ($manifest['category'] ?? '') !== $category
                    || ($manifest['publisher'] ?? '') !== 'howstack-team' || ($manifest['manifest_version'] ?? '') !== '1.0'
                    || !is_string($manifest['version'] ?? null)
                    || !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['version'])) {
                    throw new RuntimeException('内置通道包身份或版本无效：' . $id);
                }
                if (is_file($folder . '/composer.json')
                    && (!is_file($folder . '/composer.lock') || !is_file($folder . '/vendor/autoload.php'))) {
                    throw new RuntimeException('内置通道缺少发行依赖：' . $id);
                }
                $pending[] = [$id, $category, $manifest['version'], $hash];
            }
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $insert = $pdo->prepare("INSERT INTO {$table}
                (plugin_id, category, version, package_hash, state, revision, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'enabled', 2, ?, ?)");
            $event = $pdo->prepare("INSERT INTO {$events}
                (plugin_id, action, from_state, to_state, actor_id, package_hash, created_at)
                VALUES (?, ?, ?, ?, NULL, ?, ?)");
            $now = date('Y-m-d H:i:s');
            foreach ($pending as [$id, $category, $version, $hash]) {
                $insert->execute([$id, $category, $version, $hash, $now, $now]);
                $event->execute([$id, 'install', null, 'installed', $hash, $now]);
                $event->execute([$id, 'enable', 'installed', 'enabled', $hash, $now]);
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

    /** @return never @throws IrreversibleMigrationException 安装历史只能前向维护 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('不得删除内置插件登记或覆盖已有启停状态');
    }

    /**
     * 冻结与 v1 包注册器一致的摘要算法，不引用会随版本变化的应用类。
     * @param string $folder 固定内置目录
     * @return string 完整运行包 SHA-256
     */
    private function packageHash(string $folder): string
    {
        $files = [];
        $total = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($folder) + 1);
            if (!$file->isLink() && $file->getFilename() === '.DS_Store') {
                continue;
            }
            $dependency = str_starts_with($relative, 'vendor/');
            if ($file->isLink() || (!$dependency && (str_contains($relative, '/config/') || str_starts_with($relative, 'config/')))
                || (!$dependency && str_contains($relative, '.env'))
                || preg_match('~(^|/)\.env(?:[./]|$)~', $relative) || str_contains($relative, 'node_modules/')) {
                throw new RuntimeException('内置通道包包含不允许的路径或秘密文件');
            }
            if (!$file->isFile()) {
                continue;
            }
            $total += $file->getSize();
            if ($total > 104857600 || count($files) >= 10000) {
                throw new RuntimeException('内置通道包超过文件数或容量限制');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException('内置通道包无法计算文件摘要');
            }
            $files[$relative] = $hash;
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
