<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
\app\modules\plugin\infrastructure\PluginDependencyLoader::load(dirname(__DIR__, 3) . '/plugins/mail/smtp', 'plugins\\mail\\smtp\\');

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$adapter = new \plugins\mail\smtp\MailAdapter();
$configuration = new \app\shared\plugin\dto\Configuration($input['configuration']);
$message = new \app\shared\plugin\dto\mail\MailMessage($input['key'], 'receiver@example.test', 'TLS 测试', '安全连接');
echo json_encode(['status' => $adapter->send($message, $configuration)->status], JSON_THROW_ON_ERROR);
