<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\modules\website\contract\{SiteThemeRepositoryInterface, ThemeRegistryInterface, WebsiteRepositoryInterface};
use app\modules\website\validation\ThemeSchema;
use app\modules\system_settings\application\GetSystemSettingsService;

/** 提供被选中的普通SPA入口及公开配置，不负责挂载前端框架。 */
final readonly class ConsoleThemeRuntimeService
{
    public function __construct(
        private SiteThemeRepositoryInterface $repository,
        private ThemeRegistryInterface $registry,
        private GetSystemSettingsService $system,
        private WebsiteRepositoryInterface $website,
    ) {
    }

    private function selected(string $preferred, string $agent): array
    {
        $snapshot = $this->repository->runtime();
        $device = in_array($preferred, ['pc', 'mobile'], true)
            ? $preferred
            : (preg_match('/Mobile|Android|iPhone|iPod/i', $agent) ? 'mobile' : 'pc');
        $type = $device === 'mobile' && $snapshot['mobile_enabled'] && isset($snapshot['entries']['mobile']) ? 'mobile' : 'pc';
        $entry = $snapshot['entries'][$type] ?? throw new \RuntimeException('会员中心模板入口未配置');
        $release = $entry['release'] ?? throw new \RuntimeException('会员中心模板版本不存在');
        $package = $this->registry->pinned($release['theme_id'], $release['version'], $release['digest']);
        $definition = $package['console'][$type] ?? throw new \RuntimeException('会员中心模板类型不匹配');
        $assetBase = '/theme-assets/' . $release['theme_id'] . '/' . $release['version'] . '-' . $release['digest'] . '/' . dirname($definition['index']) . '/';
        $site = $this->system->base();
        $images = $this->website->website()['settings'];
        $config = [
            'contract' => '1',
            'basePath' => '/console',
            'apiBase' => '/api/console/v1',
            'assetBase' => $assetBase,
            'theme' => ['id' => $release['theme_id'], 'version' => $release['version'], 'type' => $type],
            'site' => [
                'name' => $site['site_title'],
                'logo' => WebsiteImageService::publicUrl($images['logo_image_id']),
                'favicon' => WebsiteImageService::publicUrl($images['favicon_image_id']),
                'copyright' => $site['copyright'],
                'icp_record' => $site['icp_record'],
                'public_security_record' => $site['public_security_record'],
            ],
            'settings' => ThemeSchema::jsonValue($entry['configuration']['settings'], $definition['schema']),
            'capabilities' => [
                'member.auth.password', 'member.registration', 'member.profile', 'finance.wallet',
                ...($snapshot['mobile_enabled'] && isset($snapshot['entries']['mobile']) ? ['theme.presentation.split'] : []),
            ],
        ];
        return ['config' => $config, 'file' => $package['directory'] . '/' . $definition['index']];
    }
    public function bootstrap(string $preferred = 'auto', string $agent = ''): array
    {
        return $this->selected($preferred, $agent)['config'];
    }

    public function page(string $preferred, string $agent): string
    {
        $entry = $this->selected($preferred, $agent);
        $config = json_encode($entry['config'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $head = '<base href="' . htmlspecialchars($entry['config']['assetBase'], ENT_QUOTES, 'UTF-8') . '"><script id="console-theme-config" type="application/json">' . $config . '</script>';
        return preg_replace_callback('/<head\b[^>]*>/i', static fn (array $match): string => $match[0] . $head, file_get_contents($entry['file']), 1);
    }
}
