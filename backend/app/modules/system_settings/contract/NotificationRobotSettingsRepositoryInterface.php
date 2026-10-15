<?php

declare(strict_types=1);

namespace app\modules\system_settings\contract;

/** 仅在后端读取的统一机器人配置，不能直接序列化到公开接口。 */
interface NotificationRobotSettingsRepositoryInterface
{
    /** @return array{revision: int, robots: array<string, array<string, string>>} 解密后的内部配置 */
    public function get(): array;

    /** @param array<string, array<string, string>> $robots 三种机器人的完整配置 */
    public function save(int $revision, array $robots): void;
}
