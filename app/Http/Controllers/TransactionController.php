<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\Merchant;
use App\Models\User;

class TransactionController extends Controller
{
    /**
     * Get transaction history
     */
    public function getHistory(Request $request)
    {
        try {
            $limit = $request->input('limit', 20);
            $user = $request->user();

            // Get all transactions (sent and received)
            $sentTransactions = $user->sentTransactions()
                ->with(['receiver.merchant', 'sender.merchant'])
                ->latest()
                ->limit($limit)
                ->get()
                ->map(function ($transaction) use ($user) {
                    return [
                        'id' => $transaction->id,
                        'reference' => $transaction->transaction_reference,
                        'type' => 'sent',
                        'amount' => '$' . number_format($transaction->amount, 2),
                        'counterparty' => $this->resolveCounterpartyLabel($transaction->receiver),
                        'method' => $transaction->payment_method,
                        'status' => $transaction->status,
                        'date' => $transaction->created_at->format('Y-m-d H:i:s')
                    ];
                });

            $receivedTransactions = $user->receivedTransactions()
                ->with(['receiver.merchant', 'sender.merchant'])
                ->latest()
                ->limit($limit)
                ->get()
                ->map(function ($transaction) use ($user) {
                    return [
                        'id' => $transaction->id,
                        'reference' => $transaction->transaction_reference,
                        'type' => 'received',
                        'amount' => '$' . number_format($transaction->amount, 2),
                        'counterparty' => $this->resolveCounterpartyLabel($transaction->sender),
                        'method' => $transaction->payment_method,
                        'status' => $transaction->status,
                        'date' => $transaction->created_at->format('Y-m-d H:i:s')
                    ];
                });

            $transactions = $sentTransactions->concat($receivedTransactions)
                ->sortByDesc('date')
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,
                'transactions' => $transactions
            ]);

        } catch (\Exception $e) {
            Log::error('Get history error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch transaction history'
            ], 500);
        }
    }

    /**
     * Get single transaction
     */
    public function getTransaction(Request $request, $reference)
    {
        try {
            $user = $request->user();

            $transaction = Transaction::where('transaction_reference', $reference)
                ->where(function($query) use ($user) {
                    $query->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                })
                ->with(['receiver.merchant', 'sender.merchant'])
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'error' => 'Transaction not found'
                ], 404);
            }

            $type = $transaction->sender_id == $user->id ? 'sent' : 'received';
            $counterparty = $type == 'sent' 
                ? $this->resolveCounterpartyLabel($transaction->receiver)
                : $this->resolveCounterpartyLabel($transaction->sender);

            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'reference' => $transaction->transaction_reference,
                    'type' => $type,
                    'amount' => '$' . number_format($transaction->amount, 2),
                    'counterparty' => $counterparty,
                    'method' => $transaction->payment_method,
                    'status' => $transaction->status,
                    'date' => $transaction->created_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get transaction error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch transaction'
            ], 500);
        }
    }

    /**
     * Initiate payment
     */
    public function initiatePayment(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'merchant_code' => 'required_without:receiver_id|string',
                'receiver_id' => 'required_without:merchant_code|integer',
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'required|in:qr,nfc,ussd,QR,NFC,USSD',
                'pin' => 'required|string|min:4|max:6'
            ]);

            $user = $request->user();
            $wallet = $user->wallet;

            // Verify PIN
            if (!Hash::check($validated['pin'], $user->pin_hash)) {
                Log::warning("Incorrect PIN attempt for user {$user->id}");
                
                return response()->json([
                    'success' => false,
                    'error' => 'Incorrect PIN'
                ], 401);
            }

            // Check wallet exists
            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet not found'
                ], 404);
            }

            // Check balance
            if ($wallet->balance < $validated['amount']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient balance'
                ], 400);
            }

            // Find receiver (merchant or user)
            $receiverWallet = null;
            $counterparty = null;

            if (isset($validated['merchant_code'])) {
                // Find merchant
                $merchant = Merchant::where('merchant_code', $validated['merchant_code'])->first();
                
                if (!$merchant) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Merchant not found'
                    ], 404);
                }

                if (!$merchant->is_active) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Merchant account is inactive'
                    ], 403);
                }
                
                $receiverWallet = $merchant->user->wallet ?? null;
                $counterparty = $merchant->business_name;
                $receiverId = $merchant->user_id;

            } else {
                // Find user
                $receiver = User::find($validated['receiver_id']);
                
                if (!$receiver) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Receiver not found'
                    ], 404);
                }
                
                $receiverWallet = $receiver->wallet;
                $counterparty = $receiver->phone_number;
                $receiverId = $receiver->id;
            }

            if (!$receiverWallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Receiver wallet not found'
                ], 404);
            }

            $paymentMethod = strtolower($validated['payment_method']);

            // Start database transaction
            DB::beginTransaction();

            try {
                // Create transaction record
                $transaction = Transaction::create([
                    'transaction_reference' => Transaction::generateReference(),
                    'sender_id' => $user->id,
                    'receiver_id' => $receiverId,
                    'amount' => $validated['amount'],
                    'currency' => $wallet->currency ?? 'USD',
                    'payment_method' => $paymentMethod,
                    'status' => 'completed',
                ]);

                // Update sender balance
                $wallet->balance -= $validated['amount'];
                $wallet->save();

                // Update receiver balance
                $receiverWallet->balance += $validated['amount'];
                $receiverWallet->save();

                DB::commit();

                Log::info("Payment successful: User {$user->id} paid {$validated['amount']} to {$receiverId} via {$paymentMethod}");

                return response()->json([
                    'success' => true,
                    'transaction' => [
                        'id' => $transaction->id,
                        'reference' => $transaction->transaction_reference,
                        'amount' => number_format((float) $transaction->amount, 2, '.', ''),
                        'type' => 'sent',
                        'counterparty' => $counterparty,
                        'method' => $transaction->payment_method,
                        'status' => $transaction->status,
                        'date' => $transaction->created_at->format('Y-m-d H:i:s')
                    ],
                    'wallet' => [
                        'balance' => number_format($wallet->balance, 2, '.', ''),
                        'formatted_balance' => '$' . number_format($wallet->balance, 2)
                    ],
                    'message' => 'Payment successful'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed: ' . json_encode($e->errors())
            ], 422);

        } catch (\Exception $e) {
            Log::error('Payment error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'error' => 'Payment failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve the display label for a transaction counterparty.
     */
    private function resolveCounterpartyLabel(?User $user): string
    {
        if (!$user) {
            return 'Unknown';
        }

        $merchantName = $user->merchant->business_name ?? null;

        return $merchantName ?: ($user->phone_number ?? 'Unknown');
    }
}
