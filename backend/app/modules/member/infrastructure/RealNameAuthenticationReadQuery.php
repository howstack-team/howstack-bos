<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/** 后台跨模块联表只读查询，不能执行写入。 */
final class RealNameAuthenticationReadQuery
{
    public function paginate(string $keyword, ?string $type, ?string $status, int $page, int $pageSize): array
    {
        $query = Db::table('member_real_name_authentications as a')
            ->join('members as m', 'm.id', '=', 'a.member_id');
        if ($keyword !== '') {
            $query->where(static function (Builder $nested) use ($keyword): void {
                $pattern = '%' . $keyword . '%';
                $nested->where('m.mobile', 'like', $pattern)->orWhere('m.nickname', 'like', $pattern)
                    ->orWhere('a.subject_name', 'like', $pattern)->orWhere('a.company_name', 'like', $pattern);
            });
        }
        if ($type !== null) $query->where('a.authentication_type', $type);
        if ($status !== null) $query->where('a.status', $status);
        $total = (clone $query)->count();
        $rows = $query->select(self::safeColumns())->orderBy('a.id', 'desc')->forPage($page, $pageSize)->get();

        return ['data' => array_map(self::normalize(...), $rows->all()), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = Db::table('member_real_name_authentications as a')->join('members as m', 'm.id', '=', 'a.member_id')
            ->select(self::safeColumns())->where('a.id', $id)->first();

        return $row instanceof stdClass ? self::normalize($row) : null;
    }

    private static function safeColumns(): array
    {
        return [
            'a.id', 'a.member_id', 'm.mobile', 'm.nickname', 'a.authentication_type', 'a.status',
            'a.subject_name', 'a.id_type', 'a.id_number_masked', 'a.company_name',
            'a.registration_code_masked', 'a.legal_representative', 'a.upgrade_from_id',
            'a.reviewed_by', 'a.rejection_reason', 'a.submitted_at', 'a.reviewed_at', 'a.created_at', 'a.updated_at',
        ];
    }

    private static function normalize(stdClass $row): array
    {
        $data = (array) $row;
        foreach (['id', 'member_id', 'upgrade_from_id', 'reviewed_by'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }
        return $data;
    }
}
