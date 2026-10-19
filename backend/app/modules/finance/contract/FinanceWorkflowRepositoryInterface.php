<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

/** 财务单据持久化。只写财务表，订单写入使用订单公开契约。 */
interface FinanceWorkflowRepositoryInterface
{
    public function begin(string $key, string $hash, int $actorId, string $resource, string $action, array $audit = []): ?array;
    public function finish(string $key, array $result): void;
    public function members(string $search): array;
    public function managed(string $resource, int $id): bool;
    public function invoiceIntegrity(int $orderId): bool;
    public function memberExists(int $memberId): bool;
    public function create(string $resource, array $values): int;
    public function lock(string $resource, int $id): array;
    public function update(string $resource, int $id, array $values): void;
    public function paymentForOrder(int $orderId): array;
    public function invoiceOccupations(array $unitIds): array;
    public function invoiceOccupied(int $unitId): int;
    public function allocateInvoice(int $invoiceId, int $unitId, int $amount): void;
    public function restoreConsumption(int $orderId, int $accountId, int $amount): void;
    public function lockLot(int $rechargeId, int $accountId): array;
    public function changeLot(int $lotId, int $remaining): void;
    public function changeAccount(array $account, int $available, int $frozen): void;
    public function withdrawalFrozen(int $id, int $accountId, int $amount): bool;
    public function invoiceAllocated(int $invoiceId): int;
    public function entry(array $account, string $type, string $source, int $sourceId, int $amount, int $balance): void;
    /** @return list<array<string, mixed>> 受控的会员业务来源选项 */
    public function options(string $resource, int $memberId): array;
}
