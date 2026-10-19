<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_starts_with($input['connection']['database'], 'howstack_orm_test_')) {
    exit(2);
}
class_exists(\support\Db::class);
class_exists(\support\Model::class);
$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection($input['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$gateway = new class($input['trace']) implements \app\modules\provider\contract\ProviderLifecycleGatewayInterface {
    public function __construct(private string $trace)
    {
    }
    public function assertSupported(array $source, string $action = 'renew'): void
    {
    }
    public function execute(array $task): \app\shared\plugin\dto\provider\LifecycleResult
    {
        if (\support\Db::connection()->transactionLevel() !== 0) {
            throw new \LogicException('供应执行不得持有数据库事务');
        }
        file_put_contents($this->trace, $task['request_key'] . "\n", FILE_APPEND | LOCK_EX);
        usleep(150000);
        return new \app\shared\plugin\dto\provider\LifecycleResult('succeeded', $task['action'] === 'renew' ? $task['target_expires_at'] : null);
    }
};
$service = new \app\modules\service_instance\application\ProcessServiceLifecycleService(
    new \app\modules\service_instance\infrastructure\DatabaseServiceLifecycleRepository(),
    new \app\modules\order\infrastructure\DatabaseOrderProvisioningRepository(), $gateway,
    new \app\shared\infrastructure\DatabaseTransactionManager(), \tests\support\MessageRuntimeFactory::publisher(),
);
fwrite(STDOUT, "READY\n");
if (trim(fgets(STDIN)) !== 'GO') {
    exit(3);
}
fwrite(STDOUT, (string) $service->tick(5));
