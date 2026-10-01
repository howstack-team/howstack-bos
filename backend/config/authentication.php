<?php

declare(strict_types=1);

return [
    'admin' => [
        'cookie_name' => 'hlyq_admin_token',
        'issuer' => getenv('APP_URL') ?: 'hlyq-idcbm',
        'audience' => 'hlyq-admin',
        'secret' => getenv('JWT_SECRET') ?: '',
        'ttl' => max(300, (int) (getenv('JWT_TTL') ?: 7200)),
        'secure_cookie' => filter_var(getenv('SESSION_SECURE') ?: false, FILTER_VALIDATE_BOOL),
    ],
    'member' => [
        'cookie_name' => 'hlyq_member_token',
        'issuer' => getenv('APP_URL') ?: 'hlyq-idcbm',
        'audience' => 'hlyq-member',
        'secret' => getenv('JWT_SECRET') ?: '',
        'ttl' => max(300, (int) (getenv('JWT_TTL') ?: 7200)),
        'secure_cookie' => filter_var(getenv('SESSION_SECURE') ?: false, FILTER_VALIDATE_BOOL),
    ],
];
