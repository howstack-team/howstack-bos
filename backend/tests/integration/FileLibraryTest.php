<?php

declare(strict_types=1);
namespace tests\integration;
use app\exception\BusinessException;
use app\modules\admin\access\{application\AdminAccessControlService,contract\AdminAccessRepositoryInterface};
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\{application\ChannelExecutionConfiguration,application\storage\StorageChannelService,contract\ChannelRepositoryInterface,contract\TransactionAdapterFactoryInterface};
use app\modules\file\application\ManageFilesService;
use app\modules\file\infrastructure\DatabaseFileRegistry;
use app\shared\plugin\contract\StorageAdapterInterface;
use app\shared\plugin\dto\storage\StorageResult;
use app\shared\plugin\dto\storage\StorageUrl;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实迁移和引用事务，对象存储采用可控结果验证失败分支。 */
final class FileLibraryTest extends TestCase
{
    use IsolatedBusinessDatabase;
    private function file(string $id, string $visibility='public'): void
    {
        Db::table('file_assets')->insert(['id'=>$id,'original_name'=>'测试.txt','extension'=>'txt','type'=>'document','namespace'=>'files','visibility'=>$visibility,'origin'=>'library','mime_type'=>'text/plain','byte_size'=>4,'channel_id'=>1,'channel_revision'=>1,'object_key'=>'files/'.$id.'.txt','created_by'=>1,'status'=>'stored','created_at'=>date('Y-m-d H:i:s')]);
    }
    private function service(string $deleteResult='deleted', array $permissions=[]): ManageFilesService
    {
        $grants=$this->createStub(AdminAccessRepositoryInterface::class);$grants->method('grantsForAdmin')->willReturn([['permissions'=>$permissions]]);
        $channels=$this->createStub(ChannelRepositoryInterface::class);$row=['id'=>1,'revision'=>1,'is_default'=>true,'configuration_current'=>true,'platform_available'=>true,'plugin_id'=>'fixture','status'=>1];
        $channels->method('list')->willReturn(['data'=>[$row],'total'=>1]);$channels->method('find')->willReturn($row);$channels->method('configuration')->willReturn([]);
        $adapter=$this->createStub(StorageAdapterInterface::class);$adapter->method('put')->willReturnCallback(static fn($object)=>new StorageResult('stored',$object->key));$adapter->method('delete')->willReturnCallback(static fn($key)=>new StorageResult($deleteResult,$key));
        $factory=$this->createStub(TransactionAdapterFactoryInterface::class);$factory->method('storage')->willReturn($adapter);
        return new ManageFilesService(new DatabaseFileRegistry(),new AdminAccessControlService($grants),$channels,new StorageChannelService(new ChannelExecutionConfiguration($channels),$factory));
    }
    private function founder(): AdminAccount {return new AdminAccount(1,'founder','','',1,null,true);}
    public function testReferencesBlockWholeBatchAndCannotReferenceDeletingFiles(): void
    {
        $a=str_repeat('a',32);$b=str_repeat('b',32);$this->file($a);$this->file($b);$registry=new DatabaseFileRegistry();$registry->syncReferences('content','10','正文',[$b]);
        try{$registry->beginDelete([$a,$b]);self::fail('有引用应拒绝整批');}catch(BusinessException $error){self::assertSame(409,$error->httpStatus());}
        self::assertSame('stored',$registry->find($a)['status']);self::assertSame('stored',$registry->find($b)['status']);
        $registry->syncReferences('content','10','正文',[]);$registry->beginDelete([$a]);
        try{$registry->syncReferences('content','11','并发编辑',[$a]);self::fail('不能引用删除中的文件');}catch(BusinessException $error){self::assertSame(409,$error->httpStatus());}
        $registry->finishDelete($a,true);self::assertNotNull($registry->find($a)['deleted_at']);self::assertSame(1,$registry->paginate(['page'=>1,'page_size'=>10])['total']);
    }
    public function testFailedDeleteCanRetryAndPrivateFilesCannotBeListedOrRead(): void
    {
        $id=str_repeat('c',32);$this->file($id);$private=str_repeat('d',32);$this->file($private,'private');$service=$this->service('unknown');
        self::assertSame(['deleted'=>[],'failed'=>[$id]],$service->delete($this->founder(),[$id]));$row=(new DatabaseFileRegistry())->find($id);self::assertSame('delete_failed',$row['status']);self::assertNull($row['deleted_at']);self::assertSame(1,$service->list($this->founder(),[])['total']);$stats=$service->statistics($this->founder());self::assertSame(1,$stats['count']);self::assertSame(0,$stats['byte_size']);self::assertCount(5,$stats['types']);
        self::assertSame([$id],$this->service()->delete($this->founder(),[$id])['deleted']);
        try{$service->publicUrl($private);self::fail('私密文件不能公开读取');}catch(BusinessException $error){self::assertSame(404,$error->httpStatus());}
    }
    public function testUploadPreservesNamesAndRejectsExecutableAndReadonlyWrites(): void
    {
        $service=$this->service();$created=$service->upload($this->founder(),'hello','note.txt');$file=(new DatabaseFileRegistry())->find($created['id']);self::assertSame('note.txt',$file['original_name']);self::assertSame('document',$file['type']);self::assertSame('stored',$file['status']);self::assertSame(5,$service->statistics($this->founder())['byte_size']);
        try{$service->upload($this->founder(),'<?php echo 1;','code.php');self::fail('禁止可执行文件');}catch(BusinessException $error){self::assertSame(422,$error->httpStatus());}
        $reader=new AdminAccount(99,'reader','','',1,null);$read=$this->service('deleted',['admin.files.view']);self::assertSame(1,$read->list($reader,[])['total']);
        foreach([fn()=>$read->upload($reader,'x','x.txt'),fn()=>$read->delete($reader,[$created['id']])] as $write){try{$write();self::fail('只读不可写');}catch(BusinessException $error){self::assertSame(403,$error->httpStatus());}}
    }
    public function testQueryParametersAreWhitelistedAndPageBoundsAreEnforced(): void
    {
        $service=$this->service();
        foreach ([['page'=>100001],['page_size'=>7],['type'=>'executable'],['type'=>'database'],['sort_by'=>'object_key'],['unknown'=>'value']] as $invalid) {
            try {$service->list($this->founder(),$invalid);self::fail('非法查询应拒绝');}
            catch(BusinessException $error){self::assertSame(422,$error->httpStatus());}
        }
    }
    public function testDatabaseUploadsAreClassifiedFilteredAndIncludedInStatistics(): void
    {
        $service = $this->service();
        $sql = 'CREATE TABLE example (id INTEGER);';
        $created = $service->upload($this->founder(), $sql, 'BACKUP.SQL');
        self::assertSame('other', $created['type']);
        $this->file(str_repeat('9', 32));
        $list = $service->list($this->founder(), ['type' => 'other']);
        self::assertSame(1, $list['total']);
        self::assertSame($created['id'], $list['data'][0]['id']);

        $path = tempnam(sys_get_temp_dir(), 'howstack-sqlite-upload-');
        try {
            $connection = new \PDO('sqlite:' . $path);
            $connection->exec('CREATE TABLE fixture (id INTEGER)');
            $connection = null;
            $bytes = file_get_contents($path);
            foreach (['db', 'sqlite', 'sqlite3'] as $extension) {
                self::assertSame('other', $service->upload($this->founder(), $bytes, 'data.' . $extension)['type']);
            }
            $stats = $service->statistics($this->founder());
            $database = array_values(array_filter($stats['types'], static fn (array $row): bool => $row['type'] === 'other'))[0];
            self::assertSame(4, $database['count']);
            self::assertSame(strlen($sql) + strlen($bytes) * 3, $database['byte_size']);
        } finally {
            unlink($path);
        }
        foreach ([['hello', 'fake.db'], ['<?php echo 1;', 'script.sql'], ["SELECT 1;\0", 'binary.sql'], [$bytes, 'binary.sql']] as [$bytes, $name]) {
            try {
                $service->upload($this->founder(), $bytes, $name);
                self::fail('伪造格式不能上传');
            } catch (BusinessException $error) {
                self::assertSame(422, $error->httpStatus());
            }
        }
        self::assertSame(4, $service->list($this->founder(), ['type' => 'other'])['total']);
    }
    public function testUploadChannelBindingIsPreservedWhenDefaultChannelChanges(): void
    {
        $rows = [];
        foreach ([7 => ['旧存储', 3], 8 => ['新存储', 4]] as $id => [$name, $revision]) {
            Db::table('channel_connections')->insert([
                'id' => $id, 'category' => 'storage', 'name' => $name, 'plugin_id' => 'fixture',
                'platform_snapshot' => '{}', 'secret_keys' => '[]', 'configuration_encrypted' => 'never-return-this',
                'revision' => $revision, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $rows[$id] = ['id' => $id, 'revision' => $revision, 'is_default' => true, 'configuration_current' => true, 'platform_available' => true, 'plugin_id' => 'fixture', 'status' => 1];
        }
        $default = 7;
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('list')->willReturnCallback(static function () use (&$default, $rows): array {
            return ['data' => [$rows[$default]], 'total' => 1];
        });
        $channels->method('find')->willReturnCallback(static fn (string $category, int $id): array => $rows[$id]);
        $channels->method('configuration')->willReturnCallback(static fn (string $category, int $id): array => ['source' => $id]);
        $adapter = $this->createStub(StorageAdapterInterface::class);
        $adapter->method('put')->willReturnCallback(static fn ($object) => new StorageResult('stored', $object->key));
        $adapter->method('signedUrl')->willReturnCallback(static fn ($key, $seconds, $config) => new StorageUrl($key, 'https://example.test/' . $config->values['source'], time() + $seconds));
        $adapter->method('delete')->willReturnCallback(static function ($key, $config): StorageResult {
            self::assertSame(7, $config->values['source']);
            return new StorageResult('deleted', $key);
        });
        $factory = $this->createStub(TransactionAdapterFactoryInterface::class);
        $factory->method('storage')->willReturn($adapter);
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $service = new ManageFilesService(new DatabaseFileRegistry(), new AdminAccessControlService($grants), $channels, new StorageChannelService(new ChannelExecutionConfiguration($channels), $factory));
        $old = $service->upload($this->founder(), 'old', 'old.txt');
        $default = 8;
        $new = $service->upload($this->founder(), 'new', 'new.txt');
        Db::table('channel_connections')->where('id', 7)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $files = array_column($service->list($this->founder(), [])['data'], null, 'id');
        self::assertSame(7, $files[$old['id']]['channel_id']);
        self::assertSame(3, $files[$old['id']]['channel_revision']);
        self::assertSame('旧存储', $files[$old['id']]['channel_name']);
        self::assertSame(8, $files[$new['id']]['channel_id']);
        self::assertSame(4, $files[$new['id']]['channel_revision']);
        self::assertArrayNotHasKey('configuration_encrypted', $files[$old['id']]);
        self::assertArrayNotHasKey('object_key', $files[$old['id']]);
        self::assertSame('https://example.test/7', $service->publicUrl($old['id']));
        self::assertSame([$old['id']], $service->delete($this->founder(), [$old['id']])['deleted']);
    }
    public function testUploaderNamesUseNicknameThenUsernameAndPreserveMissingAccountIdentity(): void
    {
        $service = $this->service();
        $uploaded = $service->upload($this->founder(), 'uploader', 'uploader.txt');
        $row = $service->list($this->founder(), [])['data'][0];
        self::assertSame(1, $row['created_by']);
        self::assertSame('测试管理员', $row['created_by_name']);
        self::assertArrayNotHasKey('password_hash', $row);
        self::assertArrayNotHasKey('email', $row);
        Db::table('admin_users')->where('id', 1)->update(['nickname' => ' ', 'status' => 0]);
        self::assertSame('fixture-admin', $service->list($this->founder(), [])['data'][0]['created_by_name']);
        Db::table('file_assets')->where('id', $uploaded['id'])->update(['created_by' => 999]);
        $missing = $service->list($this->founder(), [])['data'][0];
        self::assertSame(999, $missing['created_by']);
        self::assertNull($missing['created_by_name']);
    }
    public function testExpiredPreviewIsNotAnActiveReference(): void
    {
        $id=str_repeat('e',32);$this->file($id);$registry=new DatabaseFileRegistry();$registry->syncReferences('preview','fixture','过期预览',[$id],date('Y-m-d H:i:s',time()-1));self::assertSame([],$registry->references($id));self::assertCount(1,$registry->beginDelete([$id]));
    }

    public function testPaginatedReferencesAreEagerLoadedAndKeepExpiredFilesDeletable(): void
    {
        $first = str_repeat('1', 32);
        $second = str_repeat('2', 32);
        $this->file($first);
        $this->file($second);
        $registry = new DatabaseFileRegistry();
        $registry->syncReferences('content', '2', '正文二', [$first]);
        $registry->syncReferences('content', '1', '正文一', [$first]);
        $registry->syncReferences('preview', 'expired', '过期预览', [$second], date('Y-m-d H:i:s', time() - 1));

        $connection = Db::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $registry->paginate(['page' => 1, 'page_size' => 10, 'name' => '测试']);
            // 总数、列表展示联表、引用预加载；查询数量不随文件数量增加。
            self::assertCount(3, $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
        self::assertSame(2, $result['total']);
        $files = array_column($result['data'], null, 'id');
        self::assertSame([
            ['scope' => 'content', 'owner' => '1', 'label' => '正文一'],
            ['scope' => 'content', 'owner' => '2', 'label' => '正文二'],
        ], $files[$first]['references']);
        self::assertFalse($files[$first]['deletable']);
        self::assertSame([], $files[$second]['references']);
        self::assertTrue($files[$second]['deletable']);
    }
}
