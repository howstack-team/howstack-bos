<?php
return [
    'enable'       => true,
    'websocket'    => getenv('WEBMAN_PUSH_WEBSOCKET_LISTEN') ?: 'websocket://0.0.0.0:3131',
    'api'          => getenv('WEBMAN_PUSH_API_LISTEN') ?: 'http://0.0.0.0:3232',
    'app_key'      => '0f3214d7a72a2d96d6c091ff703f94fc',
    'app_secret'   => 'd770251e460bbb8fa5c23674e158abad',
    'channel_hook' => 'http://127.0.0.1:8989/plugin/webman/push/hook',
    'auth'         => '/plugin/webman/push/auth'
];
