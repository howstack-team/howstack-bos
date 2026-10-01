<?php

declare(strict_types=1);

namespace app\modules\admin\operation\application;

use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/** 计算管理员操作日志保留期限并小批量清理。 */
final readonly class PruneAdminOperationLogsService
{
    public function __construct(private OperationLogRepositoryInterface $logs)
    {
    }

    /** @return array{cutoff: string, count: int} */
    public function preview(int $days, ?DateTimeImmutable $now = null): array
    {
        if ($days < 30 || $days > 3650) {
            throw new InvalidArgumentException('保留天数必须在 30 到 3650 之间');
        }

        $cutoff = ($now ?? new DateTimeImmutable())->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        return ['cutoff' => $cutoff, 'count' => $this->logs->countBefore($cutoff)];
    }

    public function execute(string $cutoff): int
    {
        $deleted = 0;
        do {
            $batch = $this->logs->deleteBatchBefore($cutoff, 500);
            $deleted += $batch;
        } while ($batch === 500);

        return $deleted;
    }
}
