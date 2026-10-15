<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 固定入口迁至包根目录；仅核准已审核的内置包精确摘要对，不改变凭据或业务状态。 */
final class MoveBusinessPluginEntriesToRoot extends AbstractMigration
{
    /** @var array<string, array{string, string, string, string}> 类别、版本、旧摘要、新摘要 */
    private const PACKAGES = [
        'magic_cloud' => ['provider', '1.1.0', '419c20112f55c430fca4e8b69666508263678b299476e00cb737721f232b095c', '88edd3756aafefad025b01ba69c069ddeb116e0ba07531f1dc866ceb33b41099'],
        'alipay_order_code' => ['payment', '1.0.0', 'ff6bf20510da981d6c7d64fec1d32e551185e602cdc0767c0422fef090533c24', 'fcde7baf8314538f25687eb2e9e0189c565f466962c395bd4544ffddb0def0c8'],
        'wechat_native' => ['payment', '1.0.0', 'f93f2e174309f2e39a98930e4195873e7e22f4c575cb5124a3caff8ab7f44a47', 'cceb12496bed06cf6e7667f20c3e550a43f3171cef1145028cba8b2f787a1ad2'],
        'alipay_identity' => ['identity', '1.0.0', 'c01b99efdb12d26699c13effd74f45e3fe237365b8af1a26a737e547a7d559d7', '83935a790faf321535b6ddbcb7b4ff78bc9c292f4d4c6f7502bbdd7e5a5cee81'],
        'tencent_faceid' => ['identity', '1.0.0', '857043e759bf558f0ab4908f097122ee2a5a2acd8d99438334745287ffec3b88', '9311c614fe77b12cf876231607367b9d0ec6251bbbd9e3dacf69173c2787aecd'],
        'aliyun_sms' => ['sms', '1.0.0', '5d30a12643fe665d5f1eedb5d67ab11963263eded038a79c0a700b2d72bcd5d2', 'a80f350c5d03032ef9c63d8d85f9be53db6c0d6acc2232d869bf5046142ba166'],
        'smsbao' => ['sms', '1.0.0', 'd0b8e31533113021e32483cb480423d38f992a0e2fb9401095091ad49c2ff20f', '44b8a420c813a7000c548a1edf75dc2192c5a4184b815f5cc57a0fbfcb948c90'],
        'submail_sms' => ['sms', '1.0.0', '48427d252476245c0f87ad4dfcaa6a9c17b5d657f83530c030db2f650b6241c5', '8da53ecd3d33e15c1eb18e33a9d4d2fa0b5edfd456f75957504a60f83abd9a75'],
        'tencent_sms' => ['sms', '1.0.0', 'da0f87e709fcaaf00d7dbbb11bf0ede7317d19503fa4e52e7a74718dab8c99b7', '375f2355ee3963ef8eb6e4f35c22033653836ee2d8ff4a5c3f1150db093c9796'],
        'aliyun_dm' => ['mail', '1.0.0', '8c3e087d5bb3fdb62e43348575283832662f1819ac0ee1b75a0dd21b092d41c3', '79caa5323a526893ead51cf4fd41f9c4f1197b5c53685e9b68847dcfb4cd47e3'],
        'smtp' => ['mail', '1.0.1', '300171cb8fb2ca14057a33b63f61908d5925b75783fb37b2e046b873dabbf134', 'db3d9048e81e5fc2b6892daebbe59b21e4f5429ad250059a0db628d9c98811af'],
        'tencent_ses' => ['mail', '1.0.0', '710ab6d47ca4cb2836872ef6b62bdf16ec805ce5e0048bf5d3f3b0ee81915141', '37bf609cee210c4f7696aa27a6193347107aa8e6c6545c53d4e58b3732226dc9'],
        'aliyun_oss' => ['storage', '1.0.0', 'c5d397c5260fc8523e1cd5caf94120c36a9dcbcce08e68be7af09dcbf66daf47', 'e0f266b9810780c88b75680f8b9d9d9b8c21f95b3a688780180dacdec7bb9740'],
        'local_storage' => ['storage', '1.0.0', 'f33d0113b0a4d484c107f65bfd968d9e92cbfd5cb9da45aba1dd62a15bfcc408', '873f399cef8387b9d93daa5609b766ee3b8adc90fa8ff74c8df10f9a91031c3a'],
        'qiniu_storage' => ['storage', '1.0.0', 'd1375bac1e3d95e11e9151a03be20cb9e4777dac01a5a772a7d95009287b96c2', '70295f61f8a170aff78c524eea95f0ea806ba685927fa2c71d10b312022e78ab'],
        'tencent_cos' => ['storage', '1.0.0', '8db60c53976df1d6051dfea575fe3ed0dc51ce08bf2ed71420c878b9d276108f', 'f1ce174bf15fdcd54bbfa236c133d891cd8a680917cd4dbe2d3bd406a3d4deb7'],
        'qq_login' => ['oauth', '1.0.0', '22566143facc621cf9291a5dee1da730585e53ca00a122c461f1330dd8424111', '80d08def0ac931c69177921b61e8a7bd3d6e2765eafffce441c8d9dd68f5ad47'],
        'wechat_login' => ['oauth', '1.0.0', '6fd5c111760908b3fc4f5498365fe84cabd1b44e8a4face499f2eaff13bc7283', '59b52cc63b121e8b7a4f4d7fb91545915e71053fd7c6512f5f9078815105980a'],
        'aliyun_captcha' => ['captcha', '1.0.0', 'd0747a3f962cc0e815b1ddc64f03cbfcfc20bf58a1ff4a2e3ae3d649e1f28151', '547c0b003ed1cfd77af54695e48f04d1553e029ad42a965da8620ed3a247356c'],
        'geetest_v4' => ['captcha', '1.0.0', 'e801a4a72d6e04defcac44503eb55d70a5a2da268e00035ef6fa3ce99ef64271', '76b472369f271f30a5ddd5dc7f9689fb63f9c83b115ab42ff56985313e8b1971'],
        'image_captcha' => ['captcha', '1.0.0', 'b342fe791f5b630f13026a57dfcf3ee765425a705d0086954dbded644697646b', 'aad1e70909e13a75197ed1232ebdd3f820b3f05d22956fae04d5544c5141123c'],
        'tencent_captcha' => ['captcha', '1.0.0', '42423db24d41db3d0399b84bbceaecb6ef6b969873309ef153215aae279d28da', 'eab34673396d1d76492683f19423405de6b7f2d6b47c01600feae4c3274983fd'],
        'yidun_captcha' => ['captcha', '1.0.0', '54961f1e0c1cf3ef5fc3870adda9e73a9073f51556a1d36e824c5496ea0337a9', 'a987162a16846769555e8a334a5d7dc4970c63a08a6b69d3eca9234fc41deafa'],
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
                $folder = dirname(__DIR__, 2) . '/plugins/' . $category . '/' . $row['plugin_id'];
                // 源码或依赖有其他改动时不得隐式审核；新装包和重跑也不重复更新。
                if (!is_dir($folder) || $this->packageHash($folder) !== $newHash) continue;
                $update = $pdo->prepare("UPDATE {$installations} SET package_hash=?, revision=revision+1, updated_at=? WHERE id=? AND package_hash=? AND revision=?");
                $now = date('Y-m-d H:i:s');
                $update->execute([$newHash, $now, $row['id'], $oldHash, $row['revision']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('插件入口迁移时安装记录发生并发修改');
                $this->connectionFingerprints($pdo, $quote, $row['plugin_id'], $category, $oldHash, $newHash, $lock);
                $event = $pdo->prepare("INSERT INTO {$events} (plugin_id, action, from_state, to_state, actor_id, package_hash, created_at) VALUES (?, 'entry_layout', ?, ?, NULL, ?, ?)");
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
     * @param string $newHash 新布局指纹
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
        throw new IrreversibleMigrationException('请使用前向修复保留插件入口登记及审计记录');
    }
}
