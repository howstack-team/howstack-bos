<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

use app\modules\finance\domain\WalletOrderPayment;

/** 资金数据锁定与持久化；不读取、修改订单模块的数据表。 */
interface WalletLedgerRepositoryInterface
{
    /** @return array{currency: string, available_minor: int, frozen_minor: int} */
    public function balance(int $memberId): array;

    /** @return array{id: int, member_id: int, amount_minor: int, currency: string, channel: string, status: string}|null */
    public function lockRecharge(int $rechargeId): ?array;

    /** @return list<array{member_id: int, amount_minor: int, currency: string, channel: string}> */
    public function confirmedRechargePayments(int $rechargeId): array;

    /** @return array{id: int, member_id: int, available_minor: int, version: int} */
    public function lockAccount(int $memberId, string $currency): array;

    /** @return array{member_id: int, amount_minor: int, currency: string, status: string}|null */
    public function orderWalletPayment(int $orderId): ?array;

    /** @return list<array{id: int, remaining_minor: int}> FIFO 来源批次 */
    public function lockFundingLots(int $accountId): array;

    /** @param array{id: int, member_id: int, available_minor: int, version: int} $account 已锁定账户 */
    public function recordCredit(array $account, int $rechargeId, int $amountMinor, int $balance): void;

    /**
     * @param array{id: int, member_id: int, available_minor: int, version: int} $account 已锁定账户
     * @param list<array{id: int, remaining_minor: int, consumed_minor: int}> $allocations 已计算的来源扣减
     */
    public function recordDebit(array $account, WalletOrderPayment $payment, array $allocations, int $balance): void;
}
