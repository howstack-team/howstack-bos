<?php

declare(strict_types=1);

namespace app\modules\business_analysis\contract;

use app\modules\business_analysis\domain\AnalysisPeriod;

/** 经营分析的跨模块只读投影，不提供业务数据修改能力。 */
interface BusinessAnalysisRepositoryInterface
{
    /**
     * @param AnalysisPeriod $period 当前与对比区间
     * @return array{
     *   created: list<array{date: string, order_type: string, status: string, count: int}>,
     *   paid: list<array{date: string, order_type: string, count: int, amount_minor: int}>,
     *   refunds: list<array{date: string, count: int, amount_minor: int}>,
     *   members: list<array{date: string, count: int}>,
     *   paid_members: array{current: int, previous: int}
     * } 有界的按日聚合，不含客户身份、地址、配置或支付凭证
     */
    public function aggregate(AnalysisPeriod $period): array;
}
