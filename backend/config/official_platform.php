<?php

declare(strict_types=1);

// 本机 HTTP 联调开关；开启后还必须满足 APP_ENV=local，且只允许回环地址。
$allowLocalHttp = true;

// 皓量云擎账号、授权、在线更新和应用中心统一在本文件配置，修改后完整重启 Webman。
return [
    'software_code' => 'howstack-bos',

    // 官网 HTTPS 源站，例如 https://official.example.com，不带 /api 或其他路径。
    'origin' => 'http://127.0.0.1:8808',
    'allow_local_http' => $allowLocalHttp && (getenv('APP_ENV') ?: 'production') === 'local',

    // 官网应用领取及购买页面地址；单域名部署时留空，沿用 origin。
    'portal_origin' => '',

    // 发布公钥按编号配置；编号必须与官网签名的 kid 一致，值为可信交付的 Base64 Ed25519 公钥。
    'trusted_release_keys' => [
        // 本地官网的发行公钥；部署时使用受信渠道提供的对应环境公钥。
        'howstack-release-dae24cfca1da1a5e' => 'Q7SFmRfU6lnJKlCJJu/1eiWodDMQD0GtuOthqZEd9K8=',
    ],

    // 许可证公钥与发行公钥分开，不信任下载端自动提供的新kid。
    'trusted_license_keys' => [
        'howstack-license-e99d2d71b88050b5' => '+iYzxu0tCIK+EujGWj7WSGAITDhErAwaqmD0YjL2OLw=',
    ],

    // 更新包、应用包重定向下载的域名白名单，不带协议、端口或路径。
    'download_hosts' => [
        // 'packages.example.com',
    ],

    // 私有身份、状态锁和备份目录，必须位于 public 之外并随数据库一起备份。
    'private_directory' => base_path('storage/private/official-platform'),

    // 下载包体积上限，单位：字节（512 MiB）。
    'maximum_package_bytes' => 536870912,

    // 已连接实例检查更新的间隔（秒），限制在 6～24 小时，默认 12 小时。
    'update_check_interval_seconds' => 43200,

    // 本系统对外域名沿用通用 APP_URL 配置。
    'domain' => parse_url(getenv('APP_URL') ?: '', PHP_URL_HOST) ?: '',
    // 商业权益来自签名证书 grants；能力目录由宿主代码登记，不由版本名或配置授予。
];
