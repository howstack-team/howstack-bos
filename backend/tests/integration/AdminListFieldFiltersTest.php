<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\admin\auth\infrastructure\DatabaseLoginLogRepository;
use app\modules\admin\operation\infrastructure\DatabaseOperationLogRepository;
use app\modules\content\infrastructure\DatabaseContentRepository;
use app\modules\member\infrastructure\DatabaseMemberCatalogRepository;
use app\modules\member\infrastructure\DatabaseMemberRepository;
use app\modules\member\infrastructure\DatabaseRealNameAuthenticationRepository;
use app\modules\order\infrastructure\DatabaseOrderMemberReference;
use app\modules\order\infrastructure\DatabaseOrderRepository;
use app\modules\service_instance\infrastructure\DatabaseServiceInstanceRepository;
use app\modules\support\infrastructure\DatabaseSupportRepository;
use app\shared\validation\TextFilterValidator;
use PHPUnit\Framework\TestCase;
use support\validation\ValidationException;
use tests\support\IsolatedBusinessDatabase;

/** 使用独立 SQLite 和真实迁移验证组合查询，绝不连接安装数据库。 */
final class AdminListFieldFiltersTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testMemberFieldsUseAndAndTreatWildcardCharactersAsText(): void
    {
        $members = new DatabaseMemberRepository(new DatabaseOrderMemberReference());
        $a = $members->save(null, ['nickname' => '客户%_甲', 'mobile' => '13800000001', 'email' => 'a@test.example', 'status' => 0, 'password_hash' => 'hash']);
        $members->save(null, ['nickname' => '客户乙', 'mobile' => '13800000002', 'email' => 'b@test.example', 'status' => 1, 'password_hash' => 'hash']);
        $page = $members->paginate('', 0, 1, 10, ['nickname' => '%_', 'mobile' => '00001', 'email' => 'a@']);
        self::assertSame(1, $page['total']);
        self::assertSame($a, $page['data'][0]['id']);
        self::assertSame(0, $members->paginate('', null, 1, 10, ['nickname' => '乙', 'mobile' => '00001'])['total']);
        self::assertSame(0, $members->paginate('', null, 1, 10, ['nickname' => "' OR 1=1 --"])['total']);
        self::assertSame(2, $members->paginate('', null, 1, 10)['total']);
    }

    public function testAuthenticationSubjectAndMemberAndStatusAreCombined(): void
    {
        $member = $this->member();
        $now = '2026-10-06 08:00:00';
        foreach (['测试企业甲', '测试企业乙'] as $company) {
            $this->row('member_real_name_authentications', ['member_id' => $member, 'authentication_type' => 'enterprise',
                'subject_name' => '法定代表人', 'company_name' => $company, 'status' => 'pending', 'id_number_ciphertext' => 'private',
                'id_number_hash' => hash('sha256', $company), 'id_number_masked' => '***', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        $records = new DatabaseRealNameAuthenticationRepository();
        $page = $records->paginate('', 'enterprise', 'pending', 1, 10, ['mobile' => '13800138001', 'subject' => '企业甲']);
        self::assertSame(1, $page['total']);
        self::assertSame('测试企业甲', $page['data'][0]['company_name']);
        self::assertArrayNotHasKey('id_number_ciphertext', $page['data'][0]);
        self::assertSame(0, $records->paginate('', 'enterprise', 'approved', 1, 10, ['subject' => '企业甲'])['total']);
        self::assertSame(0, $records->paginate('', 'enterprise', null, 1, 10, ['mobile' => '999', 'subject' => '企业甲'])['total']);
    }

    public function testOrderServiceAndTicketFiltersApplyBeforePaginationAndCount(): void
    {
        $member = $this->member();
        $this->order($member, 'ORD-A');
        $this->order($member, 'ORD-B');
        $orders = new DatabaseOrderRepository();
        self::assertSame(1, $orders->paginate('', 'pending_payment', 1, 10, ['order_no' => 'ORD-A', 'member_mobile' => '13800138001'])['total']);
        self::assertSame(0, $orders->paginate('', null, 1, 10, ['order_no' => 'ORD-A', 'member_nickname' => '不存在'])['total']);
        $now = '2026-10-06 08:00:00';
        $this->row('service_instances', ['instance_no' => 'VM-A', 'product_type' => 'cloud_server', 'member_id' => $member,
            'display_name' => '测试实例', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $instances = new DatabaseServiceInstanceRepository();
        self::assertSame(1, $instances->paginate('cloud_server', '', 'active', 1, 10, ['instance_no' => 'VM-A', 'display_name' => '测试', 'member_mobile' => '13800138001'])['total']);
        self::assertSame(0, $instances->paginate('cloud_server', '', null, 1, 10, ['instance_no' => 'VM-A', 'member_mobile' => '999'])['total']);
        $support = new DatabaseSupportRepository();
        $type = $support->saveCatalog('ticket_types', null, ['name' => '售后', 'code' => 'support', 'status' => 1]);
        $this->row('tickets', ['ticket_no' => 'T-A', 'member_id' => $member, 'type_id' => $type, 'subject' => '网络问题', 'content' => '内容', 'created_at' => $now, 'updated_at' => $now]);
        self::assertSame(1, $support->tickets('', null, 1, 10, ['ticket_no' => 'T-A', 'subject' => '网络', 'member_mobile' => '13800138001'])['total']);
        self::assertSame(0, $support->tickets('', null, 1, 10, ['ticket_no' => 'T-A', 'subject' => '磁盘'])['total']);
    }

    public function testCatalogContentAndAuditQueriesUseSpecificFields(): void
    {
        $catalog = new DatabaseMemberCatalogRepository();
        $catalog->saveGroup(null, ['name' => '普通客户', 'code' => 'basic', 'status' => 0]);
        $catalog->saveGroup(null, ['name' => '普通企业', 'code' => 'enterprise', 'status' => 1]);
        self::assertSame(1, $catalog->groups('', 0, 1, 10, ['name' => '普通', 'code' => 'basic'])['total']);
        self::assertSame(0, $catalog->groups('', null, 1, 10, ['name' => '客户', 'code' => 'enterprise'])['total']);
        $support = new DatabaseSupportRepository();
        $support->saveCatalog('ticket_quick_replies', null, ['title' => '网络', 'content' => '检查线路', 'status' => 1]);
        self::assertSame(1, $support->catalog('ticket_quick_replies', '', 1, 1, 10, ['title' => '网络', 'content' => '线路'])['total']);
        self::assertSame(0, $support->catalog('ticket_quick_replies', '', 1, 1, 10, ['title' => '网络', 'content' => '磁盘'])['total']);
        $content = new DatabaseContentRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $category = $content->save(true, null, ['kind' => 'help', 'name' => '指南', 'status' => 1, 'parent_id' => null]);
        $content->save(false, null, ['kind' => 'help', 'category_id' => $category, 'title' => '使用指南', 'body' => '正文', 'status' => 'draft']);
        self::assertSame(1, $content->paginate('help', false, '', 'draft', 1, 10, ['title' => '使用'])['total']);
        self::assertSame(0, $content->paginate('help', false, '', 'draft', 1, 10, ['title' => '不存在'])['total']);
        $login = new DatabaseLoginLogRepository();
        $login->append(['admin_user_id' => 1, 'username' => 'fixture-admin', 'nickname' => '管理员', 'status' => 0,
            'client_ip' => '127.0.0.1', 'client_ip_location' => '本地', 'browser' => 'Chrome', 'operating_system' => 'macOS', 'logged_at' => '2026-10-06 08:00:00']);
        self::assertSame(1, $login->paginate('', 0, 1, 10, ['username' => 'fixture', 'client_ip' => '127.0.0.1'])['total']);
        self::assertSame(0, $login->paginate('', 0, 1, 10, ['username' => 'fixture', 'client_ip' => '192.168'])['total']);
        $operations = new DatabaseOperationLogRepository();
        $operations->append(['admin_user_id' => 1, 'operator_username' => 'fixture-admin', 'operator_nickname' => '管理员', 'module' => '会员管理',
            'operation' => '编辑会员', 'method' => 'PUT', 'request_url' => '/members/1', 'status' => 1, 'http_status' => 200,
            'client_ip' => '127.0.0.1', 'client_ip_location' => '本地', 'duration_ms' => 10, 'browser' => 'Chrome', 'operating_system' => 'macOS',
            'request_id' => 'TEST', 'operated_at' => '2026-10-06 08:00:00']);
        self::assertSame(1, $operations->paginate('', 1, 1, 10, ['module' => '会员', 'operation' => '编辑', 'client_ip' => '127.0.0.1'])['total']);
        self::assertSame(0, $operations->paginate('', 1, 1, 10, ['module' => '会员', 'operation' => '删除'])['total']);
    }

    public function testFilterValidatorWhitelistAndEmptyValues(): void
    {
        self::assertSame(['mobile' => '138'], TextFilterValidator::validate(['mobile' => ' 138 ', 'nickname' => ' ', 'unknown' => 'x'], ['mobile' => 20, 'nickname' => 64]));
        $this->expectException(ValidationException::class);
        TextFilterValidator::validate(['mobile' => ['138']], ['mobile' => 20]);
    }

    public function testSalesPlanNameAndCodeAreIndependentConditions(): void
    {
        $repository = new \app\modules\product\infrastructure\DatabaseProductRepository(
            new \app\shared\infrastructure\DatabaseTransactionManager(),
            new \app\modules\provider\infrastructure\DatabaseProviderBindingWriter(),
            'cloud_server',
        );
        $validator = new \app\modules\compute\validation\CloudOfferValidator();
        $repository->save(null, $validator->validate(['name' => '香港标准', 'code' => 'hk-basic', 'draft' => ['cycles' => ['month']]]));
        $repository->save(null, $validator->validate(['name' => '美国标准', 'code' => 'us-basic', 'draft' => ['cycles' => ['month']]]));

        self::assertSame(1, $repository->paginate('', 1, 10, ['name' => '香港', 'code' => 'hk'])['total']);
        self::assertSame(0, $repository->paginate('', 1, 10, ['name' => '香港', 'code' => 'us'])['total']);
    }
}
