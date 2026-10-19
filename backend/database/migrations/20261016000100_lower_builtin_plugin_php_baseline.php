<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 官方包PHP要求调整为8.3；仅核准冻结的精确摘要对，保留业务状态及历史交易快照。 */
final class LowerBuiltinPluginPhpBaseline extends AbstractMigration
{
    /** @var array<string, array{string, string, string, string}> 类别、版本、旧摘要、新摘要 */
    private const PACKAGES = [
        'magic_cloud' => ['provider', '1.1.0', '88edd3756aafefad025b01ba69c069ddeb116e0ba07531f1dc866ceb33b41099', '6142fb5b9676b585ff09f13ccc2e40d5bbf3ddabd95f733a7114589b747fd810'],
        'alipay_order_code' => ['payment', '1.0.0', 'fcde7baf8314538f25687eb2e9e0189c565f466962c395bd4544ffddb0def0c8', '8e9756b50ccc6a9f0c0a972af5d22468cc4d80e0c64418b10a248ba6099350cf'],
        'wechat_native' => ['payment', '1.0.0', 'cceb12496bed06cf6e7667f20c3e550a43f3171cef1145028cba8b2f787a1ad2', 'c95d20c420e0e4db78cfb8620da990b643b4084fd77b4d4c705951659a653931'],
        'alipay_identity' => ['identity', '1.0.0', '83935a790faf321535b6ddbcb7b4ff78bc9c292f4d4c6f7502bbdd7e5a5cee81', 'd588a048366c17429354dbe4386af209cb44fbde61e4faf3c9392756931d86de'],
        'tencent_faceid' => ['identity', '1.0.0', '9311c614fe77b12cf876231607367b9d0ec6251bbbd9e3dacf69173c2787aecd', 'b8f8666a9cb930c49948f3227521b7a8234259c32e92ea4beb71f38a90ca5f9d'],
        'aliyun_sms' => ['sms', '1.0.0', 'a80f350c5d03032ef9c63d8d85f9be53db6c0d6acc2232d869bf5046142ba166', '5cf820b3fc51caf4137fb0ae2fe8f7eeb152d325d4a2b18934dbcad7b5120d3b'],
        'smsbao' => ['sms', '1.0.0', '44b8a420c813a7000c548a1edf75dc2192c5a4184b815f5cc57a0fbfcb948c90', 'ad0ba5be2903e4f7d392d00e5a3388b41d4b44893200be4132f558f39e2099ce'],
        'submail_sms' => ['sms', '1.0.0', '8da53ecd3d33e15c1eb18e33a9d4d2fa0b5edfd456f75957504a60f83abd9a75', '5dfae6846917fbba07b4c9b86f404f3f2eed19e034df500581ac5f9a2f470674'],
        'tencent_sms' => ['sms', '1.0.0', '375f2355ee3963ef8eb6e4f35c22033653836ee2d8ff4a5c3f1150db093c9796', '9185c645e5ff2bb18255e2781b5bff3b9fa3793a935253ae445e8d6a8f0341fa'],
        'aliyun_dm' => ['mail', '1.0.0', '79caa5323a526893ead51cf4fd41f9c4f1197b5c53685e9b68847dcfb4cd47e3', 'fc275779b8bb3d272b12b17fe72f44f68bbcc2fc0bc30bd87b50a46aee5d9115'],
        'smtp' => ['mail', '1.0.1', 'db3d9048e81e5fc2b6892daebbe59b21e4f5429ad250059a0db628d9c98811af', 'ad36ffbe4b3fac70fb5f6e75ec4d3fde0fb775c53ac27ed3d846e103cdac74d3'],
        'tencent_ses' => ['mail', '1.0.0', '37bf609cee210c4f7696aa27a6193347107aa8e6c6545c53d4e58b3732226dc9', '58f49a787ca8fb2b8fd5a6eac61042147bff9855ef3cf00af406b28441493c2b'],
        'aliyun_oss' => ['storage', '1.0.0', 'e0f266b9810780c88b75680f8b9d9d9b8c21f95b3a688780180dacdec7bb9740', 'c7c92f4aac0bd9264906aa5f226e7ed76b98cea3bff4c1e6bc79931e688c24a2'],
        'local_storage' => ['storage', '1.0.0', '873f399cef8387b9d93daa5609b766ee3b8adc90fa8ff74c8df10f9a91031c3a', 'bb9f0c2c3be8d531fa51616fb61b8072b6ab051da25448f6997ed22db46cb8cd'],
        'qiniu_storage' => ['storage', '1.0.0', '70295f61f8a170aff78c524eea95f0ea806ba685927fa2c71d10b312022e78ab', '2fe7b28e5382dd0b86658eb9ddc0f3f9c4eff070668c3c0d0ffa078fbd6aaa77'],
        'tencent_cos' => ['storage', '1.0.0', 'f1ce174bf15fdcd54bbfa236c133d891cd8a680917cd4dbe2d3bd406a3d4deb7', '5902de11b53ffc237899b72b03d487285492b2e29b9b61b9f33541da2adc3c49'],
        'qq_login' => ['oauth', '1.0.0', '80d08def0ac931c69177921b61e8a7bd3d6e2765eafffce441c8d9dd68f5ad47', '5dfca5807bc79a47ed1659d72836e8a54c1882b6be41b98268691ccb619c652c'],
        'wechat_login' => ['oauth', '1.0.0', '59b52cc63b121e8b7a4f4d7fb91545915e71053fd7c6512f5f9078815105980a', 'b683b2666eeb06051d9d0237ca4e3e45acb3bf02e211be72009cf4cf946be283'],
        'aliyun_captcha' => ['captcha', '1.0.0', '547c0b003ed1cfd77af54695e48f04d1553e029ad42a965da8620ed3a247356c', '3ce8d5d70d03e42093acc299015933712c16237c719e19fb4ccb891ee2747620'],
        'geetest_v4' => ['captcha', '1.0.0', '76b472369f271f30a5ddd5dc7f9689fb63f9c83b115ab42ff56985313e8b1971', '9353cfb05c38f103b9d58e1b914b92267d92a4a02472fcab568065ba6e32a1a5'],
        'image_captcha' => ['captcha', '1.0.0', 'aad1e70909e13a75197ed1232ebdd3f820b3f05d22956fae04d5544c5141123c', '0272269e44ffdaa3e6ff94e5ababe4d93cd0bb8da5de50823f38b8f3476d21ef'],
        'tencent_captcha' => ['captcha', '1.0.0', 'eab34673396d1d76492683f19423405de6b7f2d6b47c01600feae4c3274983fd', '5d0ab13670d2abc8f5a91b7f26ec3ada0e527d0fa26f1a28228bd881033be0c1'],
        'yidun_captcha' => ['captcha', '1.0.0', 'a987162a16846769555e8a334a5d7dc4970c63a08a6b69d3eca9234fc41deafa', '3b3d27d6d56aa1f2c8d173bfb007e6366422bc98004e4cf811b26f8e77142609'],
    ];

    /** @return void 事务内登记迁移，并规范化在用连接的相同包指纹 */
    public function up(): void
    {
        if (!$this->hasTable('business_plugin_installations')) return;
        $adapter = $this->getAdapter();
        $pdo = $adapter->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        $quote = fn (string $name): string => $adapter->quoteTableName(($adapter->getOption('table_prefix') ?? '') . $name);
        $installations = $quote('business_plugin_installations');
        $events = $quote('business_plugin_events');
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $rows = $pdo->query("SELECT * FROM {$installations} ORDER BY id" . $lock)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                [$category, $version, $oldHash, $newHash] = self::PACKAGES[$row['plugin_id']] ?? ['', '', '', ''];
                if ($row['category'] !== $category || $row['version'] !== $version || $row['package_hash'] !== $oldHash) continue;
                if ($category === 'payment' && $this->hasTable('online_payment_attempts')) {
                    $pending = $pdo->prepare("SELECT COUNT(*) FROM " . $quote('online_payment_attempts') . " a JOIN " . $quote('payment_transactions') . " p ON p.id=a.payment_id WHERE a.plugin_id=? AND (p.status IN ('creating','pending','unknown') OR a.reconcile_until>?)");
                    $pending->execute([$row['plugin_id'], date('Y-m-d H:i:s')]);
                    if ((int) $pending->fetchColumn() > 0) continue; // 原商户仍有待收尾交易时保持已审核摘要。
                }
                $folder = dirname(__DIR__, 2) . '/plugins/' . $category . '/' . $row['plugin_id'];
                // 源码或依赖有其他改动时不得隐式审核；新装包和重跑也不重复更新。
                if (!is_dir($folder) || $this->packageHash($folder) !== $newHash) continue;
                $update = $pdo->prepare("UPDATE {$installations} SET package_hash=?, revision=revision+1, updated_at=? WHERE id=? AND package_hash=? AND revision=?");
                $now = date('Y-m-d H:i:s');
                $update->execute([$newHash, $now, $row['id'], $oldHash, $row['revision']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('插件PHP基线迁移时安装记录发生并发修改');
                $this->connectionFingerprints($pdo, $quote, $row['plugin_id'], $category, $oldHash, $newHash, $lock);
                $event = $pdo->prepare("INSERT INTO {$events} (plugin_id, action, from_state, to_state, actor_id, package_hash, created_at) VALUES (?, 'php_baseline', ?, ?, NULL, ?, ?)");
                $event->execute([$row['plugin_id'], $row['state'], $row['state'], $newHash, $now]);
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /**
     * 仅更新在用连接中精确匹配的运行包摘要；保留配置修订、测试时间、启停及归档记录。
     * 不重写订单、任务、发送记录或销售版本中的历史快照。
     * @param PDO $pdo 迁移连接
     * @param Closure(string): string $quote 加前缀的安全表名
     * @param string $id 内置插件标识
     * @param string $category 固定类别
     * @param string $oldHash 已审核旧包指纹
     * @param string $newHash PHP8.3兼容包指纹
     * @param string $lock 数据库行锁后缀
     */
    private function connectionFingerprints(PDO $pdo, Closure $quote, string $id, string $category, string $oldHash, string $newHash, string $lock): void
    {
        if ($category === 'provider' && $this->hasTable('provider_connections')) {
            $table = $quote('provider_connections');
            $select = $pdo->prepare("SELECT id, capabilities FROM {$table} WHERE adapter_id=?" . $lock);
            $select->execute([$id]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $snapshot = json_decode($row['capabilities'], true, flags: JSON_THROW_ON_ERROR);
                if (($snapshot['_adapter']['manifest_hash'] ?? null) !== $oldHash) continue;
                $snapshot['_adapter']['manifest_hash'] = $newHash;
                $update = $pdo->prepare("UPDATE {$table} SET capabilities=? WHERE id=?");
                $update->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']]);
            }
        } elseif ($this->hasTable('channel_connections')) {
            $table = $quote('channel_connections');
            $select = $pdo->prepare("SELECT id, platform_snapshot FROM {$table} WHERE plugin_id=? AND category=? AND deleted_at IS NULL" . $lock);
            $select->execute([$id, $category]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $snapshot = json_decode($row['platform_snapshot'], true, flags: JSON_THROW_ON_ERROR);
                if (($snapshot['package_hash'] ?? null) !== $oldHash) continue;
                $snapshot['package_hash'] = $newHash;
                $update = $pdo->prepare("UPDATE {$table} SET platform_snapshot=? WHERE id=?");
                $update->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']]);
            }
        }
    }

    /** @param string $folder 内置包目录 @return string 冻结算法的整包 SHA-256，不执行任何插件代码 */
    private function packageHash(string $folder): string
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new RuntimeException('内置包不能包含符号链接');
            if (!$file->isFile() || $file->getFilename() === '.DS_Store') continue;
            $files[substr($file->getPathname(), strlen($folder) + 1)] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    /** @return never @throws IrreversibleMigrationException 仅通过前向迁移保留审核及审计历史 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('请使用前向修复保留插件PHP兼容登记及审计记录');
    }
}
