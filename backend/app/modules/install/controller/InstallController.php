<?php

declare(strict_types=1);

namespace app\modules\install\controller;

use app\modules\install\application\InstallApplicationService;
use app\modules\install\application\InstallationAuthorizationService;
use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\modules\install\domain\InstallationConfiguration;
use app\modules\install\exception\InstallationException;
use app\modules\install\validation\InstallRequestValidator;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use JsonException;
use Random\RandomException;
use support\Request;
use support\Response;

/**
 * 提供在线安装页面和安装流程 API。
 */
final readonly class InstallController
{
    /**
     * 初始化在线安装控制器。
     * @param InstallApplicationService              $installer       安装应用服务
     * @param InstallationStateRepositoryInterface  $stateRepository 安装状态仓储
     * @param ApiResponse                            $apiResponse     统一 API 响应生成器
     */
    public function __construct(
        private InstallApplicationService $installer,
        private InstallationStateRepositoryInterface $stateRepository,
        private ApiResponse $apiResponse,
        private InstallationAuthorizationService $authorization,
    ) {
    }

    /**
     * 返回在线安装器页面。
     * @return Response 安装器 HTML 响应
     * @throws InstallationException 安装器前端资源缺失或不可读取时抛出
     */
    public function page(): Response
    {
        $path = public_path() . '/install-assets/app/index.html';
        if (!is_file($path)) {
            throw new InstallationException('安装页面资源缺失，请重新上传完整的系统安装包', 500);
        }

        $html = file_get_contents($path);
        if ($html === false) {
            throw new InstallationException('安装页面资源无法读取，请检查站点文件权限或重新上传完整安装包', 500);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ]);
    }

    /**
     * 返回安装器初始化数据以及当前版本的软件使用协议。
     * @param Request $request 当前 HTTP 请求
     * @return Response 统一格式的安装器初始化响应
     * @throws InstallationException 软件使用协议文件缺失时抛出
     * @throws JsonException 响应数据无法序列化时抛出
     * @throws RandomException 无法生成安装令牌或随机默认值时抛出
     */
    public function bootstrap(Request $request): Response
    {
        if ($this->stateRepository->isInstalled()) {
            return $this->apiResponse->error(
                $request,
                ApiErrorCode::CONFLICT,
                '系统已经安装',
                409,
            );
        }

        $session = $request->session();
        $key = (string) $request->header('x-install-key', '');
        if ($key !== '') {
            $session->set('installer.authorization', $this->authorization->authorize($key));
        }
        $this->authorization->assertProof((string) $session->get('installer.authorization', ''));
        $token = (string) $session->get('installer.token', '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $session->set('installer.token', $token);
        }

        $agreementPath = base_path(false) . '/resource/install/SOFTWARE-USE-AGREEMENT.txt';
        $agreementContent = is_file($agreementPath) ? file_get_contents($agreementPath) : false;
        if ($agreementContent === false) {
            throw new InstallationException('软件使用协议文件缺失', 500);
        }

        return $this->apiResponse->success($request, [
            'token' => $token,
            'environment' => $this->installer->environment(),
            'defaults' => $this->installer->randomDefaults(),
            'agreement' => [
                'title' => '皓量云擎云业务运营系统软件使用协议',
                'version' => InstallationConfiguration::AGREEMENT_VERSION,
                'content' => $agreementContent,
            ],
        ]);
    }

    /**
     * 重新生成后台路径和管理员凭据默认值。
     * @param Request $request 当前 HTTP 请求
     * @return Response 统一格式的随机默认值响应
     * @throws InstallationException 安装会话失效时抛出
     * @throws JsonException 响应数据无法序列化时抛出
     * @throws RandomException 无法生成安全随机值时抛出
     */
    public function randomDefaults(Request $request): Response
    {
        $this->assertToken($request);

        return $this->apiResponse->success($request, $this->installer->randomDefaults());
    }

    /**
     * 测试安装配置中的 MySQL 与 Redis 连接。
     * @param Request $request 当前 HTTP 请求
     * @return Response 统一格式的连接测试响应
     * @throws InstallationException 安装会话、配置或连接检测失败时抛出
     * @throws JsonException 响应数据无法序列化时抛出
     */
    public function testConnections(Request $request): Response
    {
        $this->assertToken($request);
        $configuration = InstallRequestValidator::make([
            'configuration' => $request->post(),
        ])->validateConnections();

        return $this->apiResponse->success($request, $this->installer->testConnections($configuration));
    }

    /**
     * 执行一个在线安装阶段。
     * @param Request $request 当前 HTTP 请求
     * @return Response 统一格式的安装阶段响应
     * @throws InstallationException 会话、配置、阶段或持久化操作失败时抛出
     * @throws JsonException 响应数据无法序列化时抛出
     * @throws RandomException 无法生成应用密钥时抛出
     */
    public function execute(Request $request): Response
    {
        $token = $this->assertToken($request);
        $input = InstallRequestValidator::make([
            'phase' => $request->post('phase'),
            'configuration' => $request->post('configuration'),
        ])->validateExecution();

        $result = $this->installer->execute($input['phase'], $input['configuration'], $token);
        if ($input['phase'] === 'finalize') {
            $this->authorization->revoke();
            $request->session()->delete('installer.authorization');
            $request->session()->delete('installer.token');
        }
        return $this->apiResponse->success($request, $result);
    }

    /**
     * 校验请求携带的安装会话令牌。
     * @param Request $request 当前 HTTP 请求
     * @return string 已验证的安装会话令牌
     * @throws InstallationException 安装会话令牌缺失或不匹配时抛出
     */
    private function assertToken(Request $request): string
    {
        $this->authorization->assertProof((string) $request->session()->get('installer.authorization', ''));
        $provided = (string) $request->header('x-install-token', '');
        $expected = (string) $request->session()->get('installer.token', '');
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            throw new InstallationException('安装会话已失效，请刷新页面后重试', 403);
        }

        return $provided;
    }
}
