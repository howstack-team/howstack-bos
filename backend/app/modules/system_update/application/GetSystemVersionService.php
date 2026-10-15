<?php

declare(strict_types=1);

namespace app\modules\system_update\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use RuntimeException;

/** 查询当前代码包版本，不执行远程检查或数据库升级。 */
final readonly class GetSystemVersionService
{
    /**
     * 初始化版本查询用例。
     * @param AdminAccessControlService $access         后台权限服务
     * @param string                    $currentVersion version.current 配置中的代码包版本
     */
    public function __construct(
        private AdminAccessControlService $access,
        private string $currentVersion,
    ) {
    }

    /**
     * 返回已授权管理员可查看的本地版本信息。
     * @param AdminAccount $actor 当前管理员
     * @return array{current_version: string} 当前代码版本，不代表数据库迁移已完成
     * @throws BusinessException 缺少页面查看权限时抛出
     * @throws RuntimeException 版本配置为空时抛出，不以默认值伪装真实版本
     */
    public function execute(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        if (trim($this->currentVersion) === '') {
            throw new RuntimeException('系统代码版本未配置');
        }

        return ['current_version' => $this->currentVersion];
    }
}
