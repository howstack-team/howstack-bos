<?php

declare(strict_types=1);

namespace app\modules\support\controller;

use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\{MemberAuthMiddleware, MemberWriteOriginMiddleware};
use app\modules\support\application\TicketConversationService;
use app\modules\support\middleware\TicketWriteRateLimitMiddleware;
use app\modules\support\validation\{MemberTicketValidator, SupportValidator};
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post, RouteGroup};
use support\{Request, Response};

#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/tickets')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberSupportController
{
    /**
     * 注入工单用例依赖。
     * @param TicketConversationService $tickets 显式注入的业务依赖
     * @param MemberAuthenticationContext $context 显式注入的业务依赖
     * @param ApiResponse $responses 显式注入的业务依赖
     */
    public function __construct(private TicketConversationService $tickets, private MemberAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '/types', name: 'member.tickets.types')]
    /**
     * 读取可提交的启用类型。
     * @param Request $request 当前已认证HTTP请求
     * @return Response 已校验结果或统一响应
     */
    public function types(Request $request): Response
    {
        $this->context->account($request);
        return $this->read($request, $this->tickets->types());
    }

    #[Get(path: '', name: 'member.tickets.index')]
    /**
     * 响应本人工单分页。
     * @param Request $request 当前已认证HTTP请求
     * @return Response 已校验结果或统一响应
     */
    public function listing(Request $request): Response
    {
        $member = $this->context->account($request); $query = MemberTicketValidator::page($request->get());
        return $this->read($request, $this->tickets->page($member->id, $query['status'], $query['page'], $query['page_size']));
    }

    #[Get(path: '/{id:\d+}', name: 'member.tickets.show')]
    /**
     * 读取本人会话详情。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $id 目标工单内部ID
     * @return Response 已校验结果或统一响应
     */
    public function detail(Request $request, mixed $id): Response
    {
        return $this->read($request, $this->tickets->detail($this->context->account($request)->id, SupportValidator::id($id)));
    }

    #[Get(path: '/{id:\d+}/messages', name: 'member.tickets.messages')]
    /**
     * 读取最多50条会话窗口。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $id 目标工单内部ID
     * @return Response 已校验结果或统一响应
     */
    public function messages(Request $request, mixed $id): Response
    {
        return $this->read($request, $this->tickets->memberMessages($this->context->account($request)->id, SupportValidator::id($id), MemberTicketValidator::cursor($request->get())));
    }

    #[Post(path: '', name: 'member.tickets.store')]
    #[Middleware(MemberWriteOriginMiddleware::class, TicketWriteRateLimitMiddleware::class)]
    /**
     * 验证本人关联并提交工单。
     * @param Request $request 当前已认证HTTP请求
     * @return Response 已校验结果或统一响应
     */
    public function create(Request $request): Response
    {
        return $this->responses->success($request, $this->tickets->create($this->context->account($request)->id, MemberTicketValidator::create($request->post())), '工单已提交', 201);
    }

    #[Post(path: '/{id:\d+}/replies', name: 'member.tickets.reply')]
    #[Middleware(MemberWriteOriginMiddleware::class, TicketWriteRateLimitMiddleware::class)]
    /**
     * 提交本人追问。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $id 目标工单内部ID
     * @return Response 已校验结果或统一响应
     */
    public function reply(Request $request, mixed $id): Response { return $this->command($request, $id, true); }

    #[Post(path: '/{id:\d+}/close', name: 'member.tickets.close')]
    #[Middleware(MemberWriteOriginMiddleware::class, TicketWriteRateLimitMiddleware::class)]
    /**
     * 关闭本人工单。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $id 目标工单内部ID
     * @return Response 已校验结果或统一响应
     */
    public function close(Request $request, mixed $id): Response { return $this->command($request, $id, false); }

    /**
     * 在当前事务中串行化命令并保存幂等结果。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $id 目标工单内部ID
     * @param bool $reply 是否为回复动作
     * @return Response 已校验结果或统一响应
     */
    private function command(Request $request, mixed $id, bool $reply): Response
    {
        $actor = $this->context->account($request); $input = MemberTicketValidator::command($request->post(), $reply);
        $key = $input['request_key']; unset($input['request_key']);
        return $this->responses->success($request, $this->tickets->execute('member', $actor->id, $reply ? 'reply' : 'close', SupportValidator::id($id), $input, $key), $reply ? '追问已提交' : '工单已关闭');
    }

    /**
     * 输出禁止缓存的私人响应。
     * @param Request $request 当前已认证HTTP请求
     * @param mixed $data 显式注入的业务依赖
     * @return Response 已校验结果或统一响应
     */
    private function read(Request $request, mixed $data): Response
    {
        return $this->responses->success($request, $data)->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
