<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_starts_with($input['connection']['database'], 'howstack_orm_test_')) exit(2);
class_exists(\support\Db::class); class_exists(\support\Model::class);
$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection($input['connection']); $capsule->setAsGlobal(); $capsule->bootEloquent();
$repository = new \app\modules\support\infrastructure\DatabaseTicketConversationRepository();
fwrite(STDOUT, "READY\n");
if (trim(fgets(STDIN)) !== 'GO') exit(3);
try {
    $result = \support\Db::connection()->transaction(static fn (): array => $repository->command('member', $input['member_id'], $input['action'], $input['ticket_id'], $input['input'], $input['key']));
    fwrite(STDOUT, json_encode(['status' => 200, 'ticket_id' => $result['ticket_id'], 'message_id' => $result['message_id']], JSON_THROW_ON_ERROR));
} catch (\app\exception\BusinessException $error) {
    fwrite(STDOUT, json_encode(['status' => $error->httpStatus()], JSON_THROW_ON_ERROR));
}
