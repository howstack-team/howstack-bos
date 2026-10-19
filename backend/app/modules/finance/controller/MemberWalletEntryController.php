<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\finance\application\ListMemberWalletEntriesService;
use app\modules\finance\validation\MemberWalletEntriesValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, RouteGroup};
use support\{Request, Response};

/** 会员资金明细只有 GET 列表，无明细 ID 查询或写入入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/wallet')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberWalletEntryController
{
    /**
     * @param ListMemberWalletEntriesService $entries 只读用例
     * @param MemberAuthenticationContext $context 会话身份
     * @param ApiResponse $responses 统一响应
     */
    public function __construct(private ListMemberWalletEntriesService $entries, private MemberAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    /**
     * @param Request $request 已认证请求
     * @return Response 禁止缓存的本人账本
     */
    #[Get(path: '/entries', name: 'member.wallet.entries')]
    public function listing(Request $request): Response
    {
        $member = $this->context->account($request);
        $page = MemberWalletEntriesValidator::page($request->get());
        return $this->responses->success($request, $this->entries->execute($member->id, $page['page'], $page['page_size']))
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
