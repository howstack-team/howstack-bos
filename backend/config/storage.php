<?php

declare(strict_types=1);

/** 本地存储根目录仅由服务器配置，禁止通过通道表单传入绝对路径。 */
return [
    'private_root' => base_path() . '/storage/private/channel-objects',
];
