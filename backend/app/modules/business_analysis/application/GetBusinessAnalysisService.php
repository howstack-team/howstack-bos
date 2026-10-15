<?php

declare(strict_types=1);

namespace app\modules\business_analysis\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\business_analysis\contract\BusinessAnalysisRepositoryInterface;
use app\modules\business_analysis\domain\AnalysisPeriod;

/** 先授权，再汇总交易事实、同长度前期与连续趋势。 */
final readonly class GetBusinessAnalysisService
{
    private const array EMPTY_SUMMARY = [
        'paid_amount_minor' => 0, 'refund_amount_minor' => 0, 'net_paid_amount_minor' => 0,
        'created_orders' => 0, 'paid_orders' => 0, 'paid_members' => 0,
        'new_members' => 0, 'average_paid_order_minor' => 0,
    ];

    public function __construct(
        private AdminAccessControlService $access,
        private BusinessAnalysisRepositoryInterface $repository,
    ) {
    }

    /**
     * @param AdminAccount $actor 当前管理员
     * @param AnalysisPeriod $period 已验证区间
     * @return array{period: array<string, string>, summary: array<string, int>, previous_summary: array<string, int>, trend: list<array<string, int|string>>, order_types: list<array<string, int|string>>, order_statuses: list<array{status: string, count: int}>} 经营分析读模型
     * @throws BusinessException 无经营分析查看权限时拒绝读取全站经营数据
     */
    public function get(AdminAccount $actor, AnalysisPeriod $period): array
    {
        $this->access->requirePermission($actor, 'admin.page.analysis.view');
        $data = $this->repository->aggregate($period);
        $current = self::EMPTY_SUMMARY;
        $previous = self::EMPTY_SUMMARY;
        $series = $types = $statuses = [];
        $start = $period->start->format('Y-m-d');
        for ($date = $period->start; $date <= $period->end; $date = $date->modify('+1 day')) {
            $key = $date->format($period->granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $series[$key] = ['date' => $key, 'created_orders' => 0, 'paid_orders' => 0,
                'paid_amount_minor' => 0, 'refund_amount_minor' => 0, 'net_paid_amount_minor' => 0, 'new_members' => 0];
        }
        foreach (['created', 'paid', 'refunds', 'members'] as $source) {
            foreach ($data[$source] as $row) {
                $isCurrent = $row['date'] >= $start;
                $key = $period->granularity === 'month' ? substr($row['date'], 0, 7) : $row['date'];
                $increments = match ($source) {
                    'created' => ['created_orders' => $row['count']],
                    'paid' => ['paid_orders' => $row['count'], 'paid_amount_minor' => $row['amount_minor']],
                    'refunds' => ['refund_amount_minor' => $row['amount_minor']],
                    'members' => ['new_members' => $row['count']],
                };
                foreach ($increments as $metric => $value) {
                    if ($isCurrent) {
                        $current[$metric] += $value;
                        $series[$key][$metric] += $value;
                    } else {
                        $previous[$metric] += $value;
                    }
                }
                if ($isCurrent && isset($row['order_type'])) {
                    $type = $row['order_type'];
                    $types[$type] ??= ['order_type' => $type, 'created_orders' => 0, 'paid_orders' => 0, 'paid_amount_minor' => 0];
                    foreach ($increments as $metric => $value) {
                        $types[$type][$metric] += $value;
                    }
                }
                if ($isCurrent && $source === 'created') {
                    $statuses[$row['status']] = ($statuses[$row['status']] ?? 0) + $row['count'];
                }
            }
        }
        $current['paid_members'] = $data['paid_members']['current'];
        $previous['paid_members'] = $data['paid_members']['previous'];
        foreach ($series as &$point) {
            $point['net_paid_amount_minor'] = $point['paid_amount_minor'] - $point['refund_amount_minor'];
        }
        unset($point);
        ksort($types);
        ksort($statuses);

        return [
            'period' => $period->metadata(), 'summary' => $this->finish($current),
            'previous_summary' => $this->finish($previous), 'trend' => array_values($series),
            'order_types' => array_values($types),
            'order_statuses' => array_map(static fn (string $status, int $count): array => [
                'status' => $status, 'count' => $count,
            ], array_keys($statuses), array_values($statuses)),
        ];
    }

    /**
     * @param array<string, int> $summary 聚合值
     * @return array<string, int> 派生金额均为整数分
     */
    private function finish(array $summary): array
    {
        $summary['net_paid_amount_minor'] = $summary['paid_amount_minor'] - $summary['refund_amount_minor'];
        $orders = $summary['paid_orders'];
        if ($orders > 0) {
            $summary['average_paid_order_minor'] = intdiv($summary['paid_amount_minor'], $orders)
                + ((($summary['paid_amount_minor'] % $orders) * 2 >= $orders) ? 1 : 0);
        }

        return $summary;
    }
}
