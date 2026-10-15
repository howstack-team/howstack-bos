<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

interface OnlinePaymentRepositoryInterface
{
    /** @return array<string, mixed>|null 私有执行快照 */
    public function request(int $memberId, string $key): ?array;
    /** @param array<string, mixed> $values @param array<string, mixed> $snapshot @return array<string, mixed> 同键返回原交易 */
    public function reserve(array $values, array $snapshot): array;
    /** @return array<string, mixed>|null */
    public function find(int $paymentId): ?array;
    /** @return array<string, mixed>|null */
    public function recharge(int $memberId, int $rechargeId): ?array;
    /** @return array<string, mixed>|null */
    public function notification(string $token): ?array;
    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function listing(int $memberId, int $page, int $size): array;
    /** @return array<string, mixed>|null 原子租约，网络请求不在事务内 */
    public function claim(int $paymentId, bool $create, bool $automatic = false): ?array;
    /** @return array<string, mixed> 调用方持有事务，固定充值、支付、执行快照锁顺序 */
    public function lock(int $paymentId): array;
    /** @param array<string, mixed> $attempt */
    public function update(array $attempt, string $status, ?string $tradeNo, ?string $qrCode, ?string $error): void;
    /** @param array<string, mixed> $attempt @param array{id: string, content_hash: string, client_ip: string, client_ip_location: string} $notification */
    public function recordNotification(array $attempt, array $notification): void;
    /** @return list<int> */
    public function due(int $limit): array;
}
