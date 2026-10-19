<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\modules\finance\contract\MemberWalletEntryRepositoryInterface;
use app\modules\finance\infrastructure\model\WalletEntryModel;
use Illuminate\Database\Query\JoinClause;

/** 用 ORM 显式投影不可变账本；来源仅补充本人业务单号，不返回内部标识或私有财务资料。 */
final class DatabaseMemberWalletEntryRepository implements MemberWalletEntryRepositoryInterface
{
    /** @inheritDoc */
    public function paginate(int $memberId, int $page, int $pageSize): array
    {
        $query = WalletEntryModel::query()->where('wallet_entries.member_id', $memberId)
            ->join('wallet_accounts as account', static function (JoinClause $join) use ($memberId): void {
                $join->on('account.id', '=', 'wallet_entries.account_id')->where('account.member_id', $memberId)
                    ->where('account.currency', 'CNY')->where('account.fund_type', 'recharge_cash');
            });
        $total = (int) (clone $query)->count();
        foreach (['recharge' => ['recharges', 'recharge_no'], 'order' => ['orders', 'order_no'],
            'refund' => ['refund_requests', 'refund_no'], 'withdrawal' => ['withdrawal_requests', 'withdrawal_no']] as $type => [$table, $number]) {
            $query->leftJoin($table . ' as source_' . $type, static function (JoinClause $join) use ($memberId, $type): void {
                $join->on('source_' . $type . '.id', '=', 'wallet_entries.source_id')
                    ->where('wallet_entries.source_type', $type)->where('source_' . $type . '.member_id', $memberId);
            });
            $query->addSelect('source_' . $type . '.' . $number . ' as ' . $type . '_number');
        }
        $rows = $query->addSelect(['wallet_entries.entry_no', 'wallet_entries.entry_type', 'wallet_entries.source_type',
            'wallet_entries.amount_minor', 'wallet_entries.available_after_minor', 'wallet_entries.created_at'])
            ->orderByDesc('wallet_entries.created_at')->orderByDesc('wallet_entries.id')->forPage($page, $pageSize)->get();
        $data = $rows->map(static function (WalletEntryModel $row): array {
            $type = in_array($row->source_type, ['recharge', 'order', 'refund', 'withdrawal'], true) ? $row->source_type : 'other';
            $number = $type === 'other' ? null : $row->getAttribute($type . '_number');
            return [
                'entry_no' => $row->entry_no, 'entry_type' => $row->entry_type, 'amount_minor' => $row->amount_minor,
                'available_after_minor' => $row->available_after_minor, 'currency' => 'CNY', 'business_type' => $type,
                'business_no' => is_string($number) && trim($number) !== '' ? $number : null,
                'created_at' => (string) $row->getRawOriginal('created_at'),
            ];
        })->all();
        return ['data' => $data, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }
}
