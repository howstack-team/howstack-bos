<?php

declare(strict_types=1);

return [
    // 即使启用，页面与 OpenAPI 文件仍要求管理员会话和 admin.api-docs.view。
    'enabled' => filter_var(getenv('API_DOCS_ENABLED') === false ? 'true' : getenv('API_DOCS_ENABLED'), FILTER_VALIDATE_BOOL),
];
