<?php

declare(strict_types=1);
namespace app\modules\service_instance\domain;

/** 月周期按实际开通时间起算，月末钳制到目标月最后一天，避免一月三十一日溢出至三月。 */
final class ProvisioningSchedule
{
    /** @param \DateTimeImmutable $start 实际开通时间 @param int $months 服务月数 @return \DateTimeImmutable 到期时间 */
    public function expiry(\DateTimeImmutable $start, int $months): \DateTimeImmutable
    {
        if ($months < 1 || $months > 120) throw new \InvalidArgumentException('服务月数无效');
        $month = $start->modify('first day of this month')->modify('+' . $months . ' months');
        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $start->format('d'), (int) $month->format('t')));
    }
}
