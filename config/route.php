<?php
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

use Webman\Route;

Route::disableDefaultRoute();

Route::get('/', [app\controller\IndexController::class, 'index']);

Route::get('/sessions', [app\controller\SessionController::class, 'index']);
Route::post('/sessions', [app\controller\SessionController::class, 'create']);
Route::delete('/sessions/{id}', [app\controller\SessionController::class, 'delete']);
Route::get('/sessions/{id}/render', [app\controller\SessionController::class, 'render']);
Route::post('/sessions/{id}/docs', [app\controller\SessionController::class, 'upload']);
Route::post('/sessions/{id}/chat', [app\controller\SessionController::class, 'chat']);
Route::post('/sessions/{id}/approve', [app\controller\SessionController::class, 'approve']);
