<?php

declare(strict_types=1);

use app\modules\install\middleware\InstallGuardMiddleware;
use app\modules\admin\operation\middleware\AdminOperationLogMiddleware;
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    '' => [
        InstallGuardMiddleware::class,
        \app\modules\software_maintenance\infrastructure\MaintenanceMiddleware::class,
        AdminOperationLogMiddleware::class,
    ],
];
