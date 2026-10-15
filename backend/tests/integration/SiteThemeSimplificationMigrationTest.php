<?php

declare(strict_types=1);
namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use app\modules\website\infrastructure\DatabaseWebsiteRepository;
use app\modules\file\infrastructure\DatabaseFileRegistry;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 覆盖已有008数据库的真实升级分支，包含定制内容与素材引用。 */
final class SiteThemeSimplificationMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }
    protected function setUp(): void { $this->initialMigrationVersion = 20261011000800; $this->initializeDatabase(); }
    public function testMigratesOldBuiltinModuleWithoutLosingWebsiteOrFileReferences(): void
    {
        $before = (array) Db::table('website_settings')->where('id',1)->first();
        $theme = json_decode(Db::table('website_theme_settings')->where('theme_id','default')->value('settings'),true);
        $theme['global']['hero_title'] = '迁移前的定制';
        $file = bin2hex(random_bytes(16)); $now = date('Y-m-d H:i:s');
        Db::table('file_assets')->insert(['id'=>$file,'original_name'=>'banner.png','extension'=>'png','type'=>'image','namespace'=>'website','visibility'=>'public','origin'=>'website',
            'mime_type'=>'image/png','byte_size'=>1,'object_key'=>'banner.png','channel_id'=>1,'channel_revision'=>1,'status'=>'stored','created_by'=>1,'created_at'=>$now]);
        $theme['global']['banners'] = [['title'=>'横幅','description'=>'','image_id'=>$file]];
        Db::table('website_theme_settings')->where('theme_id','default')->update(['settings'=>json_encode($theme)]);
        Db::table('file_references')->insert(['file_id'=>$file,'scope'=>'theme','owner'=>'default','label'=>'原主题']);
        $digest = bin2hex(random_bytes(32)); $directory = runtime_path('website-themes/'.$digest.'/official-console'); mkdir($directory,0750,true);
        file_put_contents($directory.'/theme.json',json_encode(['components'=>['console'=>['entries'=>['responsive'=>['entry'=>'console/entry.js']]]]]));
        try {
            $old = Db::table('site_theme_releases')->insertGetId(['theme_id'=>'official-console','name'=>'旧内置构建','version'=>'0.0.1','digest'=>$digest,'components'=>'["responsive"]','installed_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            Db::table('site_theme_configurations')->insert(['release_id'=>$old,'component'=>'responsive','settings'=>'{}','revision'=>1,'created_at'=>$now,'updated_at'=>$now]);
            Db::table('console_theme_bindings')->where('id',1)->update(['responsive'=>$old]);
            $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(),new PdoSchemaInspector());
            [$manager] = (new \ReflectionMethod($runner, 'manager'))->invoke($runner, MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig()));
            $manager->migrate('runtime', 20261011001000);
            $repository = new DatabaseWebsiteRepository(new DatabaseFileRegistry()); $after = $repository->website();
            self::assertSame(json_decode($before['settings'],true),$after['settings']);
            self::assertSame('迁移前的定制',$repository->snapshot()['theme']['settings']['global']['hero_title']);
            self::assertSame(0,Db::table('site_theme_releases')->where('id',$old)->count());
            self::assertNotSame($old,(int) Db::table('console_theme_bindings')->value('responsive'));
            self::assertSame(0,Db::table('file_references')->where('scope','theme')->count());
            self::assertSame(1,Db::table('file_references')->where('file_id',$file)->where('scope','site_theme')->where('owner',$after['active_release_id'].':website')->count());
            $manager->migrate('runtime', 20261011001000);
        } finally { unlink($directory.'/theme.json'); rmdir($directory); rmdir(dirname($directory)); }
    }
}
