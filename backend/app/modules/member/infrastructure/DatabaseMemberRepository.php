<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure;

use app\shared\infrastructure\TableQueryOrder;
use support\Db;

use app\modules\member\application\dto\MemberProfile;
use app\modules\member\contract\MemberRepositoryInterface;
use app\modules\member\infrastructure\model\MemberModel;
use app\modules\member\infrastructure\model\RealNameAuthenticationModel;
use app\modules\order\contract\OrderMemberReferenceInterface;
use Illuminate\Database\Eloquent\Builder;

/** 会员试点：模型持久化、模块内预加载、白名单 DTO。 */
final readonly class DatabaseMemberRepository implements MemberRepositoryInterface
{
    private const array COLUMNS = [
        'id', 'public_id', 'nickname', 'group_id', 'level_id', 'mobile', 'email', 'description', 'status',
        'last_login_at', 'created_at', 'updated_at',
    ];
    public function __construct(private OrderMemberReferenceInterface $orders)
    {
    }

    /** @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合 */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $query = $this->query();
        if ($keyword !== '') {
            $query->where(static function (Builder $nested) use ($keyword): void {
                $pattern = '%' . $keyword . '%';
                $nested->where('nickname', 'like', $pattern)->orWhere('mobile', 'like', $pattern)->orWhere('email', 'like', $pattern);
            });
        }
        if ($status !== null) $query->where('status', $status);
        foreach ([
            'nickname' => 'nickname',
            'mobile' => 'mobile',
            'email' => 'email',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $pattern = '%' . strtr($filters[$field], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $query->whereRaw($query->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $total = (int) (clone $query)->count();
        $rows = TableQueryOrder::apply($query, $filters, [
                'created_at' => 'created_at',
                'last_login_at' => 'last_login_at',
            ], ['created_at' => 'desc', 'id' => 'desc'], 'id')->forPage($page, $pageSize)->get();
        return ['data' => $rows->map(fn (MemberModel $row): array => $this->profile($row)->toArray())->all(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = $this->query()->whereKey($id)->first();
        return $row === null ? null : $this->profile($row)->toArray();
    }

    public function mobileExists(string $mobile, ?int $exceptId = null): bool
    {
        return $this->valueExists('mobile', $mobile, $exceptId);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return $this->valueExists('email', $email, $exceptId);
    }

    public function save(?int $id, array $data): int
    {
        if ($id !== null) {
            $model = MemberModel::query()->findOrFail($id);
            unset($data['public_id']);
            $model->fill($data);
            foreach (['mobile', 'email'] as $field) {
                if ($model->isDirty($field)) $model->{$field . '_verified_at'} = null;
            }
            $model->save();
            return $model->id;
        }
        unset($data['public_id']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = \app\modules\member\domain\PublicMemberId::generate();
            try {
                return Db::transaction(static fn (): int => MemberModel::query()->create(['public_id' => $number] + $data)->id);
            } catch (\Illuminate\Database\QueryException $error) {
                if (!in_array($error->getCode(), ['23000', '23505'], true)
                    || !MemberModel::query()->where('public_id', $number)->exists()) throw $error;
            }
        }
        throw new \app\exception\BusinessException('会员编号生成失败，请稍后重试', \app\shared\http\ApiErrorCode::SERVICE_UNAVAILABLE, 503);
    }

    public function delete(int $id): void
    {
        MemberModel::query()->whereKey($id)->delete();
    }

    public function hasRealNameRecords(int $id): bool
    {
        return RealNameAuthenticationModel::query()->where('member_id', $id)->exists();
    }

    public function hasOrders(int $id): bool
    {
        return $this->orders->hasOrders($id);
    }

    private function valueExists(string $field, string $value, ?int $exceptId): bool
    {
        return MemberModel::query()->where($field, $value)->when($exceptId !== null,
            static fn (Builder $query) => $query->where('id', '<>', $exceptId))->exists();
    }

    private function query(): Builder
    {
        return MemberModel::query()->select(self::COLUMNS)->with(['group:id,name', 'level:id,name']);
    }

    private function profile(MemberModel $row): MemberProfile
    {
        return new MemberProfile(
            id: $row->id,
            publicId: $row->public_id,
            nickname: $row->nickname,
            groupId: $row->group_id,
            groupName: $row->group?->name,
            levelId: $row->level_id,
            levelName: $row->level?->name,
            mobile: $row->mobile,
            email: $row->email,
            description: $row->description,
            status: $row->status,
            lastLoginAt: $row->last_login_at?->format('Y-m-d H:i:s'),
            createdAt: $row->created_at?->format('Y-m-d H:i:s'),
            updatedAt: $row->updated_at?->format('Y-m-d H:i:s'),
        );
    }
}
