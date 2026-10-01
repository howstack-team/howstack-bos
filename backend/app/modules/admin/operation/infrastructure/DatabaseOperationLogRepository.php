<?php

declare(strict_types=1);

namespace app\modules\admin\operation\infrastructure;

use app\modules\admin\operation\infrastructure\model\AdminOperationLogModel;
use support\Model;
use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use RuntimeException;
use Throwable;

/** 使用系统数据库保存和查询管理员操作日志。 */
final class DatabaseOperationLogRepository implements OperationLogRepositoryInterface
{
    private const array SUMMARY_COLUMNS = [
        'id', 'operated_at', 'operator_nickname', 'module', 'operation', 'status',
        'client_ip', 'client_ip_location', 'duration_ms',
    ];

    private const array DETAIL_COLUMNS = [
        'id', 'operated_at', 'operator_username', 'operator_nickname', 'module', 'operation',
        'method', 'request_url', 'status', 'http_status', 'client_ip', 'client_ip_location',
        'duration_ms', 'browser', 'operating_system', 'request_id', 'request_params',
    ];

    public function append(array $record): void
    {
        try {
            AdminOperationLogModel::query()->create($record);
        } catch (Throwable $exception) {
            throw new RuntimeException('操作日志存储失败', 0, $exception);
        }
    }

    public function paginate(string $keyword, ?int $status, int $page, int $pageSize): array
    {
        try {
            $query = AdminOperationLogModel::query();
            if ($keyword !== '') {
                $pattern = '%' . addcslashes($keyword, '%_\\') . '%';
                $query->where(static function (EloquentBuilder $nested) use ($pattern): void {
                    $nested->where('operator_username', 'like', $pattern)
                        ->orWhere('operator_nickname', 'like', $pattern)
                        ->orWhere('module', 'like', $pattern)
                        ->orWhere('operation', 'like', $pattern)
                        ->orWhere('client_ip', 'like', $pattern);
                });
            }
            if ($status !== null) {
                $query->where('status', $status);
            }
            $total = (int) (clone $query)->count();
            $rows = $query->select(self::SUMMARY_COLUMNS)->orderBy('id', 'desc')->forPage($page, $pageSize)->get();

            return ['data' => array_map(static fn (Model $row): array => [
                'id' => (int) $row->id,
                'operated_at' => (string) $row->operated_at,
                'operator_nickname' => (string) $row->operator_nickname,
                'module' => (string) $row->module,
                'operation' => (string) $row->operation,
                'status' => (int) $row->status,
                'client_ip' => (string) $row->client_ip,
                'client_ip_location' => (string) $row->client_ip_location,
                'duration_ms' => (int) $row->duration_ms,
            ], $rows->all()), 'total' => $total];
        } catch (Throwable $exception) {
            throw new RuntimeException('操作日志查询失败', 0, $exception);
        }
    }

    public function find(int $id): ?array
    {
        try {
            $row = AdminOperationLogModel::query()->select(self::DETAIL_COLUMNS)->where('id', $id)->first();
            if (!$row instanceof Model) {
                return null;
            }

            $result = $row->getAttributes();
            foreach (['id', 'status', 'http_status', 'duration_ms'] as $field) {
                $result[$field] = (int) $result[$field];
            }

            return $result;
        } catch (Throwable $exception) {
            throw new RuntimeException('操作日志查询失败', 0, $exception);
        }
    }

    public function countBefore(string $cutoff): int
    {
        try {
            return (int) AdminOperationLogModel::query()->where('operated_at', '<', $cutoff)->count();
        } catch (Throwable $exception) {
            throw new RuntimeException('操作日志查询失败', 0, $exception);
        }
    }

    public function deleteBatchBefore(string $cutoff, int $limit): int
    {
        try {
            $ids = AdminOperationLogModel::query()->where('operated_at', '<', $cutoff)
                ->orderBy('operated_at')->orderBy('id')->limit($limit)->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }

            return AdminOperationLogModel::query()->whereIn('id', $ids)->where('operated_at', '<', $cutoff)->delete();
        } catch (Throwable $exception) {
            throw new RuntimeException('操作日志清理失败', 0, $exception);
        }
    }
}
