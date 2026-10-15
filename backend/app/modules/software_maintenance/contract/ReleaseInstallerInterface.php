<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\contract;

/** 下载已完成且清单已重新验证后，执行固定发布包。 */
interface ReleaseInstallerInterface
{
    /**
     * @param string $directory 固定私有任务目录
     * @return void 下载前准备
     */
    public function prepareDirectory(string $directory): void;

    /**
     * @param array<string,mixed> $job 持久任务
     * @param array<string,mixed> $manifest 已验签元数据
     * @param string $archive 私有ZIP
     * @param callable(string,string):void $checkpoint 可靠阶段记录
     * @return void 完成文件和数据库更新，等待完整进程重启
     */
    public function install(array $job, array $manifest, string $archive, callable $checkpoint): void;

    /** @return void 使用固定启动命令完整重启Webman，不接受远程脚本 */
    public function restart(): void;

    /**
     * @param array<string,mixed> $job 等待重启的任务
     * @return bool 新进程核实代码和数据库状态后结束维护
     */
    public function confirm(array $job): bool;
}
