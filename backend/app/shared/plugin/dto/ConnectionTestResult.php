<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

/** 只读认证成功，不代表生产能力可用。失败通过 SDK 异常返回。 */
final readonly class ConnectionTestResult
{
    /**
     * 返回安全的测试说明。
     * @param string $message 不含认证令牌的说明
     */
    public function __construct(public string $message = '连接测试成功') {}
}
