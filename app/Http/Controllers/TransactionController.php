<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

/**
 * Transaction Controller
 * 
 * Handles payment initiation, confirmation, and transaction history
 */
class TransactionController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Initiate a payment transaction.
     * 
     * POST /api/transactions/initiate
     * Body: {receiver_id OR merchant_code, amount, payment_method, pin}
     * Headers: Authorization: Bearer {token}
     */
    public function initiate(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|max:10000',
            'payment_method' => 'required|in:qr,ussd,nfc',
            'pin' => 'required|string',
            'receiver_id' => 'required_without:merchant_code|integer|exists:users,id',
            'merchant_code' => 'required_without:receiver_id|string|exists:merchants,merchant_code',
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

            // Verify PIN
            if (!Hash::check($request->pin, $user->pin_hash)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid PIN',
                ], 401);
            }

            // Determine receiver ID
            if ($request->has('merchant_code')) {
                $merchant = Merchant::where('merchant_code', $request->merchant_code)->first();
                $receiverId = $merchant->user_id;
            } else {
                $receiverId = $request->receiver_id;
            }

            // Prevent self-payment
            if ($user->id === $receiverId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot send payment to yourself',
                ], 400);
            }

            // Process payment
            $result = $this->paymentService->processPayment(
                $user->id,
                $receiverId,
                $request->amount,
                $request->payment_method
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment successful',
                    'transaction' => [
                        'reference' => $result['transaction']->transaction_reference,
                        'amount' => $result['transaction']->formatted_amount,
                        'status' => $result['transaction']->status,
                        'created_at' => $result['transaction']->created_at->toIso8601String(),
                    ],
                    'receipt' => $result['receipt'],
                    'new_balance' => $result['new_balance'],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'transaction' => $result['transaction'] ? [
                    'reference' => $result['transaction']->transaction_reference,
                    'status' => $result['transaction']->status,
                ] : null,
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Transaction failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction history for authenticated user.
     * 
     * GET /api/transactions/history
     * Query params: ?limit=20
     * Headers: Authorization: Bearer {token}
     */
    public function getHistory(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            $limit = $request->query('limit', 20);
            $history = $this->paymentService->getTransactionHistory($user->id, $limit);

            return response()->json([
                'success' => true,
                'transactions' => $history,
                'count' => count($history),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction details by reference.
     * 
     * GET /api/transactions/{reference}
     * Headers: Authorization: Bearer {token}
     */
    public function getByReference(Request $request, string $reference)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            $transaction = Transaction::where('transaction_reference', $reference)
                ->where(function($query) use ($user) {
                    $query->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                })
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'error' => 'Transaction not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'transaction' => [
                    'reference' => $transaction->transaction_reference,
                    'amount' => $transaction->formatted_amount,
                    'sender' => $transaction->sender->phone_number,
                    'receiver' => $transaction->receiver->merchant->business_name ?? $transaction->receiver->phone_number,
                    'payment_method' => strtoupper($transaction->payment_method),
                    'status' => ucfirst($transaction->status),
                    'date' => $transaction->created_at->format('d M Y, H:i'),
                    'error_message' => $transaction->error_message,
                ],
                'receipt' => $transaction->getReceiptData(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
