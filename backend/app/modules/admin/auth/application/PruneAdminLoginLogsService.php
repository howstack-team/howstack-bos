<?php

declare(strict_types=1);

namespace app\modules\admin\auth\application;

use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/** 计算保留期限并按小批次清理登录日志。 */
final readonly class PruneAdminLoginLogsService
{
    /**
     * 初始化日志保留期服务。
     * @param LoginLogRepositoryInterface $logs 登录日志仓储
     */
    public function __construct(private LoginLogRepositoryInterface $logs)
    {
    }

    /**
     * 预览过期记录，不执行删除。
     * @param int                    $days 保留天数
     * @param DateTimeImmutable|null $now  当前时间；测试时可注入
     * @return array{cutoff: string, count: int} 截止时间和记录数
     * @throws InvalidArgumentException 保留天数超出范围时抛出
     */
    public function preview(int $days, ?DateTimeImmutable $now = null): array
    {
        if ($days < 30 || $days > 3650) {
            throw new InvalidArgumentException('保留天数必须在 30 到 3650 之间');
        }
        $cutoff = ($now ?? new DateTimeImmutable())->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        return ['cutoff' => $cutoff, 'count' => $this->logs->countBefore($cutoff)];
    }

    /**
     * 按固定批次清理截止时间之前的记录。
     * @param string $cutoff 截止时间
     * @return int 实际删除条数
     */
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
