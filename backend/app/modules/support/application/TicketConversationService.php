<?php

declare(strict_types=1);

namespace app\modules\support\application;

use app\exception\BusinessException;
use app\modules\support\contract\TicketConversationRepositoryInterface;
use app\modules\service_instance\contract\MemberServiceRepositoryInterface;
use app\modules\message\contract\MessagePublisherInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 本人读写与后台沟通共用事务、状态和幂等；HTTP身份与RBAC由入口门面提供。 */
final readonly class TicketConversationService
{
    /**
     * 注入工单用例依赖。
     * @param TicketConversationRepositoryInterface $tickets 显式注入的业务依赖
     * @param MemberServiceRepositoryInterface $services 显式注入的业务依赖
     * @param TransactionManagerInterface $transactions 显式注入的业务依赖
     * @param MessagePublisherInterface $notifications 显式注入的业务依赖
     */
    public function __construct(private TicketConversationRepositoryInterface $tickets, private MemberServiceRepositoryInterface $services,
        private TransactionManagerInterface $transactions, private MessagePublisherInterface $notifications)
    {
    }

    /** @return list<array{id:int,name:string}> 可提交类型 */
    public function types(): array { return $this->tickets->types(); }

    /**
     * @return array<string,mixed> 本人分页
     * @param int $memberId 服务端认证会员内部ID
     * @param string|null $status 既有状态筛选，NULL不筛选
     * @param int $page 已校验页码
     * @param int $size 每页10、20、50或100条
     */
    public function page(int $memberId, ?string $status, int $page, int $size): array { return $this->tickets->page($memberId, $status, $page, $size); }

    /**
     * @return array{ticket:array<string,mixed>,messages:array,next_before_id:?int} 本人安全投影
     * @param int $memberId 服务端认证会员内部ID
     * @param int $id 目标工单内部ID
     */
    public function detail(int $memberId, int $id): array
    {
        $ticket = $this->owned($memberId, $id);
        unset($ticket['member_id']);
        $window = $this->tickets->messages($id, null);
        return ['ticket' => $ticket, 'messages' => $window['data'], 'next_before_id' => $window['next_before_id']];
    }

    /**
     * @return array{data:array,next_before_id:?int} 归属先于游标读取
     * @param int $memberId 服务端认证会员内部ID
     * @param int $id 目标工单内部ID
     * @param int|null $before 更早记录的排他游标，NULL读取最近50条
     */
    public function memberMessages(int $memberId, int $id, ?int $before): array
    {
        $this->owned($memberId, $id);
        return $this->tickets->messages($id, $before);
    }

    /**
     * @return array{data:array,next_before_id:?int} 调用方已验证后台查看权限和目标存在
     * @param int $id 目标工单内部ID
     * @param int|null $before 更早记录的排他游标，NULL读取最近50条
     */
    public function adminMessages(int $id, ?int $before = null): array { return $this->tickets->messages($id, $before); }

    /**
     * @param array{type_id:int,service_id:?int,subject:string,content:string,request_key:string} $input 已校验输入 @return array{ticket_id:int,message_id:?int} 可复用结果
     * @param int $memberId 服务端认证会员内部ID
     */
    public function create(int $memberId, array $input): array
    {
        if ($input['service_id'] !== null && $this->services->find($memberId, $input['service_id']) === null) {
            throw new BusinessException('关联的服务不存在或不属于本人', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['service_id' => ['请选择本人的云服务器']]]);
        }
        $key = $input['request_key']; unset($input['request_key']);
        return $this->execute('member', $memberId, 'create', null, $input, $key);
    }

    /**
     * @param array<string,int|string|null> $input 规范化请求 @return array{ticket_id:int,message_id:?int} 幂等结果
     * @param string $actorType member会员或admin管理员
     * @param int $actorId 服务端认证操作人内部ID
     * @param string $action 固定create、reply、close动作
     * @param int|null $id 目标工单内部ID
     * @param string $key 客户端幂等键
     */
    public function execute(string $actorType, int $actorId, string $action, ?int $id, array $input, string $key): array
    {
        if (!in_array($actorType, ['member', 'admin'], true) || !in_array($action, ['create', 'reply', 'close'], true) || $actorId < 1
            || ($action === 'create' && $actorType !== 'member')) throw new \LogicException('工单命令主体或动作无效');
        return $this->transactions->run(function () use ($actorType, $actorId, $action, $id, $input, $key): array {
            $result = $this->tickets->command($actorType, $actorId, $action, $id, $input, $key);
            if (!$result['replayed'] && $action === 'reply' && $actorType === 'admin') {
                // 仅写事务Outbox；投递失败不会回滚已提交会话。站内提醒是此闭环必备能力。
                $this->notifications->publish('ticket.reply.' . $result['message_id'], 'ticket_replied', 'ticket', $result['ticket_id'], $result['member_id'],
                    ['site_name' => '云业务运营系统', 'ticket_no' => $result['ticket_no'], 'ticket_title' => $result['subject']], ['customer'], true);
            }
            return ['ticket_id' => $result['ticket_id'], 'message_id' => $result['message_id']];
        });
    }

    /**
     * 确认工单属于当前会员。
     * @param int $memberId 服务端认证会员内部ID
     * @param int $id 目标工单内部ID
     * @return array<string,mixed> 已校验结果或统一响应
     */
    private function owned(int $memberId, int $id): array
    {
        return $this->tickets->ticket($id, $memberId) ?? throw new BusinessException('工单不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
