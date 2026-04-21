<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\MerchantController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| ZimPay API Routes
| All routes return JSON responses
|
*/

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected routes (require JWT authentication)
Route::middleware('auth.jwt')->group(function () {
    
    // User routes
    Route::get('/user', [AuthController::class, 'getUser']);
    
    // Wallet routes
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'getBalance']);
        Route::post('/topup', [WalletController::class, 'topUp']);
        Route::get('/summary', [WalletController::class, 'getSummary']);
    });
    
    // Transaction routes
    Route::prefix('transactions')->group(function () {
        Route::post('/initiate', [TransactionController::class, 'initiate']);
        Route::get('/history', [TransactionController::class, 'getHistory']);
        Route::get('/{reference}', [TransactionController::class, 'getByReference']);
    });
    
    // Merchant routes (merchant role only)
    Route::prefix('merchant')->group(function () {
        Route::post('/qr-code', [MerchantController::class, 'generateQRCode']);
        Route::get('/dashboard', [MerchantController::class, 'getDashboard']);
        Route::get('/profile', [MerchantController::class, 'getProfile']);
        Route::put('/profile', [MerchantController::class, 'updateProfile']);
    });
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ZimPay API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});
