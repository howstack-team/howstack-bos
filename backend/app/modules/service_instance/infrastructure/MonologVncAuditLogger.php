<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure;

use app\modules\service_instance\contract\VncAuditLoggerInterface;
use Psr\Log\LoggerInterface;

/** 通过私有系统日志记录会员和后台VNC签发事件，不保存上游原始报文。 */
final readonly class MonologVncAuditLogger implements VncAuditLoggerInterface
{
    /**
     * @param LoggerInterface $logger 私有日志通道
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /** @inheritDoc */
    public function record(string $actor, int $id, string $outcome): void
    {
        $this->logger->info('service.vnc.access', ['actor' => $actor, 'service_id' => $id, 'outcome' => $outcome]);
    }
}
