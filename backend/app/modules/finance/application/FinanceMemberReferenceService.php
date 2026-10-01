<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\modules\finance\contract\FinanceRecordRepositoryInterface;

/** 向会员模块公开资金历史引用状态，避免跨模块直接读表。 */
final readonly class FinanceMemberReferenceService
{
    public function __construct(private FinanceRecordRepositoryInterface $records)
    {
    }

    public function hasRecords(int $memberId): bool
    {
        return $this->records->hasMemberRecords($memberId);
    }
}
