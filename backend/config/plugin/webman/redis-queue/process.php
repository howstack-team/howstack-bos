<?php
return getenv('WEBMAN_DISABLE_REDIS_QUEUE') === '1' ? [] : [
    'consumer'  => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => max(1, (int) (getenv('MESSAGE_QUEUE_WORKERS') ?: 2)),
        'constructor' => [
            // 消费者类目录
            'consumer_dir' => app_path() . '/queue/redis'
        ]
    ]
];
