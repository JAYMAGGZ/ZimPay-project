<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\MerchantController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check (public)
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Authentication routes (public)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected routes (require authentication)
Route::middleware('auth.jwt')->group(function () {
    // Auth routes
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    
    // User routes
    Route::get('/user', function (Request $request) {
        $user = $request->user()->load('wallet', 'merchant');
        return response()->json([
            'success' => true,
            'user' => $user
        ]);
    });

    // Wallet routes
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'getBalance']);
        Route::get('/summary', [WalletController::class, 'getSummary']);
        Route::post('/top-up', [WalletController::class, 'topUp']);
    });

    // Transaction routes
    Route::prefix('transactions')->group(function () {
        Route::get('/history', [TransactionController::class, 'getHistory']);
        Route::get('/{reference}', [TransactionController::class, 'getTransaction']);
        Route::post('/initiate', [TransactionController::class, 'initiatePayment']);
    });

    // Merchant routes
    Route::prefix('merchant')->group(function () {
        Route::get('/profile', [MerchantController::class, 'getProfile']);
        Route::get('/dashboard', [MerchantController::class, 'getDashboard']);
        Route::post('/qr-code', [MerchantController::class, 'generateQrCode']);
    });
});
