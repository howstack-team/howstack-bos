<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\website\contract\{SiteThemeRepositoryInterface, ThemePackageInstallerInterface, ThemeRegistryInterface, WebsiteRepositoryInterface, WebsitePathPolicyInterface};
use app\modules\website\validation\{ThemeSchema, ConsoleThemeComponent, ThemeConfigurationMerger};
use app\shared\http\ApiErrorCode;

/** 官网与会员中心共用安装目录；统一选择整套主题，缺少组件时使用默认版本。 */
final readonly class ManageSiteThemeService
{
    public function __construct(
        private SiteThemeRepositoryInterface $repository,
        private ThemePackageInstallerInterface $installer,
        private ThemeRegistryInterface $registry,
        private WebsiteRepositoryInterface $website,
        private WebsitePathPolicyInterface $paths,
        private ManageWebsiteService $management,
        private RenderWebsiteService $render,
        private AdminAccessControlService $access,
    ) {
    }

    public function catalog(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.view');
        $bindings = $this->bindings($actor);
        return array_map(function (array $release) use ($bindings): array {
            $package = $this->package($release['id']);
            $active = [];
            if ($release['id'] === $bindings['website']['release_id']) {
                $active[] = 'website';
            }
            if ($release['id'] === $bindings['console']['release_id']) {
                foreach (ConsoleThemeComponent::TYPES as $type) if (in_array($type, $release['components'], true) && ($type !== 'mobile' || $bindings['mobile_enabled'])) $active[] = $type;
            }
            return $this->summary($release) + ['active' => $active, 'cover' => $this->assetBase($release) . '/cover.' . pathinfo($package['manifest']['cover'] ?? $package['manifest']['preview'], PATHINFO_EXTENSION),
                'builtin' => $release['theme_id'] === 'default'];
        }, $this->repository->themes());
    }

    public function bindings(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.view');
        return $this->repository->bindings();
    }

    public function install(AdminAccount $actor, string $archive): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.install');
        $package = $this->installer->unpack($archive);
        try {
            return $this->installPackage($package, $actor->id, $actor);
        } finally {
            $this->installer->discard($package);
        }
    }

    /** CLI与受控上传共用更新流程；HTTP入口须先完成安装权限检查。 */
    public function installPackage(array $package, ?int $actorId, ?AdminAccount $actor = null): array
    {
        $state = $this->repository->installation($package['manifest']['id']);
        if ($actor && $state['theme']) {
            $this->access->requirePermission($actor, 'admin.page.website.update');
            if (in_array($state['theme']['id'], [$state['binding']['release_id'], $state['binding']['default_release_id']], true)) $this->activationPermission($actor);
        }
        $definitions = [...($package['website'] ? ['website' => ['schema' => $package['schema'], 'defaults' => $package['defaults']]] : []), ...$package['console']];
        $configurations = [];
        foreach ($definitions as $component => $definition) {
            $stored = $state['configurations'][$component]['settings'] ?? null;
            $settings = $stored === null ? $definition['defaults'] : (new ThemeConfigurationMerger())->merge($definition['schema'], $definition['defaults'], $stored);
            try { (new ThemeSchema())->validate($settings, $definition['schema']); }
            catch (BusinessException $error) { throw new BusinessException('新主题与已有配置不兼容，请先调整配置后更新', ApiErrorCode::VALIDATION_FAILED, 422, previous: $error); }
            $configurations[$component] = $settings;
        }
        foreach ($state['configurations'] as $component => $config) if (!isset($definitions[$component]) && $config['settings'] !== []) {
            throw new BusinessException('新主题移除了已有配置的组件，请先处理该组件配置后更新', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        if ($state['theme'] && ($state['binding']['mobile_enabled'] ?? false)) {
            $selected = $state['binding']['release_id'] === $state['theme']['id'] ? $package : $this->package($state['binding']['release_id']);
            $effectiveConsoleId = $selected['console'] !== [] ? $state['binding']['release_id'] : $state['binding']['default_release_id'];
            if ($effectiveConsoleId === $state['theme']['id'] && !isset($package['console']['mobile'])) ThemeSchema::fail('console.mobile', '移动端模板正在使用，请先关闭移动端开关后更新');
        }
        $websiteSettings = $state['website']['settings']; $effectiveId = $state['website']['active_release_id'];
        if ($package['website']) $this->validateWebsite($package, $configurations['website']);
        if ($state['theme'] && in_array($state['theme']['id'], [$state['binding']['release_id'], $state['binding']['default_release_id']], true)) {
            $selected = $state['binding']['release_id'] === $state['theme']['id'] ? $package : $this->package($state['binding']['release_id']);
            $effectiveId = $selected['website'] ? $state['binding']['release_id'] : $state['binding']['default_release_id'];
            $effectivePackage = $effectiveId === $state['theme']['id'] ? $package : $this->package($effectiveId);
            $effectiveSettings = $effectiveId === $state['theme']['id'] ? $configurations['website'] : $this->repository->configuration($effectiveId, 'website')['settings'];
            $websiteSettings = $this->validateWebsite($effectivePackage, $effectiveSettings);
        }
        $this->registry->publish($package);
        return $this->summary($this->repository->install($package, $actorId, $state, $configurations, $effectiveId, $websiteSettings));
    }

    public function configuration(AdminAccount $actor, int $id, string $component): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.view');
        $package = $this->package($id);
        $definition = $this->definition($package, $component);
        $config = $this->repository->configuration($id, $component);
        return ['revision' => $config['revision'], 'settings' => ThemeSchema::jsonValue($config['settings'], $definition['schema']),
            'schema' => ThemeSchema::jsonSchema($definition['schema'])];
    }

    public function saveConfiguration(AdminAccount $actor, int $id, string $component, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.page.website.update');
        $this->access->requirePermission($actor, 'admin.page.website.view');
        $package = $this->package($id);
        $definition = $this->definition($package, $component);
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 262144) {
            ThemeSchema::fail('settings', '配置最大256KiB');
        }
        (new ThemeSchema())->validate($input['settings'], $definition['schema']);
        if ($component === 'website') {
            $this->validateWebsite($package, $input['settings']);
        }
        $this->repository->saveConfiguration($id, $component, $input['revision'], $input['settings']);
        return $this->configuration($actor, $id, $component);
    }

    public function bind(AdminAccount $actor, array $input): array
    {
        $this->activationPermission($actor);
        $bindings = $this->repository->bindings();
        $selected = $this->package($input['release_id']);
        if (!is_bool($input['mobile_enabled'] ?? null)) ThemeSchema::fail('mobile_enabled', '移动端开关必须为布尔值');
        $console = $selected['console'] !== [] ? $selected : $this->package($bindings['default_release_id']);
        if ($input['mobile_enabled'] && !isset($console['console']['mobile'])) ThemeSchema::fail('mobile_enabled', '当前主题未提供移动端模板，不能开启');
        $effectiveId = $selected['website'] ? $input['release_id'] : $bindings['default_release_id'];
        $package = $selected['website'] ? $selected : $this->package($effectiveId);
        $website = $this->website->website();
        $config = $this->repository->configuration($effectiveId, 'website');
        $settings = $this->validateWebsite($package, $config['settings']);
        $this->repository->bind($input['revision'], $input['release_id'], $input['mobile_enabled'], $website['revision'], $effectiveId, $config['revision'], $settings);
        return $this->bindings($actor);
    }

    private function activationPermission(AdminAccount $actor): void
    {
        $this->access->requirePermission($actor, 'admin.page.website.activate');
        $this->access->requirePermission($actor, 'admin.page.website.view');
    }

    public function uninstall(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.page.website.uninstall');
        $this->package($id);
        $this->repository->uninstall($id);
    }

    public function package(int $id): array
    {
        $release = $this->repository->find($id) ?? throw new BusinessException('主题不存在', ApiErrorCode::NOT_FOUND, 404);
        return $this->registry->pinned($release['theme_id'], $release['version'], $release['digest']);
    }

    private function definition(array $package, string $component): array
    {
        if ($component === 'website' && $package['website']) {
            return ['schema' => $package['schema'], 'defaults' => $package['defaults']];
        }
        return $package['console'][$component] ?? throw new BusinessException('该主题未提供此类型模板', ApiErrorCode::VALIDATION_FAILED, 422);
    }

    private function validateWebsite(array $package, array $theme): array
    {
        if (!$package['website']) {
            return [];
        }
        $this->paths->check($package);
        $settings = $this->management->navigationForTheme($this->website->website()['settings'], $package);
        $this->management->validateSettings($settings, $theme, $package);
        try {
            foreach (['/', '/__not_found', ...array_column($package['manifest']['pages'] ?? [], 'path')] as $path) {
                $this->render->validatePage($package, $settings, $theme, $path);
            }
        } catch (\Twig\Error\Error|\InvalidArgumentException $error) {
            throw new BusinessException('官网模板无法渲染，请检查字段与路由声明', ApiErrorCode::VALIDATION_FAILED, 422, previous: $error);
        }
        return $settings;
    }

    private function summary(array $release): array
    {
        return array_intersect_key($release, array_flip(['id', 'theme_id', 'name', 'version', 'digest', 'components']));
    }

    private function assetBase(array $release): string
    {
        return '/theme-assets/' . $release['theme_id'] . '/' . $release['version'] . '-' . $release['digest'];
    }
}
