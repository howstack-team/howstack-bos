<?php

declare(strict_types=1);
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use app\controller\FrontendController;
use app\controller\FrontendAssetController;
use app\controller\IndexController;
use app\modules\api_documentation\controller\ApiDocumentationController;
use app\modules\api_documentation\middleware\DocumentationAccessMiddleware;
use app\modules\install\controller\InstallController;
use app\modules\install\middleware\InstallGuardMiddleware;
use support\Container;
use support\Request;
use support\Response;
use Webman\Route;

Route::disableDefaultRoute();

Route::any('/', [IndexController::class, 'index']);


// HTML 和原始契约不放在 public 中；静态 JS/CSS 使用已构建的本地资源。
Route::get('/api-docs', [ApiDocumentationController::class, 'page'])->middleware([DocumentationAccessMiddleware::class]);
Route::get('/api-docs/', [ApiDocumentationController::class, 'page'])->middleware([DocumentationAccessMiddleware::class]);
Route::get('/api-docs/specs/{group:admin|console|public|partner}.json', [ApiDocumentationController::class, 'specification'])
    ->middleware([DocumentationAccessMiddleware::class]);

Route::get('/install', [InstallController::class, 'page']);
Route::get('/api/install/bootstrap', [InstallController::class, 'bootstrap']);
Route::get('/api/install/random-defaults', [InstallController::class, 'randomDefaults']);
Route::post('/api/install/test-connections', [InstallController::class, 'testConnections']);
Route::post('/api/install/execute', [InstallController::class, 'execute']);

// 构建资源地址与页面入口一致；资源路由在 SPA catch-all 前注册。
Route::get('/{app:admin|console}/{path:assets/.+|logo\.png|favicon\.svg|icons\.svg}', [FrontendAssetController::class, 'show']);
Route::get('/console/', [FrontendController::class, 'console']);
Route::get('/console[/{path:.+}]', [FrontendController::class, 'console']);
Route::get('/app/{app:admin|console}/', [FrontendController::class, 'redirectLegacy']);
Route::get('/app/{app:admin|console}[/{path:.+}]', [FrontendController::class, 'redirectLegacy']);
Route::fallback(static function (Request $request): Response {
    $path = ltrim($request->path(), '/');
    $first = explode('/', $path)[0];
    $admin = Container::get(\app\modules\install\contract\InstallationStateRepositoryInterface::class)->adminPath();
    if ($first === $admin) return Container::get(FrontendController::class)->admin($path);
    if (in_array($first, ['api', 'admin', 'console', 'install', 'api-docs', 'app', 'theme-assets', 'install-assets', 'storage', 'website-preview'], true)) return response('Not Found', 404);
    return Container::get(\app\modules\website\controller\WebsiteController::class)->show($request);
})->middleware([InstallGuardMiddleware::class]);
