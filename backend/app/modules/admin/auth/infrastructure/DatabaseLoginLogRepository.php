<?php

declare(strict_types=1);

namespace app\modules\admin\auth\infrastructure;

use app\modules\admin\auth\infrastructure\model\AdminLoginLogModel;
use support\Model;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use RuntimeException;
use Throwable;

/** 使用系统数据库保存和查询管理员登录日志。 */
final class DatabaseLoginLogRepository implements LoginLogRepositoryInterface
{
    /**
     * 将登录快照写入系统数据库。
     * @param array<string, int|string|null> $record 登录事件快照
     * @return void
     * @throws RuntimeException 数据库写入失败时抛出
     */
    public function append(array $record): void
    {
        try {
            AdminLoginLogModel::query()->create($record);
        } catch (Throwable $exception) {
            throw new RuntimeException('登录日志存储失败', 0, $exception);
        }
    }

    /**
     * 查询登录记录并显式规范化数据库字段类型。
     * @param string   $keyword  搜索词
     * @param int|null $status   登录状态；null 表示全部
     * @param int      $page     页码
     * @param int      $pageSize 每页条数
     * @return array{data: list<array<string, mixed>>, total: int} 分页记录及总数
     * @throws RuntimeException 数据库查询失败时抛出
     */
    public function paginate(string $keyword, ?int $status, int $page, int $pageSize): array
    {
        try {
            $query = AdminLoginLogModel::query();
            if ($keyword !== '') {
                $query->where(static function (EloquentBuilder $nested) use ($keyword): void {
                    $pattern = '%' . addcslashes($keyword, '%_\\') . '%';
                    $nested->where('username', 'like', $pattern)
                        ->orWhere('nickname', 'like', $pattern)
                        ->orWhere('client_ip', 'like', $pattern)
                        ->orWhere('client_ip_location', 'like', $pattern);
                });
            }
            if ($status !== null) {
                $query->where('status', $status);
            }
            $total = (int) (clone $query)->count();
            $rows = $query->select(['id', 'logged_at', 'nickname', 'status', 'client_ip', 'client_ip_location', 'browser', 'operating_system'])
                ->orderBy('id', 'desc')->forPage($page, $pageSize)->get();

            return ['data' => array_map(static fn (Model $row): array => [
                'id' => (int) $row->id,
                'logged_at' => (string) $row->logged_at,
                'nickname' => (string) $row->nickname,
                'status' => (int) $row->status,
                'client_ip' => (string) $row->client_ip,
                'client_ip_location' => (string) $row->client_ip_location,
                'browser' => (string) $row->browser,
                'operating_system' => (string) $row->operating_system,
            ], $rows->all()), 'total' => $total];
        } catch (Throwable $exception) {
            throw new RuntimeException('登录日志查询失败', 0, $exception);
        }
    }

    /**
     * 统计过期记录。
     * @param string $cutoff 截止时间
     * @return int 过期记录数
     * @throws RuntimeException 数据库查询失败时抛出
     */
    public function countBefore(string $cutoff): int
    {
        try {
            return (int) AdminLoginLogModel::query()->where('logged_at', '<', $cutoff)->count();
        } catch (Throwable $exception) {
            throw new RuntimeException('登录日志查询失败', 0, $exception);
        }
    }

    /**
     * 小批量删除过期记录。
     * @param string $cutoff 截止时间
     * @param int    $limit  单批最多删除条数
     * @return int 实际删除条数
     * @throws RuntimeException 数据库清理失败时抛出
     */
    public function deleteBatchBefore(string $cutoff, int $limit): int
    {
        try {
            $ids = AdminLoginLogModel::query()->where('logged_at', '<', $cutoff)
                ->orderBy('logged_at')->orderBy('id')->limit($limit)->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }

            return AdminLoginLogModel::query()->whereIn('id', $ids)->where('logged_at', '<', $cutoff)->delete();
        } catch (Throwable $exception) {
            throw new RuntimeException('登录日志清理失败', 0, $exception);
        }
    }
}
