<?php

declare(strict_types=1);

namespace app\modules\licensing\infrastructure;

use app\modules\licensing\contract\LicenseClockInterface;

final readonly class SystemLicenseClock implements LicenseClockInterface
{
    public function now(): int
    {
        return time();
    }
}
