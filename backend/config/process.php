<?php

declare(strict_types=1);
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

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [
    'service-lifecycle' => ['handler' => \app\process\ServiceLifecycleWorker::class, 'count' => 1],
    'service-provisioning' => ['handler' => \app\process\ServiceProvisioningWorker::class, 'count' => 1],
    'payment-reconciliation' => ['handler' => \app\process\PaymentReconciliationWorker::class, 'count' => 1],
    'message-delivery' => ['handler' => \app\process\MessageDeliveryWorker::class, 'count' => 1],
    'software-maintenance' => [
        'handler' => \app\process\SoftwareMaintenanceWorker::class,
        'count' => 1,
        'reloadable' => true,
    ],
    'webman' => [
        'handler' => Http::class,
        'listen' => getenv('WEBMAN_LISTEN') ?: 'http://0.0.0.0:8989',
        'count' => max(1, (int) (getenv('WEBMAN_COUNT') ?: cpu_count() * 4)),
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
                base_path('plugins'),
            ]),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env', 'json'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ]
];
