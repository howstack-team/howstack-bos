<?php

declare(strict_types=1);

namespace app\exception;

use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use JsonException;
use Psr\Log\LoggerInterface;
use support\exception\Handler as BaseHandler;
use Throwable;
use Webman\Exception\BusinessException as FrameworkBusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 应用全局异常处理器。
 *
 * API 只暴露可安全展示的业务异常；未预期异常会记录完整日志，并向调用方返回
 * 不含堆栈、文件路径或数据库信息的通用错误响应。
 */
final class Handler extends BaseHandler
{
    /**
     * @var list<class-string<Throwable>> 无需写入系统错误日志的异常类型
     */
    public $dontReport = [
        BusinessException::class,
        FrameworkBusinessException::class,
    ];

    /**
     * 初始化全局异常处理器。
     * @param LoggerInterface $logger      系统日志记录器
     * @param bool            $debug       是否启用调试模式
     * @param ApiResponse     $apiResponse 统一 API 响应生成器
     */
    public function __construct(
        LoggerInterface $logger,
        bool $debug,
        private readonly ApiResponse $apiResponse,
    ) {
        parent::__construct($logger, $debug);
    }

    /**
     * 记录未预期的系统异常。
     *
     * 可预期业务异常不会进入系统错误日志，避免污染故障告警。
     *
     * @param Throwable $exception 待报告异常
     * @return void
     * @throws \Random\RandomException 当前请求缺少合法追踪标识且无法生成随机标识时抛出
     */
    public function report(Throwable $exception): void
    {
        if ($exception instanceof \app\modules\official_platform\exception\OfficialPlatformException) {
            if ($exception->getPrevious() !== null) {
                $this->logger->error('官网请求未完成', ['reason' => $exception->reason, 'exception_type' => $exception->getPrevious()::class]);
            }
            return;
        }
        $reportableBusinessFailure = $exception instanceof BusinessException
            && $exception->httpStatus() >= 500
            && $exception->getPrevious() !== null;
        if (!$reportableBusinessFailure && $this->shouldntReport($exception)) {
            return;
        }

        $context = ['exception' => $exception];
        $request = request();
        if ($request instanceof Request) {
            $context += [
                'request_id' => $this->apiResponse->requestId($request),
                'method' => $request->method(),
                'path' => $request->path(),
                'client_ip' => $request->getRealIp(),
            ];
        }

        $this->logger->error(
            $reportableBusinessFailure ? '业务异常包含未预期的底层错误' : '未捕获的系统异常',
            $context,
        );
    }

    /**
     * 将异常转换为 HTML 或统一 API 响应。
     * @param Request   $request   当前 HTTP 请求
     * @param Throwable $exception 待渲染异常
     * @return Response 异常响应
     * @throws JsonException API 错误响应无法序列化时抛出
     * @throws \Random\RandomException 当前请求缺少合法追踪标识且无法生成随机标识时抛出
     */
    public function render(Request $request, Throwable $exception): Response
    {
        if (!$this->isApiRequest($request)) {
            return parent::render($request, $exception);
        }

        if ($exception instanceof BusinessException) {
            return $this->apiResponse->error(
                $request,
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->httpStatus(),
                $exception->data(),
            );
        }

        if ($exception instanceof FrameworkBusinessException) {
            $frameworkCode = (int) $exception->getCode();
            $httpStatus = $frameworkCode >= 400 && $frameworkCode <= 599
                ? $frameworkCode
                : 422;
            $errorCode = $frameworkCode >= 10000
                ? $frameworkCode
                : ApiErrorCode::fromHttpStatus($httpStatus);

            return $this->apiResponse->error(
                $request,
                $errorCode,
                $exception->getMessage(),
                $httpStatus,
                $exception->getData() ?: null,
            );
        }

        return $this->apiResponse->error(
            $request,
            ApiErrorCode::INTERNAL_ERROR,
            '服务器内部错误，请稍后重试',
            500,
        );
    }

    /**
     * 判断请求是否应使用统一 API 错误格式。
     * @param Request $request 当前 HTTP 请求
     * @return bool API 路径或期望 JSON 响应时返回 true
     */
    private function isApiRequest(Request $request): bool
    {
        $path = $request->path();

        return $path === '/api'
            || $path === '/api-docs'
            || str_starts_with($path, '/api-docs/')
            || str_starts_with($path, '/api/')
            || $request->expectsJson();
    }
}
