<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use app\modules\support\infrastructure\model\TicketTypeModel;
use app\modules\support\infrastructure\model\TicketModel;
use app\modules\support\infrastructure\model\TicketMessageModel;
use app\modules\support\infrastructure\model\TicketQuickReplyModel;
use support\Model;
use app\modules\support\contract\SupportRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use support\Db;

/** 工单管理持久化，表名只接受应用层固定映射。 */
final class DatabaseSupportRepository implements SupportRepositoryInterface
{
    public function __construct(private readonly TicketReadQuery $reads = new TicketReadQuery())
    {
    }

    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function catalog(
        string $table,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $filters = array_intersect_key($filters, array_flip($table === 'ticket_types' ? ['name', 'code', 'sort_by', 'sort_order'] : ['title', 'content', 'sort_by', 'sort_order']));
        $query = $this->modelQuery($table);
        if ($keyword !== '') {
            $column = $table === 'ticket_types' ? 'name' : 'title';
            $query->where($column, 'like', '%' . $keyword . '%');
        }
        if ($status !== null) $query->where('status', $status);
        foreach ([
            'name' => 'name',
            'code' => 'code',
            'title' => 'title',
            'content' => 'content',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $total = (int) (clone $query)->count();
        $rows = TableQueryOrder::apply(
            $query, $filters, ['sort_order' => 'sort_order', 'created_at' => 'created_at'],
            ['sort_order' => 'asc', 'id' => 'desc'],
        )->forPage($page, $pageSize)->get();
        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }
    public function catalogRow(string $table, int $id): ?array
    {
        $row = $this->modelQuery($table)->where('id', $id)->first();
        return $row instanceof Model ? self::normalize($row) : null;
    }

    public function typeCodeExists(string $code, ?int $exceptId): bool
    {
        $query = TicketTypeModel::query()->where('code', $code);
        if ($exceptId !== null) $query->where('id', '<>', $exceptId);
        return $query->exists();
    }

    public function saveCatalog(string $table, ?int $id, array $data): int
    {
        if ($id === null) return (int) $this->modelQuery($table)->create($data)->getKey();
        $this->modelQuery($table)->findOrFail($id)->fill($data)->save();
        return $id;
    }

    public function deleteCatalog(string $table, int $id): void
    {
        $this->modelQuery($table)->where('id', $id)->delete();
    }

    public function typeInUse(int $id): bool
    {
        return TicketModel::query()->where('type_id', $id)->exists();
    }

    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function tickets(
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        return $this->reads->tickets($keyword, $status, $page, $pageSize, $filters);
    }

    public function ticket(int $id): ?array
    {
        return $this->reads->ticket($id);
    }

    public function messages(int $ticketId): array
    {
        return array_map(self::normalize(...), TicketMessageModel::query()
            ->where('ticket_id', $ticketId)->orderBy('id')->get()->all());
    }

    public function reply(int $ticketId, int $adminId, string $content): ?int
    {
        return Db::connection()->transaction(static function () use ($ticketId, $adminId, $content): ?int {
            $ticket = TicketModel::query()->where('id', $ticketId)->lockForUpdate()->first();
            if (!$ticket instanceof Model || $ticket->status === 'closed') return null;
            $now = date('Y-m-d H:i:s');
            $reply = TicketMessageModel::query()->create([
                'ticket_id' => $ticketId, 'author_type' => 'admin', 'author_id' => $adminId,
                'content' => $content, 'created_at' => $now,
            ]);
            $ticket->fill(['status' => 'waiting_member'])->save();
            return (int) $reply->id;
        });
    }

    /** @return array<string, mixed> */
    private static function normalize(Model $row): array
    {
        $data = array_intersect_key($row->getAttributes(), array_flip([
            'id', 'name', 'code', 'sort_order', 'status', 'created_at', 'updated_at', 'title', 'content', 'ticket_no', 'member_id', 'type_id', 'subject', 'priority', 'closed_at', 'ticket_id', 'author_type', 'author_id', 'member_nickname', 'member_mobile', 'type_name',
        ]));
        foreach (['id', 'member_id', 'type_id', 'sort_order', 'status'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field]) && $field !== 'status') $data[$field] = (int) $data[$field];
        }
        if (isset($data['status']) && is_numeric($data['status'])) $data['status'] = (int) $data['status'];
        return $data;
    }

    /** 固定表名到模型的映射，拒绝动态表名。 */
    private function modelQuery(string $table): EloquentBuilder
    {
        return match ($table) {
            'ticket_types' => TicketTypeModel::query(),
            'ticket_quick_replies' => TicketQuickReplyModel::query(),
            default => throw new \InvalidArgumentException('不支持的数据表'),
        };
    }
}
