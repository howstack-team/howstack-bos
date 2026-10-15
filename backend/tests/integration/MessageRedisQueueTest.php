<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\message\infrastructure\DatabaseMessageRepository;
use app\modules\message\infrastructure\RedisMessageQueue;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use tests\support\MessageRuntimeFactory;
use Webman\Config;
use Webman\RedisQueue\Redis;

/** 真Redis和独立Workerman消费者，仅写隔离数据库和唯一测试前缀。 */
final class MessageRedisQueueTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testSynchronousPublishOnlyContainsIdAndActualConsumerDeliversOnce(): void
    {
        if (getenv('HOWSTACK_REDIS_TEST') !== '1') self::markTestSkipped('需要显式开启本地Redis隔离测试');
        $prefix = 'howstack:test:message:' . bin2hex(random_bytes(8)) . ':';
        $temporary = sys_get_temp_dir() . '/message-redis-' . bin2hex(random_bytes(6));
        mkdir($temporary, 0700);
        file_put_contents($temporary . '/app.php', '<?php return ["enable" => true];');
        $redis = config('plugin.webman.redis-queue.redis.default');
        $redis['options']['prefix'] = $prefix;
        $name = 'message_test_' . bin2hex(random_bytes(8));
        file_put_contents($temporary . '/' . $name . '.php', '<?php return ' . var_export($redis, true) . ';');
        chmod($temporary . '/' . $name . '.php', 0600);
        Config::load($temporary, [], 'redis_queue');
        $cipher = new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2));
        $repository = new DatabaseMessageRepository($cipher);
        $type = MessageTypeCatalog::get('customer', 'order_paid');
        $config = [];
        foreach ($type['channels'] as $channel) {
            $config[$channel] = MessageTypeCatalog::defaults($type, $channel);
            $config[$channel]['enabled'] = $channel === 'message';
        }
        (new DatabaseMessageConfigurationRepository($cipher, new DatabaseTransactionManager()))->save('customer', 'order_paid', 0, $config);
        $member = $this->member();
        MessageRuntimeFactory::publisher()->publish('redis-order.1', 'order_paid', 'order', 1, $member,
            ['site_name' => '站点', 'order_no' => 'REDIS-PRIVATE-CONTENT', 'amount' => '12.00']);
        $id = $repository->due(10)[0];
        $queue = new RedisMessageQueue($name);
        self::assertTrue($queue->publish($id));
        $key = '{redis-queue}-waiting' . RedisMessageQueue::QUEUE;
        $raw = Redis::connection($name)->lIndex($key, 0);
        self::assertStringNotContainsString('REDIS-PRIVATE-CONTENT', $raw);
        self::assertSame(['delivery_id' => $id], json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['data']);
        self::assertTrue($queue->publish($id));
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/message_queue_worker.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/output.log', 'w'], 2 => ['file', $temporary . '/error.log', 'w']], $pipes, dirname(__DIR__, 2));
        try {
            fwrite($pipes[0], json_encode(['database' => Db::connection()->getConfig(), 'redis' => $redis,
                'prefix' => $prefix, 'temporary' => $temporary], JSON_THROW_ON_ERROR) . "\n");
            fclose($pipes[0]);
            $deadline = microtime(true) + 15;
            do {
                $state = proc_get_status($process);
                if (!$state['running']) break;
                if (substr_count(file_get_contents($temporary . '/output.log'), 'CONSUMED') >= 2) {
                    proc_terminate($process, SIGINT);
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($state['running']) proc_terminate($process, SIGINT);
            $deadline = microtime(true) + 5;
            do { $state = proc_get_status($process); if (!$state['running']) break; usleep(20000); } while (microtime(true) < $deadline);
            $output = file_get_contents($temporary . '/output.log');
            $error = file_get_contents($temporary . '/error.log');
            $exit = proc_close($process);
            self::assertSame(0, $state['exitcode'] === -1 ? $exit : $state['exitcode'], substr($error, 0, 2000));
            self::assertStringContainsString('CONSUMED', $output);
            self::assertStringNotContainsString('TIMEOUT', $output);
            self::assertSame('delivered', Db::table('message_deliveries')->where('id', $id)->value('status'));
            self::assertSame(1, Db::table('message_delivery_attempts')->count());
            self::assertSame(1, $repository->summary('customer', $member)['unread']);
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            Redis::connection($name)->del($key, '{redis-queue}-delayed', '{redis-queue}-failed');
            foreach (glob($temporary . '/*') as $file) unlink($file);
            rmdir($temporary);
        }
    }
}
