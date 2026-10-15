<?php

declare(strict_types=1);

namespace app\shared\plugin\exception;

use RuntimeException;
use Throwable;

/** SDK 安全异常；不依赖核心 HTTP 或业务异常实现。 */
final class PluginException extends RuntimeException
{
    /**
     * 保留安全原因与底层异常链。
     * @param string         $message       可向管理员显示的安全说明
     * @param string         $reason        配置、能力、外部失败或未知结果标识
     * @param Throwable|null $previous      底层异常，仅供服务端诊断
     */
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid_configuration',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
