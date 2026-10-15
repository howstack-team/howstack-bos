<?php

declare(strict_types=1);

namespace app\modules\message_configuration\contract;

/** 模板策略整体保存，公开读取不得包含机器人令牌或完整 Webhook。 */
interface MessageConfigurationRepositoryInterface
{
    /** @return array<string, array{revision: int, configurations: array<string, mixed>}> 按事件读取服务端配置 */
    public function all(string $audience): array;

    /** @return array{revision: int, configurations: array<string, mixed>} 服务端解密配置 */
    public function get(string $audience, string $code): array;

    /** @param array<string, mixed> $configurations 校验后的完整事件策略 */
    public function save(string $audience, string $code, int $revision, array $configurations): void;
}
