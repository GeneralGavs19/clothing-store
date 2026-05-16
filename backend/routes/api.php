<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);
Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('dashboard', DashboardController::class);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('logs', [ActivityLogController::class, 'index']);

    Route::middleware('role:admin')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);

        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);

        Route::get('sales-pending', [SaleController::class, 'pending']);
        Route::post('sales/{sale}/approve', [SaleController::class, 'approve']);
        Route::post('sales/{sale}/reject', [SaleController::class, 'reject']);
        Route::delete('sales/{sale}', [SaleController::class, 'destroy']);

        Route::apiResource('users', UserController::class)->except(['show']);

        Route::get('stock-movements', [StockController::class, 'movements']);
        Route::post('stock/transfer', [StockController::class, 'transfer']);
        Route::post('stock/adjust', [StockController::class, 'adjust']);

        Route::get('reports/sales.csv', [ReportController::class, 'salesCsv']);
        Route::get('reports/backup', [ReportController::class, 'backup']);
        Route::post('reports/rebuild-statistics', [ReportController::class, 'rebuildStatistics']);
    });
});
