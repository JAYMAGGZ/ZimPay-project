<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Wallet Controller
 * 
 * Handles wallet balance queries and top-up operations
 */
class WalletController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Get wallet balance for authenticated user.
     * 
     * GET /api/wallet/balance
     * Headers: Authorization: Bearer {token}
     */
    public function getBalance(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'balance' => $wallet->balance,
                'formatted_balance' => $wallet->formatted_balance,
                'currency' => $wallet->currency,
                'last_updated' => $wallet->updated_at->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Top up wallet balance (simulated for prototype).
     * 
     * POST /api/wallet/topup
     * Body: {amount}
     * Headers: Authorization: Bearer {token}
     */
    public function topUp(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            // Process top-up using payment service
            $result = $this->paymentService->topUpWallet($user->id, $request->amount);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Wallet topped up successfully',
                    'new_balance' => $result['new_balance'],
                    'amount_added' => $request->amount,
                ], 200);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get wallet transaction summary.
     * 
     * GET /api/wallet/summary
     * Headers: Authorization: Bearer {token}
     */
    public function getSummary(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            $wallet = $user->wallet;

            // Get transaction statistics
            $totalSent = $user->sentTransactions()
                ->where('status', 'completed')
                ->sum('amount');

            $totalReceived = $user->receivedTransactions()
                ->where('status', 'completed')
                ->sum('amount');

            $transactionCount = $user->sentTransactions()->count() + 
                               $user->receivedTransactions()->count();

            return response()->json([
                'success' => true,
                'summary' => [
                    'current_balance' => $wallet->balance,
                    'total_sent' => $totalSent,
                    'total_received' => $totalReceived,
                    'transaction_count' => $transactionCount,
                    'currency' => $wallet->currency,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
