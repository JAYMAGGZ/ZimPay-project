<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Merchant Controller
 * 
 * Handles merchant-specific operations: QR code generation, analytics dashboard
 */
class MerchantController extends Controller
{
    /**
     * Generate QR code data for merchant.
     * 
     * POST /api/merchant/qr-code
     * Headers: Authorization: Bearer {token}
     */
    public function generateQRCode(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            if (!$user->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only merchants can generate QR codes',
                ], 403);
            }

            $merchant = $user->merchant;

            if (!$merchant) {
                return response()->json([
                    'success' => false,
                    'error' => 'Merchant profile not found',
                ], 404);
            }

            // Generate QR code data
            $qrData = $merchant->generateQRCodeData();

            // Save to database
            $merchant->qr_code_data = $qrData;
            $merchant->save();

            return response()->json([
                'success' => true,
                'qr_code_data' => $qrData,
                'merchant_code' => $merchant->merchant_code,
                'business_name' => $merchant->business_name,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merchant dashboard analytics.
     * 
     * GET /api/merchant/dashboard
     * Query params: ?period=today|week|month|all
     * Headers: Authorization: Bearer {token}
     */
    public function getDashboard(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            if (!$user->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only merchants can access dashboard',
                ], 403);
            }

            $merchant = $user->merchant;
            $period = $request->query('period', 'all');

            // Calculate date range
            $startDate = null;
            switch ($period) {
                case 'today':
                    $startDate = now()->startOfDay();
                    break;
                case 'week':
                    $startDate = now()->startOfWeek();
                    break;
                case 'month':
                    $startDate = now()->startOfMonth();
                    break;
                default:
                    $startDate = null; // All time
            }

            // Get metrics
            $totalSales = $merchant->getTotalSales();
            $periodSales = $startDate ? $merchant->getSalesForPeriod($startDate) : $totalSales;
            $transactionCount = $merchant->getTransactionCount($startDate);
            $allTimeTransactionCount = $merchant->getTransactionCount();
            $averageTransaction = $merchant->getAverageTransactionValue($startDate);

            // Get recent transactions
            $recentTransactions = $merchant->transactions()
                ->when($startDate, function($query) use ($startDate) {
                    return $query->where('created_at', '>=', $startDate);
                })
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($transaction) {
                    return [
                        'reference' => $transaction->transaction_reference,
                        'amount' => $transaction->formatted_amount,
                        'sender' => $transaction->sender->phone_number,
                        'payment_method' => strtoupper($transaction->payment_method),
                        'status' => ucfirst($transaction->status),
                        'date' => $transaction->created_at->format('d M Y, H:i'),
                    ];
                });

            return response()->json([
                'success' => true,
                'merchant' => [
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'location' => $merchant->location,
                ],
                'analytics' => [
                    'period' => ucfirst($period),
                    'total_sales_all_time' => number_format($totalSales, 2),
                    'sales_this_period' => number_format($periodSales, 2),
                    'transaction_count' => $transactionCount,
                    'transaction_count_all_time' => $allTimeTransactionCount,
                    'average_transaction' => number_format($averageTransaction, 2),
                    'wallet_balance' => $user->wallet->formatted_balance,
                ],
                'recent_transactions' => $recentTransactions,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merchant profile details.
     * 
     * GET /api/merchant/profile
     * Headers: Authorization: Bearer {token}
     */
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            if (!$user->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only merchants can access profile',
                ], 403);
            }

            $merchant = $user->merchant;

            return response()->json([
                'success' => true,
                'merchant' => [
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'qr_code_data' => $merchant->qr_code_data,
                    'location' => $merchant->location,
                    'phone_number' => $user->phone_number,
                    'wallet_balance' => $user->wallet->formatted_balance,
                    'created_at' => $merchant->created_at->format('d M Y'),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update merchant profile.
     * 
     * PUT /api/merchant/profile
     * Body: {business_name?, location?}
     * Headers: Authorization: Bearer {token}
     */
    public function updateProfile(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'business_name' => 'sometimes|string|max:100',
            'location' => 'sometimes|string|max:100',
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

            if (!$user->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only merchants can update profile',
                ], 403);
            }

            $merchant = $user->merchant;

            // Update fields if provided
            if ($request->has('business_name')) {
                $merchant->business_name = $request->business_name;
            }
            if ($request->has('location')) {
                $merchant->location = $request->location;
            }

            $merchant->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'merchant' => [
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'location' => $merchant->location,
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
