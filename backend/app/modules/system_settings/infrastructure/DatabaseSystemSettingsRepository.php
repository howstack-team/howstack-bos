<?php

declare(strict_types=1);

namespace app\modules\system_settings\infrastructure;

use app\modules\system_settings\infrastructure\model\SystemSettingModel;
use app\modules\system_settings\infrastructure\model\SystemSettingCategoryModel;
use support\Model;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use RuntimeException;
use support\Db;
use Throwable;

/** 使用既有设置项表与分类表持久化固定设置。 */
final class DatabaseSystemSettingsRepository implements SystemSettingsRepositoryInterface
{
    /**
     * 读取已实现的分类。
     * @return list<array{id: int, code: string, name: string, sort_order: int}> 分类
     */
    public function categories(): array
    {
        try {
            return SystemSettingCategoryModel::query()->whereIn('code', ['base', 'security', 'notifications', 'registration'])
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name', 'sort_order'])
                ->map(static fn (Model $row): array => [
                    'id' => (int) $row->id, 'code' => (string) $row->code,
                    'name' => (string) $row->name, 'sort_order' => (int) $row->sort_order,
                ])->all();
        } catch (Throwable $exception) {
            throw new RuntimeException('系统设置分类读取失败', 0, $exception);
        }
    }

    /**
     * 读取分类值；缺失分类属于安装或升级异常。
     * @param string $category 分类编码
     * @return array<string, string> 原始设置
     */
    public function values(string $category): array
    {
        try {
            $categoryId = SystemSettingCategoryModel::query()->where('code', $category)->value('id');
            if ($categoryId === null) {
                throw new RuntimeException('系统设置分类尚未初始化');
            }

            return SystemSettingModel::query()->where('category_id', $categoryId)
                ->pluck('setting_value', 'setting_key')->map(static fn (mixed $value): string => (string) $value)->all();
        } catch (Throwable $exception) {
            throw new RuntimeException('系统设置读取失败', 0, $exception);
        }
    }

    /**
     * 串行保存同一分类并保留既有公开标志，全部字段一起提交或回滚。
     * @param string                $category 分类编码
     * @param array<string, string> $values 已校验的完整分类值
     * @return void
     */
    public function save(string $category, array $values): void
    {
        try {
            Db::transaction(static function () use ($category, $values): void {
                $categoryId = SystemSettingCategoryModel::query()->where('code', $category)->lockForUpdate()->value('id');
                if ($categoryId === null) {
                    throw new RuntimeException('系统设置分类尚未初始化');
                }
                $now = date('Y-m-d H:i:s');
                foreach ($values as $key => $value) {
                    $setting = SystemSettingModel::query()->where('setting_key', $key)->first();
                    $record = ['category_id' => $categoryId, 'setting_value' => $value, 'updated_at' => $now];
                    if ($setting !== null) {
                        $setting->fill($record)->save();
                    } else {
                        SystemSettingModel::query()->create($record + [
                            'setting_key' => $key, 'is_public' => false, 'created_at' => $now,
                        ]);
                    }
                }
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('系统设置保存失败', 0, $exception);
        }
    }
}
