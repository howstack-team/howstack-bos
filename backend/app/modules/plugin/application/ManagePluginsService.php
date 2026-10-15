<?php

declare(strict_types=1);

namespace app\modules\plugin\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;

/** 后台插件管理授权门面；不直接依赖 Model 或本地加载实现。 */
final readonly class ManagePluginsService
{
    /**
     * 注入授权与宿主生命周期。
     * @param AdminAccessControlService $access 后台权限
     * @param PluginLifecycleService $lifecycle 生命周期用例
     */
    public function __construct(private AdminAccessControlService $access, private PluginLifecycleService $lifecycle) {}

    /**
     * 获取全部状态，异常包不影响其他插件。
     * @param AdminAccount $actor 登录管理员
     * @return list<array<string, mixed>> 插件资料
     */
    public function list(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.plugins.view');
        return $this->lifecycle->list();
    }

    /**
     * 固定动作各自授权，不使用客户端 PHP 方法名。
     * @param AdminAccount $actor 登录管理员
     * @param string $id 插件标识
     * @param string $action 已验证的动作
     * @param int $revision 安装版本
     * @param array{client_ip: string, client_ip_location: string} $origin 操作来源
     * @return array<string, mixed> 新状态
     */
    public function transition(AdminAccount $actor, string $id, string $action, int $revision, array $origin): array
    {
        $permission = match ($action) {
            'install' => 'install', 'upgrade' => 'upgrade', 'uninstall' => 'uninstall',
            'enable', 'drain', 'disable' => 'update',
            default => throw new BusinessException('插件操作无效'),
        };
        $this->access->requirePermission($actor, 'admin.plugins.' . $permission);
        return $this->lifecycle->transition($id, $action, $revision, $actor->id, $origin);
    }
}
