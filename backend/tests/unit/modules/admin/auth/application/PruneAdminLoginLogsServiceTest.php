<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\application;

use app\modules\admin\auth\application\PruneAdminLoginLogsService;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PruneAdminLoginLogsServiceTest extends TestCase
{
    public function testPreviewDoesNotDeleteAndExecuteUsesBatches(): void
    {
        $repository = new class () implements LoginLogRepositoryInterface {
            public int $deletions = 0;

            public function append(array $record): void { }
            /** @param array<string, string> $filters 独立文本条件，使用 AND 组合 */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array { return ['data' => [], 'total' => 0]; }
    public function countBefore(string $cutoff): int { return 501; }
            public function deleteBatchBefore(string $cutoff, int $limit): int
            {
                ++$this->deletions;
                return $this->deletions === 1 ? 500 : 1;
            }
        };
        $service = new PruneAdminLoginLogsService($repository);

        self::assertSame(['cutoff' => '2026-03-29 12:00:00', 'count' => 501],
            $service->preview(180, new DateTimeImmutable('2026-09-25 12:00:00')));
        self::assertSame(0, $repository->deletions);
        self::assertSame(501, $service->execute('2026-03-29 12:00:00'));
        self::assertSame(2, $repository->deletions);
    }

    public function testRetentionCannotBeShorterThanThirtyDays(): void
    {
        $repository = new class () implements LoginLogRepositoryInterface {
            public function append(array $record): void { }
            /** @param array<string, string> $filters 独立文本条件，使用 AND 组合 */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array { return ['data' => [], 'total' => 0]; }
            public function countBefore(string $cutoff): int { return 0; }
            public function deleteBatchBefore(string $cutoff, int $limit): int { return 0; }
        };
        $service = new PruneAdminLoginLogsService($repository);
        $this->expectException(InvalidArgumentException::class);
        $service->preview(1);
    }
}
