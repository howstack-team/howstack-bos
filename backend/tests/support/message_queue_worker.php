<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use app\modules\message\application\DispatchMessageService;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message\infrastructure\DatabaseMessageRepository;
use app\modules\message\infrastructure\DatabaseMessageRecipientDirectory;
use app\modules\message\infrastructure\RedisMessageQueue;
use app\queue\redis\MessageDeliveryConsumer;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Container;
use Workerman\Timer;
use Workerman\Worker;

// 测试数据库凭据从stdin读取，唯一队列前缀与线上消费者隔离。
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_starts_with($input['prefix'], 'howstack:test:message:')) exit(2);
class_exists(\support\Db::class);
$capsule = new Capsule();
$capsule->addConnection($input['database']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$transport = new class implements MessageTransportInterface {
    public function send(string $channel, string $requestKey, array $payload): array
    {
        throw new RuntimeException('Redis入箱测试不得请求任何厂商');
    }
};
Container::instance()->set(DispatchMessageService::class, new DispatchMessageService(
    new DatabaseMessageRepository(new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2))),
    $transport, new RedisMessageQueue(), new DatabaseMessageRecipientDirectory(),
));
Worker::$pidFile = $input['temporary'] . '/worker.pid';
Worker::$logFile = $input['temporary'] . '/worker.log';
$worker = new Worker();
$worker->name = 'isolated-message-consumer';
$worker->onWorkerStart = static function () use ($input): void {
    $client = new \Workerman\RedisQueue\Client($input['redis']['host'],
        array_replace($input['redis']['options'], ['prefix' => $input['prefix']]));
    $consumer = new MessageDeliveryConsumer();
    $client->subscribe(RedisMessageQueue::QUEUE, static function (array $data) use ($consumer): void {
        $consumer->consume($data);
        echo "CONSUMED\n";

    });
    Timer::add(10, static function (): void { echo "TIMEOUT\n"; Worker::stopAll(); }, [], false);
};
$argv = [$argv[0], 'start'];
Worker::runAll();
