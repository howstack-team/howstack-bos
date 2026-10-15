<?php

declare(strict_types=1);

namespace app\modules\system_settings\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\modules\system_settings\validation\NotificationRobotSettingsValidator;

/** 系统设置中的机器人管理，不执行任何第三方请求。 */
final readonly class ManageNotificationRobotSettingsService
{
    public function __construct(
        private AdminAccessControlService $access,
        private NotificationRobotSettingsRepositoryInterface $repository,
        private NotificationRobotSettingsValidator $validator,
    ) {
    }

    public function get(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        $stored = $this->repository->get();
        $flags = [];
        foreach (array_keys(NotificationRobotDefinition::LABELS) as $channel) {
            foreach (NotificationRobotDefinition::keys($channel) as $key) {
                $flags[$channel][$key] = ($stored['robots'][$channel][$key] ?? '') !== '';
            }
        }
        return ['revision' => $stored['revision'], 'configured_secrets' => (object) $flags];
    }

    public function save(AdminAccount $actor, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        $this->access->requirePermission($actor, 'admin.page.settings.update');
        $values = $this->validator->values($input, $this->repository->get()['robots']);
        $this->repository->save($values['revision'], $values['robots']);
        return $this->get($actor);
    }
}
