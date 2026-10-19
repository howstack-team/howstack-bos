<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 每类产品决定续费期间、单实例费用及不可变定价快照。 */
interface ProductRenewalPricingInterface
{
    /**
     * @param string $type 产品身份
     * @param array<string,mixed> $origin 原购买标准配置
     * @param int $months 周期，0仅取选项
     * @return array<string,mixed> 周期列表及可选报价
     */
    public function quote(string $type, array $origin, int $months): array;
}
