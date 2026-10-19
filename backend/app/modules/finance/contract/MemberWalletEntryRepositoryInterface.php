<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

/** 会员账本只读投影；归属由服务端会话提供，不接受客户端账号或资金来源条件。 */
interface MemberWalletEntryRepositoryInterface
{
    /**
     * @param int $memberId 已认证会员内部主键
     * @param int $page 已验证页码
     * @param int $pageSize 已验证每页条数
     * @return array{data:list<array{entry_no:string,entry_type:string,amount_minor:int,available_after_minor:int,currency:string,business_type:string,business_no:?string,created_at:string}>,total:int,page:int,page_size:int} 安全公开投影
     */
    public function paginate(int $memberId, int $page, int $pageSize): array;
}
