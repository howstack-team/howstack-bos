<?php

use support\limiter\RateLimitException;

return [
    'enable' => true,
    'driver' => 'redis', // 多 worker / 多实例共享登录计数
    'stores' => [
        'redis' => [
            'connection' => 'default',
        ]
    ],
    // 仅注解 Limit::IP 使用此白名单；后台登录通过 Limiter::check 调用，不豁免本机 IP。
    'ip_whitelist' => [
        '127.0.0.1',
    ],
    'exception' => RateLimitException::class
];
