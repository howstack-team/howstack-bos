<?php

declare(strict_types=1);

namespace tests\unit\modules\content\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\content\application\ManageContentService;
use app\modules\content\contract\ContentRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ManageContentServiceTest extends TestCase
{
    public function testUnauthorizedAdministratorCannotReadNews(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([]);
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->expects(self::never())->method('paginate');
        $service = new ManageContentService($repository, new AdminAccessControlService($grants));

        $this->expectException(BusinessException::class);
        $service->paginate(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 'news', false, '', null, 1, 10);
    }

    public function testContentCannotUseCategoryFromAnotherKind(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with('help', true, 8)->willReturn(null);
        $repository->expects(self::never())->method('save');
        $service = new ManageContentService($repository, new AdminAccessControlService($grants));

        $this->expectException(BusinessException::class);
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'help', false, null, [
            'category_id' => 8, 'title' => '帮助标题', 'body' => '正文', 'sort_order' => 0, 'status' => 'published',
        ]);
    }

    public function testCategoryWithEntriesCannotBeDeleted(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->method('find')->willReturn(['id' => 8, 'kind' => 'help']);
        $repository->expects(self::once())->method('categoryInUse')->with(8)->willReturn(true);
        $repository->expects(self::never())->method('delete');
        $service = new ManageContentService($repository, new AdminAccessControlService($grants));

        $this->expectException(BusinessException::class);
        $service->delete(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'help', true, 8);
    }

    public function testHelpCategoryCanUseRootAsParentWithoutCode(): void
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('find')->willReturnMap([
            ['help', true, 3, ['id' => 3, 'parent_id' => null, 'status' => 1]],
            ['help', true, 9, ['id' => 9, 'parent_id' => 3, 'name' => '安装指南']],
        ]);
        $repository->expects(self::once())->method('categoryNameExists')->with('help', '安装指南', 3, null)->willReturn(false);
        $repository->expects(self::once())->method('save')->with(true, null, [
            'kind' => 'help', 'sort_order' => 2, 'name' => '安装指南', 'parent_id' => 3, 'status' => 1,
        ])->willReturn(9);
        $service = new ManageContentService($repository, new AdminAccessControlService($grants));

        $saved = $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'help', true, null, [
            'name' => '安装指南', 'parent_id' => 3, 'sort_order' => 2, 'status' => 1,
        ]);
        self::assertSame(3, $saved['parent_id']);
    }

    public function testHelpCategoryRejectsThirdLevel(): void
    {
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->method('find')->willReturn(['id' => 8, 'parent_id' => 3, 'status' => 1]);
        $repository->expects(self::never())->method('save');
        $service = new ManageContentService($repository, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));

        $this->expectException(BusinessException::class);
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'help', true, null, [
            'name' => '三级分类', 'parent_id' => 8, 'sort_order' => 0, 'status' => 1,
        ]);
    }

    public function testNewsCategoryRejectsParent(): void
    {
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $service = new ManageContentService($repository, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));

        $this->expectException(BusinessException::class);
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'news', true, null, [
            'name' => '公告分类', 'parent_id' => 3, 'sort_order' => 0, 'status' => 1,
        ]);
    }
    public function testNewsPersistsSanitizedHtmlAndExplicitFormat(): void
    {
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->method('find')->willReturnMap([
            ['news', true, 8, ['id' => 8, 'status' => 1, 'parent_id' => null]],
            ['news', false, 9, ['id' => 9, 'body_format' => 'html']],
        ]);
        $repository->expects(self::once())->method('save')->with(false, null, self::callback(static function (array $data): bool {
            return $data['body_format'] === 'html' && $data['body'] === '<p><strong>公告</strong></p>';
        }))->willReturn(9);
        $service = new ManageContentService($repository, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'news', false, null, [
            'category_id' => 8, 'title' => '测试公告', 'body' => '<p onclick="alert(1)"><strong>公告</strong><script>alert(1)</script></p>',
            'body_format' => 'html', 'sort_order' => 0, 'status' => 'draft',
        ]);
    }

    public function testHelpCannotSaveHtml(): void
    {
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->method('find')->willReturn(['id' => 8, 'status' => 1, 'parent_id' => null]);
        $repository->expects(self::never())->method('save');
        $service = new ManageContentService($repository, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
        $this->expectException(BusinessException::class);
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'help', false, null, [
            'category_id' => 8, 'title' => '帮助文档', 'body' => '<p>正文</p>', 'body_format' => 'html', 'sort_order' => 0, 'status' => 'draft',
        ]);
    }

    public function testExistingMarkdownIsPreservedWhenFormatIsOmitted(): void
    {
        $repository = $this->createMock(ContentRepositoryInterface::class);
        $repository->method('find')->willReturnMap([
            ['news', false, 9, ['id' => 9, 'body_format' => 'markdown']],
            ['news', true, 8, ['id' => 8, 'status' => 1, 'parent_id' => null]],
        ]);
        $repository->expects(self::once())->method('save')->with(false, 9, self::callback(static fn (array $data): bool => $data['body'] === '# 原文' && $data['body_format'] === 'markdown'))->willReturn(9);
        $service = new ManageContentService($repository, new AdminAccessControlService($this->createStub(AdminAccessRepositoryInterface::class)));
        $service->save(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), 'news', false, 9, [
            'category_id' => 8, 'title' => '历史公告', 'body' => '# 原文', 'sort_order' => 0, 'status' => 'draft',
        ]);
    }

}
