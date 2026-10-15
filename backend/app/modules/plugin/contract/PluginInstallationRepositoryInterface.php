<?php

declare(strict_types=1);

namespace app\modules\plugin\contract;

/** 插件安装记录与生命周期；不保存连接级凭据。 */
interface PluginInstallationRepositoryInterface
{
    /** @return array<string, array<string, mixed>> 以插件 ID 索引的受控安装记录 */
    public function all(): array;

    /**
     * 安装、升级或转换状态，事务内校验引用。
     * @param array<string, mixed> $package 已校验的受信包资料
     * @param string $action 固定生命周期动作
     * @param int $revision 乐观锁版本，初次安装为 0
     * @param int|null $actorId 操作管理员，CLI 为 null
     * @param array{client_ip: string, client_ip_location: string}|null $origin HTTP 操作来源，CLI 为 null
     * @return array<string, mixed> 新安装记录
     */
    public function transition(array $package, string $action, int $revision, ?int $actorId, ?array $origin = null): array;

    /**
     * 在供应写事务中锁定安装记录，防止停用与新建连接竞态。
     * @param string $id 插件身份
     * @return void
     */
    public function lockAccepting(string $id): void;
}
