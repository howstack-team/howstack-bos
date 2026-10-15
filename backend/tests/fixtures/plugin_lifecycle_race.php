<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\exception\BusinessException;
use app\modules\plugin\infrastructure\DatabasePluginInstallationRepository;
use app\modules\provider\infrastructure\{DatabaseProviderRepository, LocalProviderAdapterRegistry};
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use Illuminate\Database\Capsule\Manager;
use support\Model;

// 只接收测试父进程的随机隔离库配置，不读取本地业务 .env。
$config = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$capsule = new Manager();
$capsule->addConnection($config);
$capsule->setAsGlobal();
$capsule->bootEloquent();
Model::unsetEventDispatcher();
$transactions = new DatabaseTransactionManager();
$installations = new DatabasePluginInstallationRepository($transactions);
$adapters = new LocalProviderAdapterRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0', new \GuzzleHttp\Client(), $installations);
$connections = new DatabaseProviderRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), $transactions, $adapters, $installations);
echo "ready\n";
flush();
try {
    $connections->save(null, ['name' => '并发测试连接', 'adapter_id' => 'magic_cloud', 'configuration' => ['api_url' => 'https://fixture.example.test', 'username' => 'fixture', 'password' => 'fixture-secret'], 'status' => 0, 'revision' => 0]);
    echo json_encode(['status' => 201]);
} catch (BusinessException $error) {
    echo json_encode(['status' => $error->httpStatus()]);
}
$capsule->getConnection()->disconnect();
