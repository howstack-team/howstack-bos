<?php

declare(strict_types=1);

namespace app\modules\licensing\contract;

/** 可注入时间，明确授权截止边界。 */
interface LicenseClockInterface
{
    /** @return int 当前 Unix 秒 */
    public function now(): int;
}
