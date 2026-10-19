<?php

declare(strict_types=1);

// 独立 PHP 进程，仅从 stdin 读取独立测试库连接，凭据不进入命令参数或输出。
require dirname(__DIR__) . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\modules\finance\infrastructure\DatabaseOutboxWriter;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\DatabaseOrderPaymentRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\exception\BusinessException;

$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_starts_with($input['connection']['database'], 'howstack_orm_test_')) exit(2);
class_exists(\support\Db::class);
$capsule = new Capsule();
$capsule->addConnection($input['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
fwrite(STDOUT, "READY\n");
$transactions = new DatabaseTransactionManager();
$service = new PayOrderByWalletService(new DatabaseOrderPaymentRepository(),
    new WalletLedgerService(new DatabaseWalletLedgerRepository(), $transactions, \tests\support\MessageRuntimeFactory::publisher()), $transactions, new DatabaseOutboxWriter(), \tests\support\MessageRuntimeFactory::publisher(), \tests\support\ProductRuntimeFactory::purchases(), new \tests\support\RecordingOrderProvisioning());
try {
    $balance = $service->pay($input['member_id'], $input['order_id']);
    fwrite(STDOUT, json_encode(['balance' => $balance], JSON_THROW_ON_ERROR) . "\n");
} catch (BusinessException $error) {
    fwrite(STDOUT, json_encode(['conflict' => $error->httpStatus()], JSON_THROW_ON_ERROR) . "\n");
}
