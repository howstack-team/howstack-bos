<?php

declare(strict_types=1);

namespace app\modules\system_settings\contract;

/** 校验新后台入口不能占用官网、业务路由及公开资源。 */
interface AdminEntryPathPolicyInterface
{
    public function check(string $path): void;
}
