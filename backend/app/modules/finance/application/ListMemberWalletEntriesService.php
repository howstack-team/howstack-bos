<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\modules\finance\contract\MemberWalletEntryRepositoryInterface;

/** 只查询本人资金明细，不创建资金账户、不补记或修改历史流水。 */
final readonly class ListMemberWalletEntriesService
{
    /** @param MemberWalletEntryRepositoryInterface $entries 只读账本投影 */
    public function __construct(private MemberWalletEntryRepositoryInterface $entries)
    {
    }

    /**
     * @param int $memberId 服务端已认证会员
     * @param int $page 已验证页码
     * @param int $pageSize 已验证每页条数
     * @return array{data:list<array{entry_no:string,entry_type:string,amount_minor:int,available_after_minor:int,currency:string,business_type:string,business_no:?string,created_at:string}>,total:int,page:int,page_size:int} 本人明细及分页
     */
    public function execute(int $memberId, int $page, int $pageSize): array
    {
        return $this->entries->paginate($memberId, $page, $pageSize);
    }
}
