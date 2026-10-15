<?php

declare(strict_types=1);
namespace app\modules\website\contract;
/** 官网公共配置、当前入口及SEO；主题版本和配置由统一主题仓储管理。 */
interface WebsiteRepositoryInterface
{
    public function website(): array;
    public function snapshot(): array;
    public function pageSeo(string $themeId): array;
    public function savePageSeo(int $revision, string $scope, string $pageId, array $settings): array;
    public function saveWebsite(int $revision, array $settings): array;
}
