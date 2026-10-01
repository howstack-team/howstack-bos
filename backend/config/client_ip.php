<?php

declare(strict_types=1);

/** 仅将明确列出的反向代理或 CDN 出口视为可信。 */
return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', getenv('TRUSTED_PROXY_IPS') ?: '')))),
    'ipv6_database' => getenv('IP2REGION_IPV6_DB') ?: '',
];
