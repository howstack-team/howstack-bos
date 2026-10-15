<?php

declare(strict_types=1);

namespace app\modules\member\contract;

interface RegistrationRateLimiterInterface
{
    public function check(string $operation, string $target, string $ip): void;
}
