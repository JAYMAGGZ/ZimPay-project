<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UssdController extends Controller
{
    /**
     * Build USSD details for a merchant and amount.
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'merchant_code' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $merchantCode = strtoupper(str_replace('-', '', $validated['merchant_code']));
        if (!str_starts_with($merchantCode, 'MERCH')) {
            $merchantCode = 'MERCH' . $merchantCode;
        }
        $merchantCode = 'MERCH-' . substr($merchantCode, 5);

        $merchant = Merchant::where('merchant_code', $merchantCode)->first();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => 'Merchant not found',
            ], 404);
        }

        if (!$merchant->is_active) {
            return response()->json([
                'success' => false,
                'error' => 'Merchant account is inactive',
            ], 403);
        }

        $amount = (float) $validated['amount'];
        $codeWithoutPrefix = str_replace('MERCH-', '', $merchant->merchant_code);
        $amountInCents = (int) round($amount * 100);
        $ussdCode = "*384*9*{$codeWithoutPrefix}*{$amountInCents}#";

        return response()->json([
            'success' => true,
            'merchant' => [
                'name' => $merchant->business_name,
                'code' => $merchant->merchant_code,
                'location' => $merchant->location,
            ],
            'amount' => number_format($amount, 2, '.', ''),
            'amount_in_cents' => $amountInCents,
            'ussd_code' => $ussdCode,
            'message' => 'USSD code generated successfully',
        ]);
    }

    /**
     * Simulate USSD processing by creating a payment transaction.
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'merchant_code' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'required|string|min:4|max:6',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        if (!Hash::check($validated['pin'], $user->pin_hash)) {
            return response()->json([
                'success' => false,
                'error' => 'Incorrect PIN',
            ], 401);
        }

        $merchantCode = strtoupper(str_replace('-', '', $validated['merchant_code']));
        if (!str_starts_with($merchantCode, 'MERCH')) {
            $merchantCode = 'MERCH' . $merchantCode;
        }
        $merchantCode = 'MERCH-' . substr($merchantCode, 5);

        $merchant = Merchant::where('merchant_code', $merchantCode)->first();
        if (!$merchant || !$merchant->is_active) {
            return response()->json([
                'success' => false,
                'error' => 'Merchant not found',
            ], 404);
        }

        $senderWallet = $user->wallet;
        $receiverWallet = optional($merchant->user)->wallet;

        if (!$senderWallet || !$receiverWallet) {
            return response()->json([
                'success' => false,
                'error' => 'Wallet not found',
            ], 404);
        }

        $amount = (float) $validated['amount'];
        if ($senderWallet->balance < $amount) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient balance',
            ], 400);
        }

        try {
            $transaction = null;

            DB::transaction(function () use (&$transaction, $user, $merchant, $senderWallet, $receiverWallet, $amount) {
                $transaction = Transaction::create([
                    'transaction_reference' => Transaction::generateReference(),
                    'sender_id' => $user->id,
                    'receiver_id' => $merchant->user_id,
                    'amount' => $amount,
                    'currency' => $senderWallet->currency ?? 'USD',
                    'payment_method' => 'ussd',
                    'status' => 'completed',
                ]);

                $senderWallet->balance -= $amount;
                $senderWallet->save();

                $receiverWallet->balance += $amount;
                $receiverWallet->save();
            });

            if (!$transaction) {
                throw new \RuntimeException('Transaction creation failed');
            }

            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'reference' => $transaction->transaction_reference,
                    'amount' => number_format((float) $transaction->amount, 2, '.', ''),
                    'method' => $transaction->payment_method,
                    'status' => $transaction->status,
                    'date' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'counterparty' => $merchant->business_name,
                ],
                'wallet' => [
                    'balance' => number_format((float) $senderWallet->fresh()->balance, 2, '.', ''),
                    'formatted_balance' => '$' . number_format((float) $senderWallet->fresh()->balance, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('USSD payment processing failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'USSD payment processing failed',
            ], 500);
        }
    }

    /**
     * Placeholder callback endpoint for future gateway integrations.
     */
    public function callback(Request $request)
    {
        Log::info('USSD callback received', [
            'payload' => $request->all(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Callback received',
        ]);
    }

    /**
     * Return user transaction status by reference.
     */
    public function status(Request $request, string $transactionId)
    {
        $user = $request->user();

        $transaction = Transaction::where('transaction_reference', $transactionId)
            ->where(function ($query) use ($user) {
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
                'status' => $transaction->status,
                'method' => $transaction->payment_method,
                'amount' => number_format((float) $transaction->amount, 2, '.', ''),
                'date' => $transaction->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Return merchant details and static USSD template.
     */
    public function getMerchantUssdInfo(string $merchantCode)
    {
        $normalized = strtoupper(str_replace('-', '', $merchantCode));
        if (!str_starts_with($normalized, 'MERCH')) {
            $normalized = 'MERCH' . $normalized;
        }
        $normalized = 'MERCH-' . substr($normalized, 5);

        $merchant = Merchant::where('merchant_code', $normalized)->first();
        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => 'Merchant not found',
            ], 404);
        }

        $codeWithoutPrefix = str_replace('MERCH-', '', $merchant->merchant_code);
        $baseCode = "*384*9*{$codeWithoutPrefix}";

        return response()->json([
            'success' => true,
            'merchant' => [
                'name' => $merchant->business_name,
                'code' => $merchant->merchant_code,
                'location' => $merchant->location,
            ],
            'ussd_base_code' => $baseCode,
            'ussd_format' => "{$baseCode}*AMOUNT_IN_CENTS#",
            'examples' => [
                '10.00' => "{$baseCode}*1000#",
                '50.00' => "{$baseCode}*5000#",
                '100.50' => "{$baseCode}*10050#",
            ],
        ]);
    }
}
