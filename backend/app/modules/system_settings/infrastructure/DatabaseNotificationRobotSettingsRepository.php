<?php

declare(strict_types=1);

namespace app\modules\system_settings\infrastructure;

use app\exception\BusinessException;
use app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\modules\system_settings\infrastructure\model\SystemSettingCategoryModel;
use app\modules\system_settings\infrastructure\model\SystemSettingModel;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use RuntimeException;

/** 复用系统设置表，加密保存全部机器人并通过分类行锁与修订号保护并发。 */
final readonly class DatabaseNotificationRobotSettingsRepository implements NotificationRobotSettingsRepositoryInterface
{
    public function __construct(
        private SensitiveDataCipherInterface $cipher,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function get(): array
    {
        $row = SystemSettingModel::query()->where('setting_key', NotificationRobotDefinition::SETTING_KEY)->first();
        return $row === null
            ? ['revision' => 0, 'robots' => NotificationRobotDefinition::defaults()]
            : json_decode($this->cipher->decrypt($row->setting_value), true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(int $revision, array $robots): void
    {
        $this->transactions->run(function () use ($revision, $robots): void {
            $category = SystemSettingCategoryModel::query()->where('code', 'notifications')->lockForUpdate()->first();
            if ($category === null) {
                throw new RuntimeException('消息通知设置尚未初始化，请完成数据库升级');
            }
            if ($this->get()['revision'] !== $revision) {
                throw new BusinessException('机器人设置已变化，请重新加载后编辑', ApiErrorCode::CONFLICT, 409);
            }
            SystemSettingModel::query()->updateOrCreate(['setting_key' => NotificationRobotDefinition::SETTING_KEY], [
                'category_id' => $category->id,
                'setting_value' => $this->cipher->encrypt(json_encode(['revision' => $revision + 1, 'robots' => $robots], JSON_THROW_ON_ERROR)),
                'is_public' => false,
            ]);
        });
    }
}
