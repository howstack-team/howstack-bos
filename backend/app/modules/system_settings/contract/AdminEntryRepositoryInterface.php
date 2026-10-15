<?php

declare(strict_types=1);

namespace app\modules\system_settings\contract;

/** 后台入口沿用安装配置；常驻进程实时读取，写入采用文件锁及原子替换。 */
interface AdminEntryRepositoryInterface
{
    public function adminPath(): string;

    /** 比较当前入口后更新；冲突或写入失败时保留原配置。 */
    public function changeAdminPath(string $expected, string $path): void;
}
