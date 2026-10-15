<?php

declare(strict_types=1);
namespace app\modules\admin\auth\infrastructure;
use app\modules\admin\auth\contract\TotpInterface;
use OTPHP\TOTP;
/** 封装MIT许可证的OTPHP RFC6238实现，不自行实现密码算法。 */
final readonly class OtphpTotp implements TotpInterface
{
    public function generate(): string { return TOTP::generate(null, 20)->getSecret(); }
    public function uri(string $secret, string $username): string
    {
        $otp = TOTP::createFromSecret($secret);
        $otp->setIssuer('Howstack BOS');
        $otp->setLabel($username);
        return $otp->getProvisioningUri();
    }
    public function counter(string $secret, string $code, int $last, int $timestamp): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) return null;
        $otp = TOTP::createFromSecret($secret);
        $current = intdiv($timestamp, 30);
        for ($step = $current + 1; $step >= $current - 1; $step--) {
            if ($step > $last && hash_equals($otp->at($step * 30), $code)) return $step;
        }
        return null;
    }
}
