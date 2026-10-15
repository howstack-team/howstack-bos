<?php

declare(strict_types=1);

namespace app\controller;

use app\modules\website\controller\WebsiteController;
use support\Request;
use support\Response;

/** 系统根入口委托官网模块，安装检查继续由全局中间件执行。 */
final readonly class IndexController
{
    public function __construct(private WebsiteController $website)
    {
    }

    /** @return Response 默认主题首页 */
    public function index(Request $request): Response
    {
        return $this->website->show($request);
    }
}
