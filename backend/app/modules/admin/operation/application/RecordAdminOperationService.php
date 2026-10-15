<?php

declare(strict_types=1);

namespace app\modules\admin\operation\application;

use app\exception\BusinessException;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\domain\UserAgentSummary;
use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\contract\IpLocationResolverInterface;
use app\shared\http\ApiResponse;
use Throwable;
use Webman\Exception\BusinessException as FrameworkBusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/** 记录经过身份认证的后台写操作及有限的审计参数。 */
final readonly class RecordAdminOperationService
{
    public function __construct(
        private OperationLogRepositoryInterface $logs,
        private ClientIpResolverInterface $ips,
        private IpLocationResolverInterface $locations,
        private ApiResponse $responses,
        private OperationLogPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * 保存写操作结果，不保存完整请求或响应报文。
     * @param Request $request 当前请求
     * @param AdminAccount $actor 已认证管理员
     * @param Response|null $response 正常返回的响应
     * @param Throwable|null $failure 下游抛出的异常
     * @param int $durationMs 不含日志写入的处理耗时
     */
    public function record(Request $request, AdminAccount $actor, ?Response $response, ?Throwable $failure, int $durationMs): void
    {
        $path = $request->path();
        $segments = explode('/', trim($path, '/'));
        $resource = $segments[3] ?? '';
        $targetId = $segments[4] ?? null;
        if ($resource === 'message-logs' && $targetId === 'deliveries') {
            $targetId = $segments[5] ?? null;
        }
        if ($resource === 'message-configurations') {
            $targetId = $segments[5] ?? null;
        }
        if ($resource === 'channels') {
            $targetId = ($segments[5] ?? '') === 'platforms' ? ($segments[6] ?? null) : ($segments[5] ?? null);
        }
        if ($resource === 'cloud-servers' && $targetId === 'delivery-rules') {
            $resource = 'cloud-delivery-rules';
            $targetId = $segments[5] ?? null;
        }
        if ($resource === 'finance') {
            $targetId = $segments[5] ?? null;
        }
        if ($resource === 'website' && ($segments[4] ?? '') === 'themes') {
            $targetId = ($segments[5] ?? '') === 'install' ? ($request->context['website.installed_theme_id'] ?? null) : ($segments[5] ?? null);
        }
        if ($resource === 'website' && ($segments[4] ?? '') === 'seo') {
            $resource = 'website-seo';
            $targetId = ($segments[5] ?? '') === 'pages' ? ($segments[6] ?? null) : 'defaults';
        }
        $module = match ($resource) {
            'finance' => '财务管理',
            'orders' => '订单管理',
            'profile' => '个人设置',
            'administrators' => '系统用户',
            'roles' => '角色权限',
            'departments' => '部门管理',
            'settings' => '系统设置',
            'themes' => '主题管理',
            'website' => '官网设置',
            'website-seo' => '页面 SEO',
            'files' => '文件管理',
            'channels' => '通道管理',
            'message-configurations', 'message-scenes' => '消息配置',
            'message-logs' => '消息日志',
            'members' => '会员管理',
            'member-groups' => '会员分组',
            'member-levels' => '会员等级',
            'real-name-authentications' => '实名认证',
            'cloud-products' => '云服务器销售方案',
            'cloud-delivery-rules' => '云服务器交付规则',
            'cloud-servers' => '云服务器配置',
            'compute-resources' => '云服务器配置资源',
            'provider-connections' => '资源供应商',
            'business-plugins' => '插件管理',
            'official-platform' => '官网连接与授权',
            'system-updates' => '系统在线更新',
            'application-center' => '应用中心',
            default => '后台管理',
        };
        $verb = match ($request->method()) {
            'POST' => '新增', 'PUT', 'PATCH' => '编辑', 'DELETE' => '删除',
            default => '操作',
        };
        if ($resource === 'website' && ($segments[4] ?? '') === 'themes') {
            $verb = match ($segments[6] ?? $segments[5] ?? '') { 'install' => '安装主题', 'activate' => '切换主题', 'previews' => '预览主题', default => $verb };
        }
        if ($resource === 'message-logs' && ($segments[6] ?? '') === 'retry') {
            $verb = '重试失败消息：';
        }
        if (in_array($resource, ['message-configurations', 'message-scenes'], true) && $targetId === 'batch') {
            $verb = '批量编辑';
        }
        if ($resource === 'profile') {
            $verb = match ($targetId) { 'password' => '修改密码：', 'mfa' => '管理两步验证：', default => '修改资料：' };
        }
        if (in_array($resource, ['cloud-products', 'provider-connections'], true)) {
            $verb = match ($segments[5] ?? '') {
                'publish' => '发布', 'status' => '启停', 'preview' => '预览', 'test' => '测试',
                'catalog' => '获取供应商资源', 'mappings' => '设置资源对应', default => $verb,
            };
        }
        if ($resource === 'channels' && ($segments[6] ?? '') === 'test') {
            $verb = '测试发送';
        }
        if ($resource === 'business-plugins') {
            $verb = match ($request->post('action')) {
                'install' => '安装', 'enable' => '启用', 'drain' => '停止接单',
                'disable' => '停用', 'upgrade' => '审核更新', 'uninstall' => '卸载',
                default => '管理',
            };
        }
        if ($resource === 'orders') {
            $verb = match ($segments[5] ?? 'create') { 'pay' => '余额付款：', 'cancel' => '取消订单：', 'update' => '编辑备注：', 'preview' => '计算订单费用：', default => '创建订单：' };
        }
        if ($resource === 'finance') {
            $verb = match ($segments[6] ?? 'create') {
                'approve' => '审核通过：', 'reject' => '审核驳回：', 'execute' => '执行余额退款：',
                'issue' => '登记开票：', 'red' => '登记红冲：', 'payout' => '登记打款：',
                default => '创建财务单据：',
            };
        }
        $ip = $this->ips->resolve($request);
        $agent = UserAgentSummary::parse((string) $request->header('user-agent', ''));
        $httpStatus = $response?->getStatusCode() ?? $this->failureStatus($failure);
        $auditInput = in_array($resource, ['files', 'administrators', 'roles', 'departments', 'settings', 'themes', 'website', 'website-seo', 'members', 'member-groups', 'member-levels', 'real-name-authentications', 'cloud-products', 'compute-resources', 'cloud-delivery-rules', 'business-plugins', 'channels', 'message-configurations', 'message-scenes'], true) ? $request->post() : [];
        $this->logs->append([
            'admin_user_id' => $actor->id,
            'operator_username' => $actor->username,
            'operator_nickname' => $actor->nickname,
            'module' => $module,
            'operation' => $verb . $module,
            'method' => $request->method(),
            // 不保存 Host、原始查询字符串或 HTTP 报文。
            'request_url' => mb_strimwidth($path, 0, 1024, ''),
            'status' => $httpStatus < 400 ? 1 : 0,
            'http_status' => $httpStatus,
            'client_ip' => $ip,
            'client_ip_location' => $this->locations->resolve($ip),
            'duration_ms' => max(0, $durationMs),
            'browser' => mb_strimwidth($agent['browser'], 0, 64, ''),
            'operating_system' => mb_strimwidth($agent['operating_system'], 0, 64, ''),
            'request_id' => $this->responses->requestId($request),
            'request_params' => $this->sanitizer->params($resource, $targetId, $auditInput),
            'operated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function failureStatus(?Throwable $failure): int
    {
        if ($failure instanceof BusinessException) {
            return $failure->httpStatus();
        }
        if ($failure instanceof FrameworkBusinessException) {
            $code = (int) $failure->getCode();
            return $code >= 400 && $code <= 599 ? $code : 422;
        }

        return 500;
    }
}
