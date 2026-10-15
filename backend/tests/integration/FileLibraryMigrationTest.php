<?php

declare(strict_types=1);
namespace tests\integration;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner,PdoSchemaInspector,SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class FileLibraryMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase {setUp as private initializeDatabase;}
    protected function setUp(): void {$this->initialMigrationVersion=20261011000300;$this->initializeDatabase();}
    public function testHistoricalFilesAndSeoArePreservedAndRetryDoesNotOverwrite(): void
    {
        $id=str_repeat('a',32);$now=date('Y-m-d H:i:s');Db::table('website_images')->insert(['id'=>$id,'channel_id'=>2,'channel_revision'=>3,'object_key'=>'website/images/'.$id.'.png','mime_type'=>'image/png','byte_size'=>68,'created_by'=>1,'status'=>'stored','created_at'=>$now]);
        $row=Db::table('website_settings')->where('id',1)->first();$settings=json_decode($row->settings,true);$settings['company']['image_id']=$id;$settings['seo_title']='旧副标题';$settings['seo_description']='旧描述';$settings['seo_keywords']='旧关键词';Db::table('website_settings')->where('id',1)->update(['settings'=>json_encode($settings)]);
        Db::table('website_page_seo')->insert(['scope'=>'core','page_id'=>'home','title'=>'自定义首页副标题','description'=>'','keywords'=>'','indexable'=>0,'created_at'=>$now,'updated_at'=>$now]);
        $runner=new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(),new PdoSchemaInspector());$config=MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());self::assertSame([20261011000400,20261011000500,20261011000600, 20261011000700],$runner->migrate($config)->executed);
        $file=Db::table('file_assets')->where('id',$id)->first();self::assertNull($file->original_name);self::assertSame('website',$file->namespace);self::assertSame('website/images/'.$id.'.png',$file->object_key);self::assertSame(1,Db::table('file_references')->where('file_id',$id)->count());self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('website_images'));self::assertFalse(Db::connection()->getSchemaBuilder()->hasTable('content_images'));
        $home=Db::table('website_page_seo')->where('page_id','home')->first();self::assertSame('自定义首页副标题',$home->title);self::assertSame('旧描述',$home->description);self::assertSame('旧关键词',$home->keywords);self::assertSame(0,(int)$home->indexable);self::assertArrayNotHasKey('seo_title',json_decode(Db::table('website_settings')->value('settings'),true));self::assertSame([],$runner->migrate($config)->executed);self::assertSame('旧描述',Db::table('website_page_seo')->where('page_id','home')->value('description'));
        if(Db::connection()->getDriverName()==='mysql') foreach(['file_assets','file_references'] as $table) foreach(Db::select('SELECT COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['orm_'.$table]) as $column) self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u',$column->comment);
    }
}
