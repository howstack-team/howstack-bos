<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure;

use app\shared\infrastructure\TableQueryOrder;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/** 后台跨模块联表只读查询，不能执行写入。 */
final class TicketReadQuery
{
    private const array TICKET_COLUMNS = [
        't.id', 't.ticket_no', 't.member_id', 't.type_id', 't.subject', 't.content',
        't.priority', 't.status', 't.created_at', 't.updated_at', 't.closed_at',
        'm.nickname as member_nickname', 'm.mobile as member_mobile', 'tt.name as type_name',
        'service.instance_no as service_no', 'service.display_name as service_name',
    ];
    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function tickets(
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $query = $this->ticketQuery();
        if ($status !== null) $query->where('t.status', $status);
        if ($keyword !== '') {
            $pattern = '%' . $keyword . '%';
            $query->where(static function (Builder $nested) use ($pattern): void {
                $nested->where('t.ticket_no', 'like', $pattern)->orWhere('t.subject', 'like', $pattern)
                    ->orWhere('m.nickname', 'like', $pattern)->orWhere('m.mobile', 'like', $pattern);
            });
        }
        foreach ([
            'ticket_no' => 't.ticket_no',
            'subject' => 't.subject',
            'member_mobile' => 'm.mobile',
            'member_nickname' => 'm.nickname',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        if (isset($filters['priority'])) {
            $query->where('t.priority', $filters['priority']);
        }
        $total = (int) (clone $query)->count('t.id');
        $rows = TableQueryOrder::apply($query, $filters, [
                'updated_at' => 't.updated_at',
                'created_at' => 't.created_at',
            ], ['t.id' => 'desc'], 't.id')->select(self::TICKET_COLUMNS)->forPage($page, $pageSize)->get();
        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    public function ticket(int $id): ?array
    {
        $row = $this->ticketQuery()->where('t.id', $id)->select(self::TICKET_COLUMNS)->first();
        return $row instanceof stdClass ? self::normalize($row) : null;
    }

    private function ticketQuery(): Builder
    {
        return Db::table('tickets as t')->join('members as m', 'm.id', '=', 't.member_id')
            ->join('ticket_types as tt', 'tt.id', '=', 't.type_id')
            ->leftJoin('service_instances as service', static fn ($join) => $join->on('service.id', '=', 't.service_id')->on('service.member_id', '=', 't.member_id'));
    }

    private static function normalize(stdClass $row): array
    {
        $data = array_intersect_key((array) $row, array_flip([
            'id', 'name', 'code', 'sort_order', 'status', 'created_at', 'updated_at', 'title', 'content', 'ticket_no', 'member_id', 'type_id', 'subject', 'priority', 'closed_at', 'ticket_id', 'author_type', 'author_id', 'member_nickname', 'member_mobile', 'type_name', 'service_no', 'service_name',
        ]));
        foreach (['id', 'member_id', 'type_id', 'sort_order', 'status'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field]) && $field !== 'status') $data[$field] = (int) $data[$field];
        }
        if (isset($data['status']) && is_numeric($data['status'])) $data['status'] = (int) $data['status'];
        return $data;
    }
}
