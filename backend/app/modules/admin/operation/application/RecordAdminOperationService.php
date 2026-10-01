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
        $module = match ($resource) {
            'administrators' => '系统用户',
            'roles' => '角色权限',
            'departments' => '部门管理',
            'settings' => '系统设置',
            'members' => '会员管理',
            'member-groups' => '会员分组',
            'member-levels' => '会员等级',
            'real-name-authentications' => '实名认证',
            default => '后台管理',
        };
        $verb = match ($request->method()) {
            'POST' => '新增', 'PUT', 'PATCH' => '编辑', 'DELETE' => '删除',
            default => '操作',
        };
        $ip = $this->ips->resolve($request);
        $agent = UserAgentSummary::parse((string) $request->header('user-agent', ''));
        $httpStatus = $response?->getStatusCode() ?? $this->failureStatus($failure);
        $auditInput = in_array($resource, ['administrators', 'roles', 'departments', 'settings', 'members', 'member-groups', 'member-levels', 'real-name-authentications'], true) ? $request->post() : [];
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
