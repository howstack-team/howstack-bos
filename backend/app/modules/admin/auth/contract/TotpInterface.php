<?php

declare(strict_types=1);
namespace app\modules\admin\auth\contract;
/** TOTP算法边界；六位SHA1、30秒时间步、允许前后一步时钟偏差。 */
interface TotpInterface
{
    public function generate(): string;
    public function uri(string $secret, string $username): string;
    public function counter(string $secret, string $code, int $last, int $timestamp): ?int;
}
