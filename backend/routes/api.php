<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportBatchController;
use App\Http\Controllers\MovieController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/movies', [MovieController::class, 'index']);
Route::get('/movies/{id}', [MovieController::class, 'show']);
Route::post('/upload', [MovieController::class, 'upload']);
Route::get('/proxy-image', [MovieController::class, 'proxyImage']);

// 导入批次：列表 / 详情（回看批次导入了哪些影片）/ 按批次撤销
Route::get('/import-batches', [ImportBatchController::class, 'index']);
Route::get('/import-batches/{id}', [ImportBatchController::class, 'show']);
Route::post('/import-batches/{id}/revert', [ImportBatchController::class, 'revert']);

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});