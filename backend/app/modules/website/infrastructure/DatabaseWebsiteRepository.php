<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\exception\BusinessException;
use app\modules\website\contract\WebsiteRepositoryInterface;
use app\modules\website\infrastructure\model\WebsiteSettingsModel;
use app\modules\website\infrastructure\model\WebsitePageSeoModel;
use app\modules\website\infrastructure\model\SiteThemeReleaseModel;
use app\modules\website\infrastructure\model\SiteThemeConfigurationModel;
use app\shared\http\ApiErrorCode;
use support\Db;

/** 配置不驻留 Worker 内存；条件 UPDATE 避免读取后写入的竞争窗口。 */
final class DatabaseWebsiteRepository implements WebsiteRepositoryInterface
{
    public function __construct(private \app\modules\file\contract\FileRegistryInterface $files) {}

    public function website(): array
    {
        $model = WebsiteSettingsModel::query()->findOrFail(1);
        return $this->websiteData($model);
    }
    public function snapshot(): array
    {
        return Db::transaction(function (): array {
            $website = WebsiteSettingsModel::query()->where('id', 1)->sharedLock()->firstOrFail();
            $data = $this->websiteData($website);
            $theme = SiteThemeConfigurationModel::query()->where('release_id', $website->active_release_id)->where('component', 'website')->sharedLock()->firstOrFail();
            return ['website' => $data, 'theme' => $theme->only(['settings', 'revision']), 'seo' => $this->pageSeo($data['active_theme_id'])];
        });
    }
    private function websiteData(WebsiteSettingsModel $model): array
    {
        $release = SiteThemeReleaseModel::query()->findOrFail($model->active_release_id);
        return $model->only(['active_release_id', 'settings', 'revision']) + ['active_theme_id' => $release->theme_id, 'active_theme_version' => $release->version, 'active_theme_digest' => $release->digest];
    }

    /** @inheritDoc */
    public function pageSeo(string $themeId): array
    {
        return WebsitePageSeoModel::query()->whereIn('scope', ['core', 'theme:' . $themeId])->orderBy('scope')->orderBy('page_id')->get()
            ->map(static fn (WebsitePageSeoModel $row): array => $row->only(['scope', 'page_id', 'title', 'description', 'keywords', 'indexable']))->all();
    }

    /** @inheritDoc */
    public function savePageSeo(int $revision, string $scope, string $pageId, array $settings): array
    {
        return Db::transaction(function () use ($revision, $scope, $pageId, $settings): array {
            $now = date('Y-m-d H:i:s');
            if (WebsiteSettingsModel::query()->where('id', 1)->where('revision', $revision)
                ->update(['revision' => $revision + 1, 'updated_at' => $now]) !== 1) $this->conflict();
            // 所有官网写操作先锁单例行；主题切换与SEO保存采用同一修订边界。
            WebsitePageSeoModel::query()->upsert([['scope' => $scope, 'page_id' => $pageId, ...$settings, 'created_at' => $now, 'updated_at' => $now]],
                ['scope', 'page_id'], ['title', 'description', 'keywords', 'indexable', 'updated_at']);
            return $this->snapshot();
        });
    }

    /** @inheritDoc */
    public function saveWebsite(int $revision, array $settings): array
    {
        return Db::transaction(function () use ($revision, $settings): array {
            $query = WebsiteSettingsModel::query()->where('id', 1)->where('revision', $revision);
            if ($query->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => date('Y-m-d H:i:s')]) !== 1) $this->conflict();
            $this->files->syncReferences('website', '1', '官网基础设置', \app\modules\file\domain\FileReferences::collect($settings));
            return $this->website();
        });
    }

    private function conflict(): never
    {
        throw new BusinessException('配置已被更新，请重新读取后保存', ApiErrorCode::CONFLICT, 409);
    }
}
