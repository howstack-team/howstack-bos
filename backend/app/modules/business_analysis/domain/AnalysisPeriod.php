<?php

declare(strict_types=1);

namespace app\modules\business_analysis\domain;

use DateTimeImmutable;

/** 包含结束当天的经营分析区间；对比区间为紧邻的同天数区间。 */
final readonly class AnalysisPeriod
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $granularity,
        public DateTimeImmutable $generatedAt,
    ) {
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function previousStart(): DateTimeImmutable
    {
        return $this->start->modify('-' . $this->days() . ' days');
    }

    public function exclusiveEnd(): string
    {
        return $this->end->modify('+1 day')->format('Y-m-d H:i:s');
    }

    /** @return array<string, string> 时间、币种和口径元数据 */
    public function metadata(): array
    {
        return [
            'start_date' => $this->start->format('Y-m-d'),
            'end_date' => $this->end->format('Y-m-d'),
            'previous_start_date' => $this->previousStart()->format('Y-m-d'),
            'previous_end_date' => $this->start->modify('-1 day')->format('Y-m-d'),
            'granularity' => $this->granularity,
            'timezone' => $this->start->getTimezone()->getName(),
            'currency' => 'CNY',
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
