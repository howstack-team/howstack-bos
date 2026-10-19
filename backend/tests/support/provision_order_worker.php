<?php

declare(strict_types=1);
// 仅测试隔离 MySQL 数据库；连接从 stdin 传入，命令行和输出不含密码。
require dirname(__DIR__) . '/bootstrap.php';
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_starts_with($input['connection']['database'], 'howstack_orm_test_')) exit(2);
class_exists(\support\Db::class);
class_exists(\support\Model::class);
$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection($input['connection']); $capsule->setAsGlobal(); $capsule->bootEloquent();
$gateway = new class($input['trace']) implements \app\modules\provider\contract\ProviderProvisioningGatewayInterface {
    public function __construct(private string $trace) {}
    public function capture(array $binding, array $configuration): array { throw new \LogicException('不在领取测试中建立新任务'); }
    public function execute(array $task, string $password): \app\shared\plugin\dto\provider\ProvisionResult {
        if (\support\Db::connection()->transactionLevel() !== 0) throw new \LogicException('网络调用不能持有事务');
        file_put_contents($this->trace, $task['request_key'] . "\n", FILE_APPEND | LOCK_EX);
        usleep(150000);
        return new \app\shared\plugin\dto\provider\ProvisionResult('active', 'remote-' . $task['id']);
    }
};
$service = new \app\modules\service_instance\application\ProvisionPaidOrderService(
    new \app\modules\order\infrastructure\DatabaseOrderProvisioningRepository(),
    new \app\modules\service_instance\infrastructure\DatabaseProvisioningRepository(new \app\shared\infrastructure\SodiumSensitiveDataCipher(str_repeat('test-key-', 8))),
    $gateway, new \app\shared\infrastructure\DatabaseTransactionManager());
fwrite(STDOUT, "READY\n");
if (trim(fgets(STDIN)) !== 'GO') exit(3);
fwrite(STDOUT, (string) $service->tick(1));
