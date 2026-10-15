<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

use JsonSerializable;
use LogicException;

/** 单次调用配置；调试与 JSON 仅输出占位，禁止放入持久消息。 */
final readonly class Configuration implements JsonSerializable
{
    /**
     * @param array<string, string|int|bool> $values 当前连接必要值，包含受保护秘密
     */
    public function __construct(public array $values) {}

    /**
     * @return array{redacted: true} 日志中仅保留脱敏标记
     */
    public function jsonSerialize(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return array{redacted: true} 调试不输出凭据
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return never 禁止持久化复制连接秘密
     * @throws LogicException 调用方应保存连接引用
     */
    public function __serialize(): array
    {
        throw new LogicException('插件配置不能序列化，任务应保存连接引用');
    }
}
