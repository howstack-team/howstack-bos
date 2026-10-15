<?php

declare(strict_types=1);

namespace app\modules\business_analysis\infrastructure;

use app\modules\business_analysis\contract\BusinessAnalysisRepositoryInterface;
use app\modules\business_analysis\domain\AnalysisPeriod;
use Illuminate\Database\Query\Builder;
use support\Db;

/** 从交易事实聚合；日期过滤在索引列上执行，不联表展开订单项。 */
final class DatabaseBusinessAnalysisRepository implements BusinessAnalysisRepositoryInterface
{
    /** @inheritDoc */
    public function aggregate(AnalysisPeriod $period): array
    {
        return Db::transaction(function () use ($period): array {
            $created = $this->range(Db::table('orders')->where('currency', 'CNY'), 'created_at', $period)
                ->selectRaw('SUBSTR(created_at, 1, 10) AS date, order_type, status, COUNT(*) AS count')
                ->groupByRaw('SUBSTR(created_at, 1, 10), order_type, status')->get()->all();
            $paidQuery = $this->range(Db::table('orders')->where('currency', 'CNY')->where('paid_minor', '>', 0), 'paid_at', $period);
            $paid = (clone $paidQuery)
                ->selectRaw('SUBSTR(paid_at, 1, 10) AS date, order_type, COUNT(*) AS count, SUM(paid_minor) AS amount_minor')
                ->groupByRaw('SUBSTR(paid_at, 1, 10), order_type')->get()->all();
            // distinct 必须按整个区间计算，不能累加每天的付费会员数。
            $paidMembers = (clone $paidQuery)->selectRaw(
                'COUNT(DISTINCT CASE WHEN paid_at >= ? THEN member_id END) AS current_count, '
                . 'COUNT(DISTINCT CASE WHEN paid_at < ? THEN member_id END) AS previous_count',
                [$period->start->format('Y-m-d H:i:s'), $period->start->format('Y-m-d H:i:s')],
            )->first();
            $refunds = $this->range(Db::table('refund_requests')->where('currency', 'CNY')
                ->where('source_type', 'order_unit')->where('status', 'completed'), 'completed_at', $period)
                ->selectRaw('SUBSTR(completed_at, 1, 10) AS date, COUNT(*) AS count, SUM(amount_minor) AS amount_minor')
                ->groupByRaw('SUBSTR(completed_at, 1, 10)')->get()->all();
            $members = $this->range(Db::table('members'), 'created_at', $period)
                ->selectRaw('SUBSTR(created_at, 1, 10) AS date, COUNT(*) AS count')
                ->groupByRaw('SUBSTR(created_at, 1, 10)')->get()->all();

            return [
                'created' => $this->rows($created, ['count']),
                'paid' => $this->rows($paid, ['count', 'amount_minor']),
                'refunds' => $this->rows($refunds, ['count', 'amount_minor']),
                'members' => $this->rows($members, ['count']),
                'paid_members' => ['current' => (int) $paidMembers->current_count, 'previous' => (int) $paidMembers->previous_count],
            ];
        });
    }

    /** 固定内部时间列与半开边界；包含开始/结束日，排除次日零点。 */
    private function range(Builder $query, string $column, AnalysisPeriod $period): Builder
    {
        return $query->where($column, '>=', $period->previousStart()->format('Y-m-d H:i:s'))
            ->where($column, '<', $period->exclusiveEnd());
    }

    /**
     * @param list<object> $rows SQL 聚合行
     * @param list<string> $integers 固定整数列；MySQL 的 SUM 可能返回数字字符串
     * @return list<array<string, int|string>> 不泄露额外字段的投影
     */
    private function rows(array $rows, array $integers): array
    {
        return array_map(static function (object $row) use ($integers): array {
            $value = (array) $row;
            foreach ($integers as $key) {
                $value[$key] = (int) $value[$key];
            }

            return $value;
        }, $rows);
    }
}
