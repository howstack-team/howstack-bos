<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\member\infrastructure\DatabaseMemberRepository;
use app\modules\member\infrastructure\DatabaseMemberCatalogRepository;
use app\modules\member\infrastructure\DatabaseMemberAuthRepository;
use app\modules\member\infrastructure\DatabaseRealNameAuthenticationRepository;
use app\modules\member\infrastructure\model\MemberModel;
use app\modules\member\infrastructure\model\RealNameAuthenticationModel;
use app\modules\order\infrastructure\DatabaseOrderMemberReference;
use app\modules\order\infrastructure\DatabaseOrderRepository;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\access\domain\AdminDataScope;
use app\modules\admin\auth\infrastructure\DatabaseAdminAccountRepository;
use app\modules\content\infrastructure\DatabaseContentRepository;
use app\modules\support\infrastructure\DatabaseSupportRepository;
use app\modules\system_settings\infrastructure\DatabaseSystemSettingsRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 覆盖 ORM 迁移最容易改变的类型、时间、权限关系及内容字段。 */
final class BusinessModelRepositoriesTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testMemberProfileDatesNullsAndRelationsAreCompatibleWithoutNPlusOne(): void
    {
        $catalog = new DatabaseMemberCatalogRepository();
        $group = $catalog->saveGroup(null, ['name' => '普通客户', 'code' => 'general', 'status' => 1]);
        $level = $catalog->saveLevel(null, ['name' => '一级', 'code' => 'L1', 'status' => 1]);
        $repository = new DatabaseMemberRepository(new DatabaseOrderMemberReference());
        for ($index = 1; $index <= 12; $index++) {
            $repository->save(null, ['nickname' => '会员' . $index, 'mobile' => '13800138' . sprintf('%03d', $index),
                'email' => null, 'group_id' => $group, 'level_id' => $level, 'status' => 1, 'password_hash' => 'secret']);
        }
        Db::connection()->enableQueryLog();
        Db::connection()->flushQueryLog();
        $page = $repository->paginate('会员', 1, 1, 10);
        self::assertCount(4, Db::connection()->getQueryLog());
        Db::connection()->disableQueryLog();
        self::assertSame(12, $page['total']);
        self::assertCount(10, $page['data']);
        $profile = $page['data'][0];
        self::assertIsInt($profile['id']);
        self::assertSame(1, $profile['status']);
        self::assertNull($profile['email']);
        self::assertNull($profile['last_login_at']);
        self::assertSame('普通客户', $profile['group_name']);
        self::assertSame('一级', $profile['level_name']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $profile['created_at']);
        self::assertArrayNotHasKey('password_hash', $profile);
        self::assertArrayNotHasKey('password_hash', MemberModel::query()->first()->toArray());
        $auth = new DatabaseMemberAuthRepository();
        self::assertSame('secret', $auth->findById($profile['id'])->passwordHash);
        $auth->recordLogin($profile['id']);
        self::assertIsString($repository->find($profile['id'])['last_login_at']);
        self::assertSame('orm_members', Db::connection()->getTablePrefix() . (new MemberModel())->getTable());
    }

    public function testAdministratorRolesDepartmentsAndLoginUseModelsWithoutLeakingPassword(): void
    {
        $repository = new DatabaseAdminAccessRepository();
        $root = $repository->departments()[0]['id'];
        $department = $repository->saveDepartment(null, ['parent_id' => $root, 'name' => '售后', 'status' => 1]);
        $role = $repository->saveRole(null, ['name' => '客服', 'code' => 'support', 'status' => 1, 'data_scope' => 'all'],
            ['admin.members.view'], [$department]);
        $id = $repository->saveAdministrator(null, ['username' => 'test-admin', 'nickname' => '客服甲', 'department_id' => $department,
            'created_by' => null, 'password_hash' => 'secret-hash', 'status' => 1], [$role]);
        $auth = new DatabaseAdminAccountRepository();
        self::assertSame('secret-hash', $auth->findByUsername('test-admin')->passwordHash);
        $auth->recordSuccessfulLogin($id, '2026-10-01 09:00:00');
        $admin = $repository->rawAdministrator($id);
        self::assertSame([$role], $admin['role_ids']);
        self::assertSame('2026-10-01 09:00:00', $admin['last_login_at']);
        self::assertArrayNotHasKey('password_hash', $admin);
        self::assertSame(['admin.members.view'], $repository->grantsForAdmin($id)[0]['permissions']);
        self::assertSame([$department], $repository->role($role)['department_ids']);
        Db::connection()->enableQueryLog();
        Db::connection()->flushQueryLog();
        $repository->roles();
        self::assertCount(3, Db::connection()->getQueryLog());
        Db::connection()->disableQueryLog();
        self::assertTrue($repository->departmentIsUsed($department));
        self::assertTrue($repository->roleIsAssigned($role));
        $repository->saveDepartment($department, ['description' => '部门说明', 'sort_order' => 3]);
        $repository->saveRole($role, ['description' => '角色说明', 'sort_order' => 2], ['admin.members.view'], [$department]);
        $repository->saveAdministrator($id, ['nickname' => '客服乙', 'email' => null], [$role]);
        self::assertSame('部门说明', array_values(array_filter($repository->departments(), static fn (array $row): bool => $row['id'] === $department))[0]['description']);
        self::assertSame('角色说明', $repository->role($role)['description']);
        self::assertSame('客服乙', $repository->rawAdministrator($id)['nickname']);
    }

    public function testRealNameReviewKeepsSensitiveFieldsOutOfPublicProfile(): void
    {
        $member = $this->member();
        $repository = new DatabaseRealNameAuthenticationRepository();
        $id = $repository->create(['member_id' => $member, 'authentication_type' => 'personal', 'status' => 'pending',
            'subject_name' => '张某', 'id_type' => 'identity_card', 'id_number_ciphertext' => 'secret',
            'id_number_hash' => str_repeat('a', 64), 'id_number_masked' => '110***********1234']);
        self::assertTrue($repository->hasPending($member));
        self::assertTrue($repository->reviewPending($id, 'approved', 1, ''));
        self::assertFalse($repository->reviewPending($id, 'rejected', 1, '重复审核'));
        $profile = $repository->find($id);
        self::assertSame('approved', $profile['status']);
        self::assertArrayNotHasKey('id_number_ciphertext', $profile);
        self::assertArrayNotHasKey('id_number_hash', RealNameAuthenticationModel::query()->find($id)->toArray());
        self::assertSame($id, (int) $repository->latestApproved($member, 'personal')['id']);
    }

    public function testPersonalAuthenticationCanUpgradeToEnterpriseWithInternalFingerprint(): void
    {
        $member = $this->member();
        $records = new DatabaseRealNameAuthenticationRepository();
        $cipher = new class implements \app\shared\contract\SensitiveDataCipherInterface {
            public function encrypt(string $plaintext): string { return 'encrypted:' . $plaintext; }
            public function decrypt(string $payload): string { return substr($payload, 10); }
            public function fingerprint(string $plaintext): string { return hash('sha256', $plaintext); }
        };
        $service = new \app\modules\member\application\RealNameApplicationService($records,
            new DatabaseMemberRepository(new DatabaseOrderMemberReference()), $cipher);
        $personal = $service->submitPersonal($member, ['real_name' => '测试甲', 'id_type' => 'identity_card', 'id_number' => 'TEST-ID-1234']);
        self::assertTrue($records->reviewPending($personal, 'approved', 1, ''));
        $enterprise = $service->submitEnterprise($member, ['company_name' => '测试企业', 'registration_code' => 'TEST-COMPANY-1234',
            'legal_representative' => '测试甲', 'legal_representative_id_number' => 'TEST-ID-1234']);
        self::assertSame($personal, $records->find($enterprise)['upgrade_from_id']);
        self::assertArrayNotHasKey('id_number_hash', $records->find($enterprise));
        self::assertArrayNotHasKey('registration_code_ciphertext', $records->find($enterprise));
    }

    public function testLoginAndOperationLogsKeepTimeTypesAndDetailWhitelist(): void
    {
        $login = new \app\modules\admin\auth\infrastructure\DatabaseLoginLogRepository();
        $login->append(['admin_user_id' => 1, 'username' => 'fixture-admin', 'nickname' => '测试管理员', 'status' => 1,
            'client_ip' => '127.0.0.1', 'client_ip_location' => '本地', 'browser' => 'Chrome', 'operating_system' => 'macOS',
            'failure_reason' => '', 'logged_at' => '2026-10-01 09:00:00']);
        $page = $login->paginate('fixture', 1, 1, 10);
        self::assertSame(1, $page['total']);
        self::assertSame('2026-10-01 09:00:00', $page['data'][0]['logged_at']);
        self::assertSame(1, $page['data'][0]['status']);
        self::assertArrayNotHasKey('failure_reason', $page['data'][0]);
        $operations = new \app\modules\admin\operation\infrastructure\DatabaseOperationLogRepository();
        $operations->append(['admin_user_id' => 1, 'operator_username' => 'fixture-admin', 'operator_nickname' => '测试管理员',
            'module' => '会员管理', 'operation' => '修改会员', 'method' => 'PATCH', 'request_url' => '/api/admin/v1/members/1',
            'status' => 1, 'http_status' => 200, 'client_ip' => '127.0.0.1', 'client_ip_location' => '本地', 'duration_ms' => 12,
            'browser' => 'Chrome', 'operating_system' => 'macOS', 'request_id' => 'TEST-REQUEST', 'request_params' => '{"nickname":"测试"}',
            'operated_at' => '2026-10-01 09:00:00']);
        $summary = $operations->paginate('会员', 1, 1, 10);
        self::assertSame(1, $summary['total']);
        self::assertSame(12, $summary['data'][0]['duration_ms']);
        self::assertArrayNotHasKey('request_params', $summary['data'][0]);
        $detail = $operations->find($summary['data'][0]['id']);
        self::assertSame('2026-10-01 09:00:00', $detail['operated_at']);
        self::assertSame('{"nickname":"测试"}', $detail['request_params']);
        self::assertSame(200, $detail['http_status']);
        self::assertSame(1, $operations->countBefore('2026-10-02 00:00:00'));
        self::assertSame(1, $operations->deleteBatchBefore('2026-10-02 00:00:00', 10));
    }

    public function testContentSupportAndSettingsWriteThenReadPreservesExistingContracts(): void
    {
        $content = new DatabaseContentRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $category = $content->save(true, null, ['kind' => 'help', 'name' => '指南', 'status' => 1, 'parent_id' => null]);
        $child = $content->save(true, null, ['kind' => 'help', 'name' => '入门', 'status' => 1, 'parent_id' => $category]);
        $entry = $content->save(false, null, ['kind' => 'help', 'category_id' => $child, 'title' => '使用指南', 'body' => '# Markdown', 'status' => 'draft']);
        self::assertSame('# Markdown', $content->find('help', false, $entry)['body']);
        $content->save(false, $entry, ['body' => '# 修改后的 Markdown']);
        self::assertSame('# 修改后的 Markdown', $content->find('help', false, $entry)['body']);
        self::assertSame($child, $content->paginate('help', true, '', null, 1, 10)['data'][0]['children'][0]['id']);
        $support = new DatabaseSupportRepository();
        $type = $support->saveCatalog('ticket_types', null, ['name' => '咨询', 'code' => 'consult', 'status' => 1]);
        $support->saveCatalog('ticket_types', $type, ['name' => '常规咨询']);
        self::assertSame('常规咨询', $support->catalogRow('ticket_types', $type)['name']);
        $member = $this->member();
        $now = '2026-10-01 09:00:00';
        $ticket = $this->row('tickets', ['ticket_no' => 'T1', 'member_id' => $member, 'type_id' => $type, 'subject' => '咨询',
            'content' => '问题', 'created_at' => $now, 'updated_at' => $now]);
        self::assertIsInt($support->reply($ticket, 1, '已收到'));
        self::assertSame('waiting_member', $support->ticket($ticket)['status']);
        self::assertSame('已收到', $support->messages($ticket)[0]['content']);
        $settings = new DatabaseSystemSettingsRepository();
        $settings->save('base', ['site.title' => '测试站点']);
        self::assertSame('测试站点', $settings->values('base')['site.title']);
        $settings->save('base', ['site.title' => '修改后的站点']);
        self::assertSame('修改后的站点', $settings->values('base')['site.title']);
        self::assertCount(4, $settings->categories());
    }

    public function testOrderDetailDoesNotExposeConfigurationSnapshot(): void
    {
        $member = $this->member();
        $id = $this->order($member);
        $repository = new DatabaseOrderRepository();
        $order = $repository->findWithItems($id);
        self::assertSame(1200, $order['payable_minor']);
        self::assertNull($order['paid_at']);
        self::assertArrayNotHasKey('configuration_snapshot', $order['items'][0]);
        self::assertSame(1, $repository->paginate('', null, 1, 10)['total']);
    }
    public function testServiceOriginIsStableAcrossPurchaseRenewalAndUpgradeUnits(): void
    {
        $member = $this->member();
        $first = $this->order($member, 'PURCHASE');
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $service = $this->row('service_instances', ['instance_no' => 'S1', 'product_type' => 'cloud_server',
            'member_id' => $member, 'display_name' => '实例一', 'status' => 'active',
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00']);
        Db::table('order_item_units')->where('id', $unit)->update(['service_id' => $service]);
        Db::table('service_instances')->where('id', $service)->update(['origin_order_unit_id' => $unit]);
        foreach (['RENEWAL', 'UPGRADE'] as $number) {
            $order = $this->order($member, $number);
            $item = Db::table('order_items')->where('order_id', $order)->value('id');
            Db::table('order_item_units')->where('order_item_id', $item)->where('unit_index', 1)->update(['service_id' => $service]);
        }
        $repository = new \app\modules\service_instance\infrastructure\DatabaseServiceInstanceRepository();
        $page = $repository->paginate('cloud_server', '', null, 1, 10);
        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['data']);
        self::assertSame('PURCHASE', $page['data'][0]['order_no']);
        self::assertSame('PURCHASE', $repository->find($service)['order_no']);
        self::assertSame(3, Db::table('order_item_units')->where('service_id', $service)->count());
        // 发票金额仍归属各自交易单位，不改成服务级金额。
        $invoice = $this->row('invoice_records', ['invoice_no' => 'I1', 'member_id' => $member, 'order_id' => $first,
            'amount_minor' => 600, 'invoice_type' => 'personal', 'title' => '测试', 'status' => 'pending',
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00']);
        $this->row('invoice_allocations', ['invoice_id' => $invoice, 'order_unit_id' => $unit, 'amount_minor' => 600]);
        self::assertSame($unit, (int) Db::table('invoice_allocations')->value('order_unit_id'));
        if (getenv('HOWSTACK_MYSQL_TEST') === '1') {
            try {
                Db::table('service_instances')->where('id', $service)->update(['origin_order_unit_id' => 999999]);
                self::fail('MySQL 外键必须拒绝不存在的首次订单单位');
            } catch (\Illuminate\Database\QueryException $error) {
                self::assertSame('23000', $error->errorInfo[0]);
            }
        }
    }

}
