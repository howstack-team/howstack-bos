<?php

declare(strict_types=1);

namespace app\modules\website\contract;

/** 将静态主题页面与宿主已注册路由及动态后台入口隔离。 */
interface WebsitePathPolicyInterface
{
    /** @param array<string, mixed> $package 主题清单 */
    public function check(array $package): void;
}
