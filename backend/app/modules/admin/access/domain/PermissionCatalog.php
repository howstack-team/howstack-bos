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
        foreach (['view' => '查看文件库', 'upload' => '上传文件', 'delete' => '删除文件'] as $action => $label) $catalog['admin.files.' . $action] = ['label' => $label, 'group' => '文件管理'];
        foreach (['view' => '查看个人设置', 'update' => '修改个人资料', 'password' => '修改本人密码', 'mfa' => '管理本人两步验证'] as $action => $label) {
            $catalog['admin.profile.' . $action] = ['label' => $label, 'group' => '个人设置'];
        }
        foreach ([
            'dashboard' => '工作台', 'tasks' => '待办事项',
            'resource-pool' => '资源池', 'upstream' => '上游管理',
            'product-list' => '产品列表', 'product-category' => '产品分类', 'product-pricing' => '定价配置',
            'renewal-list' => '续费管理', 'refund-list' => '旧版退款占位',
            'transactions' => '旧版资金流水占位', 'invoices' => '旧版发票占位',
            'settlement' => '上下游结算', 'campaigns' => '活动管理', 'coupons' => '优惠券',
            'referrals' => '邀请返利',
            'applications' => '应用管理', 'application-market' => '应用市场', 'connectors' => '对接插件',
            'settings' => '系统设置', 'operation-logs' => '操作日志', 'login-logs' => '登录日志',
            'system-logs' => '系统日志',
        ] as $key => $label) {
            $catalog['admin.page.' . $key . '.view'] = ['label' => $label, 'group' => $key === 'login-logs' ? '审计日志' : '页面访问（Mock）'];
            $catalog['admin.page.' . $key . '.create'] = ['label' => '新建' . $label, 'group' => '页面操作（Mock）'];
            $catalog['admin.page.' . $key . '.update'] = ['label' => '编辑' . $label, 'group' => '页面操作（Mock）'];
        }
        foreach (['base' => '官网基础设置', 'navigation' => '官网导航管理', 'seo' => '页面 SEO'] as $section => $label) {
            foreach (['view' => '查看', 'update' => '修改'] as $action => $verb) {
                $catalog['admin.website.' . $section . '.' . $action] = ['label' => $verb . $label, 'group' => $label];
            }
        }
        $catalog['admin.page.website.view'] = ['label' => '查看主题管理', 'group' => '主题管理'];
        $catalog['admin.page.website.update'] = ['label' => '修改主题配置及上传图片', 'group' => '主题管理'];
        $catalog['admin.page.website.install'] = ['label' => '安装主题', 'group' => '主题管理'];
        $catalog['admin.page.website.activate'] = ['label' => '切换主题', 'group' => '主题管理'];
        $catalog['admin.page.website.uninstall'] = ['label' => '卸载主题', 'group' => '主题管理'];
        $catalog['admin.page.analysis.view'] = ['label' => '查看经营分析', 'group' => '总览'];
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

        foreach (\app\modules\channel\domain\ChannelCategories::LABELS as $category => $label) {
            foreach (['view' => '查看', 'update' => '配置与启停'] as $action => $verb) {
                $catalog['admin.channels.' . $category . '.' . $action] = ['label' => $verb . $label, 'group' => '通道管理 · ' . $label];
            }
        }

        foreach (['view' => '查看消息配置', 'update' => '编辑消息配置'] as $action => $label) {
            $catalog['admin.message-configurations.' . $action] = ['label' => $label, 'group' => '消息配置'];
        }

        $catalog['admin.message-logs.view'] = ['label' => '查看消息日志', 'group' => '消息日志'];
        $catalog['admin.message-logs.retry'] = ['label' => '重试明确失败的消息', 'group' => '消息日志'];

        $catalog['admin.channels.mail.test'] = ['label' => '测试发送邮件', 'group' => '通道管理 · 邮件通道'];

        $catalog['admin.page.order-list.view'] = ['label' => '查看订单及订单项', 'group' => '财务管理'];
        foreach (['create' => '创建订单', 'update' => '编辑订单备注', 'pay' => '使用会员余额付款', 'cancel' => '取消未支付订单'] as $action => $label) {
            $catalog['admin.page.order-list.' . $action] = ['label' => $label, 'group' => '财务管理'];
        }

        foreach (['deployment' => '地域和可用区', 'specification' => '规格与套餐', 'network' => '线路和网络', 'storage' => '镜像和存储', 'purchase' => '购买与计费'] as $section => $label) {
            $catalog['admin.cloud-configuration.' . $section . '.view'] = ['label' => '查看' . $label, 'group' => '云服务器配置'];
        }
        foreach ([
            'cloud-servers' => ['云服务器概览', ['view' => '查看']],
            'cloud-products' => ['云服务器配置', ['view' => '查看', 'create' => '新增', 'update' => '编辑与启停', 'preview' => '预览']],
            'compute-resources' => ['云服务器配置资源', ['view' => '查看', 'create' => '新增', 'update' => '编辑']],
            'plugins' => ['插件管理', ['view' => '查看', 'install' => '安装', 'update' => '启停与停止接单', 'upgrade' => '升级', 'uninstall' => '卸载']],
            'provider-connections' => ['资源供应商', ['view' => '查看', 'create' => '新增', 'update' => '编辑与启停', 'test' => '测试连接', 'sync' => '获取供应商资源', 'map' => '设置资源对应']],
        ] as $resource => [$label, $actions]) {
            foreach ($actions as $action => $verb) {
                $catalog['admin.' . $resource . '.' . $action] = ['label' => $verb . $label, 'group' => $label];
            }
        }
        foreach ([
            'cloud-servers' => '云服务器', 'physical-servers' => '物理服务器',
            'server-colocations' => '服务器托管',
        ] as $resource => $label) {
            $catalog['admin.services.' . $resource . '.view'] = ['label' => '查看' . $label . '实例', 'group' => '业务管理'];
        }
        $catalog['admin.services.cloud-servers.vnc'] = ['label' => '连接云服务器VNC远程控制台', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.power'] = ['label' => '云服务器开关机与重启', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.reinstall'] = ['label' => '云服务器重装系统', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.reset-password'] = ['label' => '云服务器重置密码', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.renew'] = ['label' => '创建云服务器续费订单', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.lifecycle'] = ['label' => '重试云服务器生命周期与运维查单', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.resolve'] = ['label' => '确认云服务器未开通', 'group' => '业务管理'];
        $catalog['admin.services.cloud-servers.provision'] = ['label' => '重试云服务器开通', 'group' => '业务管理'];
        foreach (['types' => '工单类型', 'quick-replies' => '快捷回复', 'list' => '工单列表'] as $resource => $label) {
            foreach (($resource === 'list' ? ['view' => '查看', 'reply' => '回复', 'close' => '关闭'] :
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
            'refunds' => '退款记录', 'invoices' => '发票记录', 'withdrawals' => '充值退还',
        ] as $resource => $label) {
            $catalog['admin.finance.' . $resource . '.view'] = ['label' => '查看' . $label, 'group' => '财务管理'];
        }

        foreach ([
            'recharges' => ['create' => '线下充值入账'],
            'refunds' => ['create' => '申请退款', 'review' => '审核退款', 'execute' => '执行余额退款'],
            'invoices' => ['create' => '申请开票', 'review' => '审核发票', 'issue' => '登记开票', 'red' => '登记红冲'],
            'withdrawals' => ['create' => '申请提现', 'review' => '审核提现', 'payout' => '登记打款'],
        ] as $resource => $actions) {
            foreach ($actions as $action => $label) {
                $catalog['admin.finance.' . $resource . '.' . $action] = ['label' => $label, 'group' => '财务管理'];
            }
        }

        foreach ([
            'license' => ['授权管理', ['view' => '查看', 'manage' => '验证商业授权']],
            'platform' => ['授权激活', ['connect' => '激活与解除软件授权']],
            'updates' => ['版本更新', ['view' => '查看', 'check' => '检查更新', 'execute' => '执行更新']],
            'app-center' => ['应用中心', ['view' => '查看', 'install' => '安装应用', 'update' => '更新应用', 'uninstall' => '卸载应用']],
        ] as $resource => [$label, $actions]) {
            foreach ($actions as $action => $verb) {
                $catalog['admin.' . $resource . '.' . $action] = ['label' => $verb . $label, 'group' => $label];
            }
        }

        $catalog['admin.page.settings.view'] = ['label' => '查看系统设置', 'group' => '系统设置'];
        $catalog['admin.api-docs.view'] = ['label' => '查看 API 文档', 'group' => '开发工具'];
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
