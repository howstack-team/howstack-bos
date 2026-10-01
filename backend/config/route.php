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
use app\modules\install\controller\InstallController;
use app\modules\install\middleware\InstallGuardMiddleware;
use support\Container;
use support\Request;
use support\Response;
use Webman\Route;

Route::disableDefaultRoute();

Route::get('/', [IndexController::class, 'index']);

Route::get('/install', [InstallController::class, 'page']);
Route::get('/api/install/bootstrap', [InstallController::class, 'bootstrap']);
Route::get('/api/install/random-defaults', [InstallController::class, 'randomDefaults']);
Route::post('/api/install/test-connections', [InstallController::class, 'testConnections']);
Route::post('/api/install/execute', [InstallController::class, 'execute']);

Route::get('/console[/{path:.+}]', [FrontendController::class, 'console']);
// Webman 会把 /app/<name> 当作应用插件目录；构建资源需从受控 public/app 目录显式提供。
Route::get('/app/{app:admin|console}/{path:.+}', [FrontendAssetController::class, 'show']);
Route::fallback(static function (Request $request): Response {
    return Container::get(FrontendController::class)->admin(ltrim($request->path(), '/'));
})->middleware([InstallGuardMiddleware::class]);
