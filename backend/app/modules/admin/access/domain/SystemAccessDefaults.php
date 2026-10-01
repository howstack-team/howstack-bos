<?php

declare(strict_types=1);

namespace app\modules\admin\access\domain;

/**
 * 定义安装和升级时共用的系统授权标识。
 */
final class SystemAccessDefaults
{
    public const SUPER_ADMIN_ROLE_CODE = 'system_super_admin';

    public const ROOT_DEPARTMENT_KEY = 'root';
}
