<?php

declare(strict_types=1);
namespace app\modules\website\contract;

/** 每个主题标识只有一条记录；更新保留记录ID与兼容配置。 */
interface SiteThemeRepositoryInterface
{
    public function themes(): array;
    public function find(int $id): ?array;
    public function installation(string $themeId): array;
    public function install(array $package, ?int $actorId, array $state, array $configurations, int $effectiveWebsiteId, array $websiteSettings): array;
    public function bindings(): array;
    public function runtime(): array;
    public function configuration(int $releaseId, string $component): array;
    public function saveConfiguration(int $releaseId, string $component, int $revision, array $settings): array;
    public function bind(int $revision, int $id, bool $mobileEnabled, int $websiteRevision, int $effectiveWebsiteId, int $themeRevision, array $settings): void;
    public function uninstall(int $id): void;
}
