<?php

declare(strict_types=1);

namespace app\modules\message\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\message\contract\MessageRepositoryInterface;
use app\modules\message\validation\MessageQueryValidator;

/** 管理日志不显示消息正文、验证码、邮箱、手机号或机器人Webhook。 */
final readonly class ManageMessageLogsService
{
    public function __construct(private MessageRepositoryInterface $messages, private AdminAccessControlService $access,
        private MessageQueryValidator $queries)
    {
    }

    public function list(AdminAccount $actor, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.message-logs.view');
        return $this->messages->logs($this->queries->query($input)) + ['scenes' => array_values(array_map(static fn (array $scene): array =>
            ['code' => $scene['code'], 'name' => $scene['name']], \app\modules\message_configuration\domain\MessageTypeCatalog::scenes()))];
    }

    public function detail(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.message-logs.view');
        return $this->messages->detail($id);
    }

    public function retry(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.message-logs.view');
        $this->access->requirePermission($actor, 'admin.message-logs.retry');
        $this->messages->retry($id);
    }
}
