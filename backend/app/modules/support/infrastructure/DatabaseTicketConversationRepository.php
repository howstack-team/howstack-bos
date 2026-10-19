<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure;

use app\exception\BusinessException;
use app\modules\support\contract\TicketConversationRepositoryInterface;
use app\modules\support\domain\TicketState;
use app\modules\support\infrastructure\model\{TicketModel, TicketMessageModel, TicketCommandModel, TicketTypeModel};
use app\shared\http\ApiErrorCode;

/** ORM查询显式投影；命令与会话、状态在调用方事务中提交。 */
final class DatabaseTicketConversationRepository implements TicketConversationRepositoryInterface
{
    /**
     * 读取可提交的启用类型。
     * @return array<string,mixed> 已校验结果或统一响应
     */
    public function types(): array
    {
        return TicketTypeModel::query()->where('status', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
            ->map(static fn ($row): array => ['id' => $row->id, 'name' => $row->name])->all();
    }

    /**
     * 读取本人有界工单分页。
     * @param int $memberId 服务端认证会员内部ID
     * @param string|null $status 既有状态筛选，NULL不筛选
     * @param int $page 已校验页码
     * @param int $size 每页10、20、50或100条
     * @return array<string,mixed> 已校验结果或统一响应
     */
    public function page(int $memberId, ?string $status, int $page, int $size): array
    {
        $query = TicketModel::query()->where('tickets.member_id', $memberId);
        if ($status !== null) $query->where('tickets.status', $status);
        $total = (clone $query)->count();
        $rows = $query->leftJoin('ticket_types as type', 'type.id', '=', 'tickets.type_id')
            ->orderByDesc('tickets.updated_at')->orderByDesc('tickets.id')->forPage($page, $size)
            ->get(['tickets.id', 'tickets.ticket_no', 'tickets.type_id', 'type.name as type_name', 'tickets.subject', 'tickets.priority', 'tickets.status', 'tickets.created_at', 'tickets.updated_at', 'tickets.closed_at']);
        return ['data' => $rows->map(static fn ($row): array => self::summary($row))->all(), 'total' => (int) $total, 'page' => $page, 'page_size' => $size];
    }

    /**
     * 读取限定本人归属的工单。
     * @param int $id 目标工单内部ID
     * @param int $memberId 服务端认证会员内部ID
     * @return array|null 已校验结果或统一响应
     */
    public function ticket(int $id, int $memberId): ?array
    {
        $row = TicketModel::query()->where('tickets.id', $id)->where('tickets.member_id', $memberId)
            ->leftJoin('ticket_types as type', 'type.id', '=', 'tickets.type_id')
            ->leftJoin('service_instances as service', static fn ($join) => $join->on('service.id', '=', 'tickets.service_id')->on('service.member_id', '=', 'tickets.member_id'))
            ->first(['tickets.id', 'tickets.member_id', 'tickets.ticket_no', 'tickets.type_id', 'type.name as type_name', 'tickets.subject', 'tickets.content', 'tickets.priority', 'tickets.status', 'tickets.created_at', 'tickets.updated_at', 'tickets.closed_at', 'service.id as related_id', 'service.instance_no as related_no', 'service.display_name as related_name']);
        if (!$row) return null;
        return self::summary($row) + ['member_id' => $row->member_id, 'content' => $row->content,
            'service' => $row->related_id ? ['id' => (int) $row->related_id, 'instance_no' => $row->related_no, 'display_name' => $row->related_name] : null];
    }

    /**
     * 读取最多50条会话窗口。
     * @param int $ticketId 目标工单内部ID，新建时为NULL
     * @param int|null $beforeId 更早记录的排他游标，NULL读取最近50条
     * @return array<string,mixed> 已校验结果或统一响应
     */
    public function messages(int $ticketId, ?int $beforeId): array
    {
        $query = TicketMessageModel::query()->where('ticket_id', $ticketId);
        if ($beforeId !== null) $query->where('id', '<', $beforeId);
        $rows = $query->orderByDesc('id')->limit(51)->get(['id', 'author_type', 'content', 'created_at']);
        $more = $rows->count() > 50;
        $rows = $rows->take(50)->reverse()->values();
        return ['data' => $rows->map(static fn ($row): array => ['id' => $row->id, 'author_type' => $row->author_type, 'content' => $row->content,
            'created_at' => (string) $row->getRawOriginal('created_at')])->all(), 'next_before_id' => $more ? (int) $rows->first()->id : null];
    }

    /**
     * 在当前事务中串行化命令并保存幂等结果。
     * @param string $actorType member会员或admin管理员
     * @param int $actorId 服务端认证操作人内部ID
     * @param string $action 固定create、reply、close动作
     * @param int|null $ticketId 目标工单内部ID，新建时为NULL
     * @param array<string,mixed> $input 已校验并规范化的业务字段
     * @param string $requestKey 同主体同内容重试保持不变的幂等键
     * @return array<string,mixed> 已校验结果或统一响应
     */
    public function command(string $actorType, int $actorId, string $action, ?int $ticketId, array $input, string $requestKey): array
    {
        if (TicketModel::query()->getConnection()->transactionLevel() < 1) throw new \LogicException('工单命令必须在业务事务中提交');
        $scope = ['actor_type' => $actorType, 'actor_id' => $actorId, 'request_key' => $requestKey];
        $hash = hash('sha256', json_encode([$action, $ticketId, $input], JSON_THROW_ON_ERROR));
        $now = date('Y-m-d H:i:s');
        $inserted = TicketCommandModel::query()->insertOrIgnore($scope + ['action' => $action, 'payload_hash' => $hash, 'created_at' => $now]);
        $command = TicketCommandModel::query()->where($scope)->lockForUpdate()->firstOrFail();
        if (!hash_equals($command->payload_hash, $hash)) throw new BusinessException('此提交标识已用于其他内容，请重新提交', ApiErrorCode::CONFLICT, 409);
        if (!$inserted) {
            $ticket = TicketModel::query()->findOrFail($command->ticket_id);
            return self::result($ticket, $command->message_id, true);
        }
        $messageId = null;
        if ($action === 'create') {
            $type = TicketTypeModel::query()->whereKey($input['type_id'])->lockForUpdate()->first();
            if (!$type || $type->status !== 1) throw new BusinessException('所选工单类型不可用，请重新选择', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['type_id' => ['所选工单类型不可用']]]);
            $ticket = TicketModel::query()->create(['ticket_no' => 'TK' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6))),
                'member_id' => $actorId, 'type_id' => $input['type_id'], 'service_id' => $input['service_id'], 'subject' => $input['subject'], 'content' => $input['content'],
                'priority' => 'normal', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $query = TicketModel::query()->whereKey($ticketId);
            if ($actorType === 'member') $query->where('member_id', $actorId);
            $ticket = $query->lockForUpdate()->first();
            if (!$ticket) throw new BusinessException('工单不存在', ApiErrorCode::NOT_FOUND, 404);
            if ($action === 'reply') {
                $status = TicketState::afterReply($ticket->status, $actorType);
                $message = TicketMessageModel::query()->create(['ticket_id' => $ticket->id, 'author_type' => $actorType, 'author_id' => $actorId,
                    'content' => $input['content'], 'created_at' => $now]);
                $messageId = (int) $message->id;
                $ticket->fill(['status' => $status, 'updated_at' => $now])->save();
            } elseif ($action === 'close') {
                if ($ticket->status !== 'closed') $ticket->fill(['status' => 'closed', 'closed_at' => $now, 'updated_at' => $now])->save();
            } else throw new \LogicException('工单动作无效');
        }
        $command->fill(['ticket_id' => $ticket->id, 'message_id' => $messageId])->save();
        return self::result($ticket, $messageId, false);
    }

    /**
     * 将工单转换为显式输出白名单。
     * @param TicketModel $row 显式选列的工单持久化投影
     * @return array<string,mixed> 已校验结果或统一响应
     */
    private static function summary(TicketModel $row): array
    {
        return ['id' => $row->id, 'ticket_no' => $row->ticket_no, 'type_id' => $row->type_id, 'type_name' => $row->type_name,
            'subject' => $row->subject, 'priority' => $row->priority, 'status' => $row->status,
            'created_at' => (string) $row->getRawOriginal('created_at'), 'updated_at' => (string) $row->getRawOriginal('updated_at'),
            'closed_at' => $row->getRawOriginal('closed_at')];
    }

    /**
     * 构造事务内命令结果，不输出内容摘要。
     * @param TicketModel $ticket 已锁定的工单模型
     * @param int|null $messageId 回复消息ID，非回复动作可为NULL
     * @param bool $replayed 是否返回此前已提交结果
     * @return array<string,mixed> 已校验结果或统一响应
     */
    private static function result(TicketModel $ticket, ?int $messageId, bool $replayed): array
    {
        return ['ticket_id' => $ticket->id, 'message_id' => $messageId, 'member_id' => $ticket->member_id,
            'ticket_no' => $ticket->ticket_no, 'subject' => $ticket->subject, 'replayed' => $replayed];
    }
}
