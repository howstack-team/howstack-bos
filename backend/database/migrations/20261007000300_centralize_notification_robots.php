<?php

declare(strict_types=1);

use app\shared\infrastructure\SodiumSensitiveDataCipher;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 统一消息机器人配置；保留模板、启用状态和其他渠道，冲突时不擅自选择机器人。 */
final class CentralizeNotificationRobots extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $quote = fn (string $name): string => $this->getAdapter()->quoteTableName($prefix . $name);
        $categories = $quote('system_setting_categories');
        $settings = $quote('system_settings');
        $messages = $quote('message_configurations');
        $now = date('Y-m-d H:i:s');
        $cipher = null;
        $robots = ['dingtalk' => ['webhook_url' => '', 'sign_secret' => ''], 'wecom' => ['webhook_url' => ''], 'feishu' => ['webhook_url' => '', 'sign_secret' => '']];
        $existing = $this->fetchRow("SELECT id, setting_value FROM {$settings} WHERE setting_key = 'notifications.robots_encrypted'");
        $revision = 0;
        if ($existing !== false) {
            $cipher = new SodiumSensitiveDataCipher((string) getenv('APP_KEY'));
            $global = json_decode($cipher->decrypt($existing['setting_value']), true, flags: JSON_THROW_ON_ERROR);
            $robots = $global['robots'];
            $revision = $global['revision'];
        }
        $changes = [];
        foreach ($this->fetchAll("SELECT id, configuration_encrypted, revision FROM {$messages} WHERE audience = 'admin'") as $row) {
            $cipher ??= new SodiumSensitiveDataCipher((string) getenv('APP_KEY'));
            $config = json_decode($cipher->decrypt($row['configuration_encrypted']), true, flags: JSON_THROW_ON_ERROR);
            $changed = false;
            foreach ($robots as $channel => &$values) {
                foreach (array_keys($values) as $key) {
                    if (!array_key_exists($key, $config[$channel] ?? [])) {
                        continue;
                    }
                    $old = $config[$channel][$key];
                    if ($old !== '') {
                        if ($values[$key] !== '' && $values[$key] !== $old) {
                            throw new RuntimeException('历史消息存在不同的机器人配置，请先统一配置后重试迁移；原配置未修改');
                        }
                        $values[$key] = $old;
                    }
                    unset($config[$channel][$key]);
                    $changed = true;
                }
            }
            unset($values);
            if ($changed) {
                $changes[] = [$cipher->encrypt(json_encode($config, JSON_THROW_ON_ERROR)), (int) $row['revision'] + 1, $now, (int) $row['id']];
            }
        }
        // 所有历史数据先完成校验，再在同一事务迁移；重复执行只补缺失分类。
        $pdo = $this->getAdapter()->getConnection();
        $ownedTransaction = !$pdo->inTransaction();
        if ($ownedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $category = $this->fetchRow("SELECT id FROM {$categories} WHERE code = 'notifications'");
            if ($category === false) {
                $this->execute("INSERT INTO {$categories} (code, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", ['notifications', '消息通知', 30, $now, $now]);
                $category = $this->fetchRow("SELECT id FROM {$categories} WHERE code = 'notifications'");
            }
            if ($changes !== []) {
                $payload = $cipher->encrypt(json_encode(['revision' => $revision + 1, 'robots' => $robots], JSON_THROW_ON_ERROR));
                if ($existing === false) {
                    $this->execute("INSERT INTO {$settings} (category_id, setting_key, setting_value, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [(int) $category['id'], 'notifications.robots_encrypted', $payload, 0, $now, $now]);
                } else {
                    $this->execute("UPDATE {$settings} SET setting_value = ?, is_public = 0, updated_at = ? WHERE id = ?", [$payload, $now, (int) $existing['id']]);
                }
                foreach ($changes as $change) {
                    $this->execute("UPDATE {$messages} SET configuration_encrypted = ?, revision = ?, updated_at = ? WHERE id = ?", $change);
                }
            }
            if ($ownedTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('统一机器人配置只允许前向修复，不得删除凭据');
    }
}
