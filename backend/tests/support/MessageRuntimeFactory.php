<?php

declare(strict_types=1);

namespace tests\support;

use app\modules\message\application\PublishMessageService;
use app\modules\message\infrastructure\DatabaseMessageRepository;
use app\modules\message\infrastructure\DatabaseMessageRecipientDirectory;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\modules\system_settings\infrastructure\DatabaseNotificationRobotSettingsRepository;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\infrastructure\DatabaseTransactionManager;

/** 在调用方隔离数据库内使用真正消息仓储，绝不连接外部Redis或厂商。 */
final class MessageRuntimeFactory
{
    public static function publisher(): PublishMessageService
    {
        $cipher = new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2));
        $transactions = new DatabaseTransactionManager();
        return new PublishMessageService(new DatabaseMessageRepository($cipher), new DatabaseMessageRecipientDirectory(),
            new DatabaseMessageConfigurationRepository($cipher, $transactions),
            new DatabaseNotificationRobotSettingsRepository($cipher, $transactions), $transactions,
            new \app\modules\channel\infrastructure\DatabaseChannelDisplayName());
    }
}
