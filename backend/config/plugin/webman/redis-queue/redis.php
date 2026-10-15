<?php

declare(strict_types=1);

// 共用 Redis 时按安装实例隔离，防止另一数据库消费相同数字任务 ID。
$instance = substr(hash('sha256', implode(':', [getenv('APP_KEY') ?: '', getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'hlyq_idcbm', getenv('DB_PREFIX') ?: 'hlyq_'])), 0, 16);
return [
    'default' => [
        'host' => 'redis://' . (getenv('REDIS_HOST') ?: '127.0.0.1') . ':' . (getenv('REDIS_PORT') ?: '6379'),
        'options' => [
            'auth' => getenv('REDIS_USERNAME') ? [getenv('REDIS_USERNAME'), getenv('REDIS_PASSWORD') ?: ''] : (getenv('REDIS_PASSWORD') ?: null),
            'db' => (int) (getenv('REDIS_DATABASE') ?: 0),
            'prefix' => getenv('REDIS_QUEUE_PREFIX') ?: 'howstack:' . $instance . ':',
            'max_attempts'  => 5,
            'retry_seconds' => 5,
        ],
        // Connection pool, supports only Swoole or Swow drivers.
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ]
    ],
];
