<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure;

use app\exception\BusinessException;
use app\modules\message\contract\MessageRepositoryInterface;
use app\modules\message\domain\MessageScene;
use app\modules\message\infrastructure\model\MessageModel;
use app\modules\message\infrastructure\model\MessageRecipientModel;
use app\modules\message\infrastructure\model\MessageDeliveryModel;
use app\modules\message\infrastructure\model\MessageDeliveryAttemptModel;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;
use support\Db;
use LogicException;

/** 所有消息写入只发生在本模块；数据库任务不依赖Redis的可用性。 */
final readonly class DatabaseMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private SensitiveDataCipherInterface $cipher)
    {
    }

    public function record(string $eventKey, string $scene, string $businessType, int $businessId, array $targets): int
    {
        if (Db::connection()->transactionLevel() < 1) {
            throw new LogicException('消息任务必须与业务事务一起记录');
        }
        $now = date('Y-m-d H:i:s');
        $inserted = Db::table('messages')->insertOrIgnore(['event_key' => $eventKey, 'scene' => $scene,
            'purpose' => MessageScene::metadata($scene)['purpose'], 'business_type' => $businessType,
            'business_id' => $businessId, 'created_at' => $now]);
        $message = MessageModel::query()->where('event_key', $eventKey)->firstOrFail();
        if (!$inserted) {
            return $message->id;
        }
        foreach ($targets as $target) {
            $recipient = MessageRecipientModel::query()->firstOrCreate(['message_id' => $message->id,
                'audience' => $target['audience'], 'target_key' => $target['target_key']], ['account_id' => $target['account_id'],
                    'group_key' => $target['group_key'] ?? 'legacy', 'group_name' => $target['group_name'] ?? '']);
            $hex = hash('sha256', $eventKey . ':' . $recipient->id . ':' . $target['channel']);
            $requestKey = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
            MessageDeliveryModel::query()->create(['recipient_id' => $recipient->id, 'request_key' => $requestKey,
                'channel' => $target['channel'], 'channel_name' => $target['channel_name'] ?? '',
                'payload_encrypted' => $this->cipher->encrypt(json_encode($target['payload'], JSON_THROW_ON_ERROR)),
                'next_attempt_at' => $now, 'queue_after' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        return $message->id;
    }

    public function claim(int $id): ?array
    {
        return Db::transaction(function () use ($id): ?array {
            $row = MessageDeliveryModel::query()->whereKey($id)->lockForUpdate()->first();
            $now = date('Y-m-d H:i:s');
            if ($row === null || $row->status !== 'pending' || $row->next_attempt_at > $now) {
                return null;
            }
            $lease = bin2hex(random_bytes(24));
            $row->fill(['status' => 'processing', 'lease_key' => $lease, 'lease_until' => date('Y-m-d H:i:s', time() + 120),
                'attempts' => $row->attempts + 1, 'updated_at' => $now])->save();
            MessageDeliveryAttemptModel::query()->create(['delivery_id' => $id, 'attempt_no' => $row->attempts,
                'status' => 'processing', 'created_at' => $now]);
            $recipient = MessageRecipientModel::query()->findOrFail($row->recipient_id);
            return ['audience' => $recipient->audience, 'account_id' => $recipient->account_id, 'id' => $id, 'channel' => $row->channel, 'request_key' => $row->request_key, 'lease_key' => $lease,
                'payload' => json_decode($this->cipher->decrypt($row->payload_encrypted), true, 32, JSON_THROW_ON_ERROR)];
        });
    }

    public function finish(int $id, string $lease, string $status, string $error, int $duration, bool $retry): void
    {
        if (!in_array($status, ['accepted', 'delivered', 'failed', 'unknown'], true)
            || !preg_match('/^[a-z0-9_]{0,64}$/D', $error)) {
            throw new LogicException('消息传输结果无效');
        }
        Db::transaction(function () use ($id, $lease, $status, $error, $duration, $retry): void {
            $row = MessageDeliveryModel::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'processing' || !hash_equals($row->lease_key, $lease)) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            MessageDeliveryAttemptModel::query()->where('delivery_id', $id)->where('attempt_no', $row->attempts)
                ->update(['status' => $status, 'error_code' => $error, 'duration_ms' => $duration, 'finished_at' => $now]);
            $again = $status === 'failed' && $retry && $row->attempts < 3;
            $row->fill(['status' => $again ? 'pending' : $status, 'error_code' => $error, 'lease_key' => '', 'lease_until' => null,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + 30 * (2 ** min($row->attempts, 6))),
                'queue_after' => $now, 'updated_at' => $now])->save();
        });
    }

    public function due(int $limit): array
    {
        $now = date('Y-m-d H:i:s');
        return MessageDeliveryModel::query()->where('status', 'pending')->where('next_attempt_at', '<=', $now)
            ->where('queue_after', '<=', $now)->orderBy('id')->limit($limit)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function queued(int $id): void
    {
        MessageDeliveryModel::query()->whereKey($id)->where('status', 'pending')
            ->update(['queue_after' => date('Y-m-d H:i:s', time() + 60)]);
    }

    public function recoverExpired(): void
    {
        $ids = MessageDeliveryModel::query()->where('status', 'processing')->where('lease_until', '<', date('Y-m-d H:i:s'))
            ->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            Db::transaction(function () use ($id): void {
                $row = MessageDeliveryModel::query()->whereKey($id)->lockForUpdate()->first();
                if ($row === null || $row->status !== 'processing' || $row->lease_until >= date('Y-m-d H:i:s')) {
                    return;
                }
                $now = date('Y-m-d H:i:s');
                $status = $row->channel === 'message' ? 'failed' : 'unknown';
                MessageDeliveryAttemptModel::query()->where('delivery_id', $id)->where('attempt_no', $row->attempts)
                    ->update(['status' => $status, 'error_code' => 'worker_lease_expired', 'finished_at' => $now]);
                $row->fill(['status' => $row->channel === 'message' ? 'pending' : 'unknown',
                    'error_code' => 'worker_lease_expired', 'lease_key' => '', 'lease_until' => null, 'queue_after' => $now,
                    'next_attempt_at' => $now, 'updated_at' => $now])->save();
            });
        }
    }

    private function inboxQuery(string $audience, int $accountId): \Illuminate\Database\Query\Builder
    {
        return Db::table('message_recipients as r')->join('messages as m', 'r.message_id', '=', 'm.id')
            ->join('message_deliveries as d', 'r.id', '=', 'd.recipient_id')
            ->where('r.audience', $audience)->where('r.account_id', $accountId)
            ->where('d.channel', 'message')->where('d.status', 'delivered');
    }

    public function inbox(string $audience, int $accountId, array $query): array
    {
        $scenes = [];
        foreach (array_keys(\app\modules\message_configuration\domain\MessageTypeCatalog::all($audience)) as $scene) {
            $meta = MessageScene::metadata($scene);
            if (($query['category'] ?? '') !== '' && $meta['category'] !== $query['category']) {
                continue;
            }
            if (($query['topic'] ?? '') !== '' && $meta['topic'] !== $query['topic']) {
                continue;
            }
            $scenes[] = $scene;
        }
        $builder = $this->inboxQuery($audience, $accountId)->whereIn('m.scene', $scenes);
        $total = $builder->count();
        $rows = $builder->orderByDesc('r.id')->offset(($query['page'] - 1) * $query['page_size'])->limit($query['page_size'])
            ->get(['r.id', 'r.read_at', 'm.scene', 'm.created_at', 'm.business_type', 'm.business_id', 'd.payload_encrypted']);
        $data = [];
        foreach ($rows as $row) {
            $payload = json_decode($this->cipher->decrypt($row->payload_encrypted), true, 32, JSON_THROW_ON_ERROR);
            $data[] = ['id' => (int) $row->id, 'title' => $payload['title'], 'body' => $payload['body'], 'unread' => $row->read_at === null,
                'created_at' => $row->created_at, 'business_type' => $row->business_type, 'business_id' => (int) $row->business_id]
                + MessageScene::metadata($row->scene);
        }
        return ['data' => $data, 'total' => $total];
    }

    public function summary(string $audience, int $accountId): array
    {
        $groups = $this->inboxQuery($audience, $accountId)->whereNull('r.read_at')->groupBy('m.scene')
            ->select('m.scene')->selectRaw('count(*) as total')->get();
        $total = 0;
        $categories = [];
        $topics = [];
        foreach ($groups as $group) {
            $meta = MessageScene::metadata($group->scene);
            $count = (int) $group->total;
            $total += $count;
            $categories[$meta['category']] = ($categories[$meta['category']] ?? 0) + $count;
            $topics[$meta['topic']] = ($topics[$meta['topic']] ?? 0) + $count;
        }
        return ['unread' => $total, 'categories' => (object) $categories, 'topics' => (object) $topics];
    }

    public function read(string $audience, int $accountId, ?int $recipientId): void
    {
        $exists = $recipientId === null || $this->inboxQuery($audience, $accountId)->where('r.id', $recipientId)->exists();
        if (!$exists) {
            throw new BusinessException('消息不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        // 直接使用相同所有权条件更新，避免无界pluck与超大IN查询。
        $query = MessageRecipientModel::query()->where('audience', $audience)->where('account_id', $accountId)->whereNull('read_at')
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('message_deliveries')->whereColumn('recipient_id', 'message_recipients.id')
                ->where('channel', 'message')->where('status', 'delivered'));
        if ($recipientId !== null) {
            $query->whereKey($recipientId);
        }
        $query->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function logs(array $query): array
    {
        $builder = MessageModel::query();
        foreach (['scene', 'business_type', 'purpose'] as $field) {
            if (($query[$field] ?? '') !== '') {
                $builder->where($field, $query[$field]);
            }
        }
        if (($query['business_id'] ?? '') !== '') {
            $builder->where('business_id', $query['business_id']);
        }
        if (($query['created_from'] ?? '') !== '') {
            $builder->where('created_at', '>=', $query['created_from'] . ' 00:00:00');
        }
        if (($query['created_to'] ?? '') !== '') {
            $end = new \DateTimeImmutable($query['created_to']);
            $builder->where('created_at', '<', $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00');
        }
        if (($query['status'] ?? '') !== '') {
            // 完整数据集先聚合再筛选；CASE 顺序与 MessageOutcome 一致，不能只筛当前页。
            $grammar = Db::connection()->getQueryGrammar();
            $status = $grammar->wrap('outcome_deliveries.status');
            $count = 'COUNT(' . $grammar->wrap('outcome_deliveries.id') . ')';
            $sum = static fn (string $value): string => "SUM(CASE WHEN {$status} = '{$value}' THEN 1 ELSE 0 END)";
            $completed = '(' . $sum('accepted') . ' + ' . $sum('delivered') . ')';
            $expression = "CASE WHEN {$count} = 0 THEN 'inactive'"
                . ' WHEN ' . $sum('unknown') . " > 0 THEN 'unknown'"
                . ' WHEN ' . $sum('processing') . " > 0 THEN 'processing'"
                . ' WHEN ' . $sum('pending') . " > 0 THEN 'pending'"
                . ' WHEN ' . $sum('delivered') . " = {$count} THEN 'delivered'"
                . " WHEN {$completed} = {$count} THEN 'accepted'"
                . " WHEN {$completed} > 0 THEN 'partial' ELSE 'failed' END";
            $outcomes = Db::table('messages as outcome_messages')
                ->leftJoin('message_recipients as outcome_recipients', 'outcome_recipients.message_id', '=', 'outcome_messages.id')
                ->leftJoin('message_deliveries as outcome_deliveries', 'outcome_deliveries.recipient_id', '=', 'outcome_recipients.id')
                ->select('outcome_messages.id')->groupBy('outcome_messages.id')
                ->havingRaw($expression . ' = ?', [$query['status']]);
            $builder->whereIn('id', $outcomes);
        }
        if (($query['channel'] ?? '') !== '' || ($query['delivery_status'] ?? '') !== '' || ($query['audience'] ?? '') !== '') {
            $builder->whereExists(static function ($q) use ($query): void {
                $q->selectRaw('1')->from('message_recipients as r')->join('message_deliveries as d', 'd.recipient_id', '=', 'r.id')
                    ->whereColumn('r.message_id', 'messages.id');
                if (($query['channel'] ?? '') !== '') $q->where('d.channel', $query['channel']);
                if (($query['delivery_status'] ?? '') !== '') $q->where('d.status', $query['delivery_status']);
                if (($query['audience'] ?? '') !== '') {
                    $q->where('r.audience', $query['audience']);
                }
            });
        }
        $total = $builder->count();
        $direction = ($query['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $builder->orderBy('created_at', $direction)->orderBy('id', $direction)->offset(($query['page'] - 1) * $query['page_size'])->limit($query['page_size'])->get();
        $counts = $this->deliveryStates($rows->pluck('id')->all());
        $groups = $this->recipientGroups($rows->pluck('id')->all());
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->logRow($row, $counts[$row->id] ?? []) + ['groups' => $groups[$row->id] ?? []];
        }
        return ['data' => $data, 'total' => $total];
    }

    /** @return array<int, list<array<string, mixed>>> 一次读取整页的接收组和渠道摘要，禁止返回联系地址。 */
    private function recipientGroups(array $ids): array
    {
        $rows = Db::table('message_recipients as r')->join('message_deliveries as d', 'd.recipient_id', '=', 'r.id')
            ->whereIn('r.message_id', $ids)->groupBy('r.message_id', 'r.group_key', 'r.group_name', 'r.audience', 'd.channel')
            ->select('r.message_id', 'r.group_key', 'r.group_name', 'r.audience', 'd.channel')->selectRaw('count(*) as total')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->message_id][] = ['id' => $row->group_key, 'name' => $row->group_name ?: '历史接收组',
                'audience' => $row->audience, 'channel' => $row->channel, 'count' => (int) $row->total];
        }
        return $result;
    }

    /** @return array<int, array<string, int>> 一次聚合整页任务，避免逐条消息查询。 */
    private function deliveryStates(array $ids): array
    {
        $groups = Db::table('message_deliveries as d')->join('message_recipients as r', 'd.recipient_id', '=', 'r.id')
            ->whereIn('r.message_id', $ids)->groupBy('r.message_id', 'd.status')
            ->select('r.message_id', 'd.status')->selectRaw('count(*) as total')->get();
        $states = [];
        foreach ($groups as $group) {
            $states[(int) $group->message_id][$group->status] = (int) $group->total;
        }
        return $states;
    }

    private function logRow(MessageModel $row, array $states): array
    {
        $name = \app\modules\message_configuration\domain\MessageTypeCatalog::all('customer')[$row->scene]['name']
            ?? \app\modules\message_configuration\domain\MessageTypeCatalog::all('admin')[$row->scene]['name'] ?? $row->scene;
        return ['id' => $row->id, 'scene' => $row->scene, 'name' => $name, 'purpose' => $row->purpose,
            'business_type' => $row->business_type, 'business_id' => $row->business_id, 'created_at' => $row->created_at,
            'delivery_states' => (object) $states, 'status' => \app\modules\message\domain\MessageOutcome::aggregate($states)];
    }

    public function detail(int $id): array
    {
        $message = MessageModel::query()->find($id);
        if ($message === null) {
            throw new BusinessException('消息不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        $deliveries = Db::table('message_deliveries as d')->join('message_recipients as r', 'd.recipient_id', '=', 'r.id')
            ->where('r.message_id', $id)->orderBy('d.id')
            ->get(['d.id', 'r.audience', 'r.account_id', 'r.group_key', 'r.group_name', 'r.read_at', 'd.channel', 'd.channel_name', 'd.status', 'd.attempts', 'd.error_code', 'd.updated_at']);
        $attempts = MessageDeliveryAttemptModel::query()->whereIn('delivery_id', $deliveries->pluck('id'))->orderBy('id')
            ->get(['delivery_id', 'attempt_no', 'status', 'error_code', 'duration_ms', 'created_at', 'finished_at'])->toArray();
        return $this->logRow($message, $this->deliveryStates([$id])[$id] ?? []) + ['groups' => $this->recipientGroups([$id])[$id] ?? [],
            'deliveries' => $deliveries->map(static fn ($r): array => (array) $r)->all(), 'attempts' => $attempts];
    }

    public function retry(int $id): void
    {
        $updated = MessageDeliveryModel::query()->whereKey($id)->where('status', 'failed')
            ->where('attempts', '<', 10)->update(['status' => 'pending', 'queue_after' => date('Y-m-d H:i:s'),
                'next_attempt_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        if (!$updated) {
            throw new BusinessException('仅明确失败且未达到10次上限的任务可以重试；未知结果请先核实厂商流水', ApiErrorCode::CONFLICT, 409);
        }
    }
}
