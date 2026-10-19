<?php

declare(strict_types=1);

namespace app\modules\support\contract;

/** 工单本人查询及事务内命令边界；客户身份只能由宿主提供。 */
interface TicketConversationRepositoryInterface
{
    /** @return list<array{id:int,name:string}> 仅启用类型 */
    public function types(): array;
    /**
     * @return array{data:list<array<string,mixed>>,total:int,page:int,page_size:int} 本人列表
     * @param int $memberId 服务端认证会员内部ID
     * @param string|null $status 既有状态筛选，NULL不筛选
     * @param int $page 已校验页码
     * @param int $size 每页10、20、50或100条
     */
    public function page(int $memberId, ?string $status, int $page, int $size): array;
    /**
     * @return array<string,mixed>|null 显式安全投影，含内部member_id供应用层使用
     * @param int $id 目标工单内部ID
     * @param int $memberId 服务端认证会员内部ID
     */
    public function ticket(int $id, int $memberId): ?array;
    /**
     * @return array{data:list<array{id:int,author_type:string,content:string,created_at:string}>,next_before_id:?int} 最近50条或更早窗口，窗口内正序
     * @param int $ticketId 目标工单内部ID，新建时为NULL
     * @param int|null $beforeId 更早记录的排他游标，NULL读取最近50条
     */
    public function messages(int $ticketId, ?int $beforeId): array;
    /**
     * @param array<string,int|string|null> $input 已验证并规范化的字段 @return array{ticket_id:int,message_id:?int,member_id:int,ticket_no:string,subject:string,replayed:bool} 同事务串行化状态及去重
     * @param string $actorType member会员或admin管理员
     * @param int $actorId 服务端认证操作人内部ID
     * @param string $action 固定create、reply、close动作
     * @param int|null $ticketId 目标工单内部ID，新建时为NULL
     * @param string $requestKey 同主体同内容重试保持不变的幂等键
     */
    public function command(string $actorType, int $actorId, string $action, ?int $ticketId, array $input, string $requestKey): array;
}
