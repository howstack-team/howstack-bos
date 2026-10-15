<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\exception\BusinessException;
use app\modules\file\contract\FileRegistryInterface;
use app\modules\file\domain\FileReferences;
use app\modules\website\contract\SiteThemeRepositoryInterface;
use app\modules\website\infrastructure\model\{SiteThemeReleaseModel, SiteThemeConfigurationModel, SiteThemeBindingModel, WebsiteSettingsModel};
use app\shared\http\ApiErrorCode;
use support\Db;

/** 每个主题只有当前包记录；安装与更新原子提交，整套选择采用乐观锁。 */
final readonly class DatabaseSiteThemeRepository implements SiteThemeRepositoryInterface
{
    public function __construct(private FileRegistryInterface $files)
    {
    }

    public function themes(): array
    {
        return SiteThemeReleaseModel::query()->orderBy('theme_id')->orderByDesc('id')->get()->map(fn ($row) => $this->data($row))->all();
    }
    public function find(int $id): ?array
    {
        $row = SiteThemeReleaseModel::query()->find($id);
        return $row === null ? null : $this->data($row);
    }
    /** 为校验与配置合并读取一致快照；提交阶段核对全部修订。 */
    public function installation(string $themeId): array
    {
        return Db::transaction(function () use ($themeId): array {
            $binding = SiteThemeBindingModel::query()->where('id', 1)->sharedLock()->firstOrFail();
            $theme = SiteThemeReleaseModel::query()->where('theme_id', $themeId)->sharedLock()->first();
            $website = WebsiteSettingsModel::query()->where('id', 1)->sharedLock()->firstOrFail();
            $configurations = $theme ? SiteThemeConfigurationModel::query()->where('release_id', $theme->id)->orderBy('component')->sharedLock()->get()
                ->mapWithKeys(fn ($row) => [$row->component => $row->only(['settings', 'revision'])])->all() : [];
            return ['theme' => $theme ? $this->data($theme) : null, 'binding' => $binding->only(['release_id', 'default_release_id', 'mobile_enabled', 'revision']),
                'website' => $website->only(['active_release_id', 'settings', 'revision']), 'configurations' => $configurations,
                'default_website_revision' => SiteThemeConfigurationModel::query()->where('release_id', $binding->default_release_id)->where('component', 'website')->sharedLock()->value('revision')];
        });
    }

    /** 内容先发布到新摘要目录，最后原子替换当前记录、配置和官网来源。 */
    public function install(array $package, ?int $actorId, array $state, array $configurations, int $effectiveWebsiteId, array $websiteSettings): array
    {
        return Db::transaction(function () use ($package, $actorId, $state, $configurations, $effectiveWebsiteId, $websiteSettings): array {
            $binding = SiteThemeBindingModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            $manifest = $package['manifest'];
            $theme = SiteThemeReleaseModel::query()->where('theme_id', $manifest['id'])->lockForUpdate()->first();
            $website = WebsiteSettingsModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            $configs = $theme ? SiteThemeConfigurationModel::query()->where('release_id', $theme->id)->orderBy('component')->lockForUpdate()->get()->keyBy('component') : collect();
            if ($binding->revision !== $state['binding']['revision'] || $website->revision !== $state['website']['revision']
                || ($theme?->id !== ($state['theme']['id'] ?? null)) || ($theme?->digest !== ($state['theme']['digest'] ?? null))) $this->conflict('主题或配置已变化，请重新上传');
            foreach ($state['configurations'] as $component => $config) if ($configs->get($component)?->revision !== $config['revision']) $this->conflict('主题配置已变化，请重新上传');
            if ($configs->count() !== count($state['configurations'])) $this->conflict();
            $fallback = SiteThemeConfigurationModel::query()->where('release_id', $binding->default_release_id)->where('component', 'website')->lockForUpdate()->firstOrFail();
            if ($fallback->revision !== $state['default_website_revision']) $this->conflict('默认官网配置已变化，请重新上传');
            if ($theme && hash_equals($theme->digest, $package['digest'])) return $this->data($theme);
            $attributes = ['theme_id' => $manifest['id'], 'name' => $manifest['name'], 'version' => $manifest['version'], 'digest' => $package['digest'],
                'components' => array_keys($configurations), 'installed_by' => $actorId, 'installed_at' => date('Y-m-d H:i:s')];
            if ($theme) $theme->fill($attributes)->save();
            else $theme = SiteThemeReleaseModel::query()->create($attributes);
            foreach ($configurations as $component => $settings) {
                if (isset($configs[$component])) $configs[$component]->fill(['settings' => $settings, 'revision' => $configs[$component]->revision + 1])->save();
                else SiteThemeConfigurationModel::query()->create(['release_id' => $theme->id, 'component' => $component, 'settings' => $settings, 'revision' => 1]);
                $this->references($theme, $component, $settings);
            }
            foreach ($configs as $component => $config) if (!array_key_exists($component, $configurations)) {
                $this->references($theme, $component, []); $config->delete();
            }
            if ($state['theme'] && in_array($theme->id, [$binding->release_id, $binding->default_release_id], true)) {
                $website->fill(['active_release_id' => $effectiveWebsiteId, 'settings' => $websiteSettings, 'revision' => $website->revision + 1])->save();
                $binding->fill(['revision' => $binding->revision + 1])->save();
                $this->files->syncReferences('website', '1', '官网基础设置', FileReferences::collect($websiteSettings));
            }
            return $this->data($theme);
        });
    }
    public function bindings(): array
    {
        return Db::transaction(function (): array {
            $binding = SiteThemeBindingModel::query()->where('id', 1)->sharedLock()->firstOrFail();
            $selected = SiteThemeReleaseModel::query()->findOrFail($binding->release_id);
            $fallback = SiteThemeReleaseModel::query()->findOrFail($binding->default_release_id);
            $website = in_array('website', $selected->components, true) ? $selected : $fallback;
            $console = in_array('pc', $selected->components, true) ? $selected : $fallback;
            return $binding->only(['release_id', 'default_release_id', 'mobile_enabled', 'revision']) + [
                'website' => ['release_id' => $website->id, 'fallback' => $website->id !== $selected->id],
                'console' => ['release_id' => $console->id, 'fallback' => $console->id !== $selected->id, 'mobile_available' => in_array('mobile', $console->components, true)],
            ];
        });
    }
    public function runtime(): array
    {
        return Db::transaction(function (): array {
            $bindings = $this->bindings();
            $release = $this->find($bindings['console']['release_id']);
            $entries = [];
            foreach (['pc', 'mobile'] as $type) {
                if (in_array($type, $release['components'], true)) $entries[$type] = ['release' => $release, 'configuration' => $this->configuration($release['id'], $type)];
            }
            return ['entries' => $entries, 'mobile_enabled' => $bindings['mobile_enabled']];
        });
    }
    public function configuration(int $releaseId, string $component): array
    {
        return SiteThemeConfigurationModel::query()->where('release_id', $releaseId)->where('component', $component)->firstOrFail()->only(['settings', 'revision']);
    }
    public function saveConfiguration(int $releaseId, string $component, int $revision, array $settings): array
    {
        return Db::transaction(function () use ($releaseId, $component, $revision, $settings): array {
            $release = SiteThemeReleaseModel::query()->where('id', $releaseId)->lockForUpdate()->first();
            if ($release === null) {
                $this->conflict();
            }
            $config = SiteThemeConfigurationModel::query()->where('release_id', $releaseId)->where('component', $component)->lockForUpdate()->firstOrFail();
            if ($config->revision !== $revision) {
                $this->conflict();
            }
            $config->fill(['settings' => $settings, 'revision' => $revision + 1])->save();
            $this->references($release, $component, $settings);
            return $this->configuration($releaseId, $component);
        });
    }
    public function bind(int $revision, int $id, bool $mobileEnabled, int $websiteRevision, int $effectiveWebsiteId, int $themeRevision, array $settings): void
    {
        Db::transaction(function () use ($revision, $id, $mobileEnabled, $websiteRevision, $effectiveWebsiteId, $themeRevision, $settings): void {
            $binding = SiteThemeBindingModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            $ids = array_unique([$id, $binding->default_release_id]); sort($ids);
            $releases = SiteThemeReleaseModel::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $selected = $releases[$id] ?? null;
            $effective = $selected && in_array('website', $selected->components, true) ? $id : $binding->default_release_id;
            $website = WebsiteSettingsModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            $config = SiteThemeConfigurationModel::query()->where('release_id', $effective)->where('component', 'website')->lockForUpdate()->first();
            if (!$selected || $binding->revision !== $revision || $website->revision !== $websiteRevision || $effective !== $effectiveWebsiteId || $config?->revision !== $themeRevision) $this->conflict();
            $console = in_array('pc', $selected->components, true) ? $selected : $releases[$binding->default_release_id];
            if ($mobileEnabled && !in_array('mobile', $console->components, true)) throw new BusinessException('当前主题未提供移动端模板，不能开启', ApiErrorCode::VALIDATION_FAILED, 422);
            $website->fill(['active_release_id' => $effective, 'settings' => $settings, 'revision' => $websiteRevision + 1])->save();
            $binding->fill(['release_id' => $id, 'mobile_enabled' => $mobileEnabled, 'revision' => $revision + 1])->save();
            $this->files->syncReferences('website', '1', '官网基础设置', FileReferences::collect($settings));
        });
    }
    public function uninstall(int $id): void
    {
        Db::transaction(function () use ($id): void {
            $binding = SiteThemeBindingModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            $release = SiteThemeReleaseModel::query()->where('id', $id)->lockForUpdate()->firstOrFail();
            $website = WebsiteSettingsModel::query()->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($release->theme_id === 'default' || $website->active_release_id === $id || in_array($id, [$binding->release_id, $binding->default_release_id], true)) {
                $this->conflict('内置主题或正在使用的主题不能卸载');
            }
            foreach ($release->components as $component) {
                $this->references($release, $component, []);
            }
            SiteThemeConfigurationModel::query()->where('release_id', $id)->delete();
            $release->delete();
            // 保留不可变资源，已打开的页面仍能加载旧版本分块。
        });
    }
    private function references(SiteThemeReleaseModel $release, string $component, array $settings): void
    {
        $this->files->syncReferences('site_theme', $release->id . ':' . $component, '主题配置：' . $release->name, FileReferences::collect($settings));
    }
    private function data(SiteThemeReleaseModel $row): array
    {
        return $row->only(['id', 'theme_id', 'name', 'version', 'digest', 'components', 'installed_by', 'installed_at']);
    }

    private function conflict(string $message = '配置已变化，请重新读取后保存'): never
    {
        throw new BusinessException($message, ApiErrorCode::CONFLICT, 409);
    }
}
