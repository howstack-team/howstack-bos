<?php

declare(strict_types=1);

namespace app\modules\admin\access\domain;

/**
 * 定义运营后台可授权的稳定权限码。
 */
final class PermissionCatalog
{
    /**
     * 返回权限目录；业务权限由代码发布，不接受数据库中的未知权限码。
     * @return array<string, array{label: string, group: string}> 权限码及展示信息
     */
    public static function all(): array
    {
        $catalog = [];
        foreach ([
            'dashboard' => '工作台', 'analysis' => '分析页', 'tasks' => '待办事项',
            'resource-pool' => '资源池', 'upstream' => '上游管理',
            'product-list' => '产品列表', 'product-category' => '产品分类', 'product-pricing' => '定价配置',
            'renewal-list' => '续费管理', 'refund-list' => '旧版退款占位',
            'transactions' => '旧版资金流水占位', 'invoices' => '旧版发票占位',
            'settlement' => '上下游结算', 'campaigns' => '活动管理', 'coupons' => '优惠券',
            'referrals' => '邀请返利', 'website' => '官网与主题',
            'applications' => '应用管理', 'application-market' => '应用市场', 'connectors' => '对接插件',
            'settings' => '系统设置', 'operation-logs' => '操作日志', 'login-logs' => '登录日志',
            'system-logs' => '系统日志',
        ] as $key => $label) {
            $catalog['admin.page.' . $key . '.view'] = ['label' => $label, 'group' => $key === 'login-logs' ? '审计日志' : '页面访问（Mock）'];
            $catalog['admin.page.' . $key . '.create'] = ['label' => '新建' . $label, 'group' => '页面操作（Mock）'];
            $catalog['admin.page.' . $key . '.update'] = ['label' => '编辑' . $label, 'group' => '页面操作（Mock）'];
        }
        foreach (['administrators' => '管理员', 'roles' => '角色权限', 'departments' => '部门'] as $resource => $label) {
            foreach (['view' => '查看', 'create' => '新增', 'update' => '编辑', 'delete' => '删除'] as $action => $verb) {
                $catalog['admin.' . $resource . '.' . $action] = ['label' => $verb . $label, 'group' => '权限管理'];
            }
        }
        foreach (['view' => '查看', 'create' => '新增', 'update' => '编辑', 'delete' => '删除'] as $action => $verb) {
            $catalog['admin.members.' . $action] = ['label' => $verb . '会员', 'group' => '会员管理'];
        }
        foreach (['member-groups' => '会员分组', 'member-levels' => '会员等级'] as $resource => $label) {
            foreach (['view' => '查看', 'create' => '新增', 'update' => '编辑', 'delete' => '删除'] as $action => $verb) {
                $catalog['admin.' . $resource . '.' . $action] = ['label' => $verb . $label, 'group' => '会员管理'];
            }
        }
        $catalog['admin.real-name-authentications.view'] = ['label' => '查看实名认证记录', 'group' => '实名认证'];
        $catalog['admin.real-name-authentications.review'] = ['label' => '审核实名认证', 'group' => '实名认证'];

        $catalog['admin.page.order-list.view'] = ['label' => '查看订单及订单项', 'group' => '财务管理'];
        foreach ([
            'cloud-servers' => '云服务器', 'physical-servers' => '物理服务器',
            'server-colocations' => '服务器托管',
        ] as $resource => $label) {
            $catalog['admin.services.' . $resource . '.view'] = ['label' => '查看' . $label . '实例', 'group' => '业务管理'];
        }
        foreach (['types' => '工单类型', 'quick-replies' => '快捷回复', 'list' => '工单列表'] as $resource => $label) {
            foreach (($resource === 'list' ? ['view' => '查看', 'reply' => '回复'] :
                ['view' => '查看', 'create' => '新增', 'update' => '编辑', 'delete' => '删除']) as $action => $verb) {
                $catalog['admin.tickets.' . $resource . '.' . $action] = ['label' => $verb . $label, 'group' => '服务管理'];
            }
        }
        foreach (['help' => '帮助文档', 'news' => '新闻公告'] as $kind => $label) {
            foreach (['entries' => $label, 'categories' => $label . '分类'] as $resource => $name) {
                foreach (['view' => '查看', 'create' => '新增', 'update' => '编辑', 'delete' => '删除'] as $action => $verb) {
                    $catalog['admin.content.' . $kind . '-' . $resource . '.' . $action] = ['label' => $verb . $name, 'group' => '内容管理'];
                }
            }
        }
        foreach ([
            'recharges' => '充值记录', 'payments' => '支付记录', 'transactions' => '资金流水',
            'refunds' => '退款记录', 'invoices' => '发票记录', 'withdrawals' => '提现记录',
        ] as $resource => $label) {
            $catalog['admin.finance.' . $resource . '.view'] = ['label' => '查看' . $label, 'group' => '财务管理'];
        }

        $catalog['admin.page.settings.view'] = ['label' => '查看系统设置', 'group' => '系统设置'];
        $catalog['admin.page.settings.update'] = ['label' => '修改系统设置', 'group' => '系统设置'];

        return $catalog;
    }

    /**
     * 检查一组权限码是否全部属于当前发布的目录。
     * @param list<string> $codes 待校验的权限码
     * @return bool 全部合法时返回 true
     */
    public static function containsAll(array $codes): bool
    {
        return array_diff($codes, array_keys(self::all())) === [];
    }
}
