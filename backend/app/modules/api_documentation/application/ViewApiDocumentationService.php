<?php

declare(strict_types=1);

namespace app\modules\api_documentation\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\api_documentation\contract\DocumentationArtifactInterface;

/** 文档访问与业务 API 权限分别校验。 */
final readonly class ViewApiDocumentationService
{
    public function __construct(
        private AdminAccessControlService $access,
        private DocumentationArtifactInterface $artifacts,
    ) {
    }

    public function page(AdminAccount $actor): string
    {
        $this->access->requirePermission($actor, 'admin.api-docs.view');

        return $this->artifacts->page();
    }

    public function specification(AdminAccount $actor, string $group): string
    {
        $this->access->requirePermission($actor, 'admin.api-docs.view');

        return $this->artifacts->specification($group);
    }
}
