<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Payment Service
 * 
 * Handles payment transaction processing with atomic database operations
 */
class PaymentService
{
    /**
     * Process a payment transaction.
     * 
     * @param int $senderId Sender user ID
     * @param int $receiverId Receiver user ID
     * @param float $amount Transaction amount
     * @param string $paymentMethod Payment method (qr, ussd, nfc)
     * @return array Transaction result with status and data
     */
    public function processPayment(int $senderId, int $receiverId, float $amount, string $paymentMethod = 'qr'): array
    {
        try {
            // Start database transaction
            DB::beginTransaction();

            // 1. Validate users exist
            $sender = User::findOrFail($senderId);
            $receiver = User::findOrFail($receiverId);

            // 2. Get wallets
            $senderWallet = $sender->wallet;
            $receiverWallet = $receiver->wallet;

            if (!$senderWallet || !$receiverWallet) {
                throw new Exception('Wallet not found for user');
            }

            // 3. Validate sufficient balance
            if (!$senderWallet->hasSufficientBalance($amount)) {
                throw new Exception('Insufficient balance');
            }

            // 4. Generate transaction reference
            $transactionRef = Transaction::generateReference();

            // 5. Create transaction record (pending status)
            $transaction = Transaction::create([
                'transaction_reference' => $transactionRef,
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'amount' => $amount,
                'currency' => $senderWallet->currency,
                'payment_method' => $paymentMethod,
                'status' => 'processing',
            ]);

            // 6. Execute wallet operations (atomic)
            $debitSuccess = $senderWallet->debit($amount);
            $creditSuccess = $receiverWallet->credit($amount);

            if (!$debitSuccess || !$creditSuccess) {
                throw new Exception('Wallet operation failed');
            }

            // 7. Mark transaction as completed
            $transaction->markAsCompleted();

            // 8. Commit database transaction
            DB::commit();

            // 9. Return success response
            return [
                'success' => true,
                'transaction' => $transaction,
                'receipt' => $transaction->getReceiptData(),
                'new_balance' => $senderWallet->fresh()->balance,
            ];

        } catch (Exception $e) {
            // Rollback all database changes
            DB::rollBack();

            // Mark transaction as failed if it was created
            if (isset($transaction)) {
                $transaction->markAsFailed($e->getMessage());
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'transaction' => $transaction ?? null,
            ];
        }
    }

    /**
     * Simulate mobile money gateway API call.
     * 
     * @param string $accountId Account identifier
     * @param float $amount Transaction amount
     * @return array Gateway response
     */
    public function callMobileMoneyGateway(string $accountId, float $amount): array
    {
        // Simulate API latency (200-500ms)
        usleep(rand(200000, 500000));

        // Simulate 95% success rate
        if (rand(1, 100) <= 95) {
            return [
                'status' => 'success',
                'reference' => 'MM' . uniqid(),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        return [
            'status' => 'failed',
            'error' => 'External gateway error',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get transaction history for a user.
     * 
     * @param int $userId User ID
     * @param int $limit Number of transactions to retrieve
     * @return array Transaction history
     */
    public function getTransactionHistory(int $userId, int $limit = 20): array
    {
        $user = User::findOrFail($userId);

        $transactions = Transaction::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $transactions->map(function ($transaction) use ($userId) {
            return [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_reference,
                'type' => $transaction->sender_id === $userId ? 'sent' : 'received',
                'amount' => $transaction->formatted_amount,
                'counterparty' => $transaction->sender_id === $userId 
                    ? ($transaction->receiver->merchant->business_name ?? $transaction->receiver->phone_number)
                    : ($transaction->sender->merchant->business_name ?? $transaction->sender->phone_number),
                'method' => strtoupper($transaction->payment_method),
                'status' => ucfirst($transaction->status),
                'date' => $transaction->created_at->diffForHumans(),
            ];
        })->toArray();
    }

    /**
     * Top up wallet (simulated for prototype).
     * 
     * @param int $userId User ID
     * @param float $amount Amount to add
     * @return array Top-up result
     */
    public function topUpWallet(int $userId, float $amount): array
    {
        try {
            $user = User::findOrFail($userId);
            $wallet = $user->wallet;

            if (!$wallet) {
                throw new Exception('Wallet not found');
            }

            DB::beginTransaction();

            // Credit wallet
            $wallet->credit($amount);

            DB::commit();

            return [
                'success' => true,
                'new_balance' => $wallet->fresh()->balance,
                'message' => 'Wallet topped up successfully',
            ];

        } catch (Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
