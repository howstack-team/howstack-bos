<?php

declare(strict_types=1);

namespace app\modules\message\contract;

interface MessageRecipientDirectoryInterface
{
    /** @return list<array{id:int, name:string, email:string, mobile:string}> 只读取启用接收人，不修改跨模块表 */
    public function recipients(string $audience, int $memberId, array $policy): array;
    public function active(string $audience, int $accountId): bool;
    /** @return array<string, list<array{id:int,name:string}>> 仅给消息配置展示名称和主键 */
    public function options(): array;
}
