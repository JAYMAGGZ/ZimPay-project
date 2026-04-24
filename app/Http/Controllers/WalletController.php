<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    /**
     * Get wallet balance
     */
    public function getBalance(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'wallet' => [
                    'id' => $wallet->id,
                    'balance' => number_format($wallet->balance, 2, '.', ''),
                    'currency' => $wallet->currency ?? 'USD',
                    'formatted_balance' => '$' . number_format($wallet->balance, 2)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get balance error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch balance'
            ], 500);
        }
    }

    /**
     * Get wallet summary
     */
    public function getSummary(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet not found'
                ], 404);
            }

            // Get transaction counts
            $totalSent = $user->sentTransactions()->where('status', 'completed')->count();
            $totalReceived = $user->receivedTransactions()->where('status', 'completed')->count();

            return response()->json([
                'success' => true,
                'wallet' => [
                    'balance' => number_format($wallet->balance, 2, '.', ''),
                    'currency' => $wallet->currency ?? 'USD',
                    'formatted_balance' => '$' . number_format($wallet->balance, 2)
                ],
                'summary' => [
                    'total_sent' => $totalSent,
                    'total_received' => $totalReceived,
                    'total_transactions' => $totalSent + $totalReceived
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get summary error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch summary'
            ], 500);
        }
    }

    /**
     * Top up wallet
     */
    public function topUp(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01|max:10000'
            ]);

            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet not found'
                ], 404);
            }

            // Update balance
            $wallet->balance += $validated['amount'];
            $wallet->save();

            Log::info("Wallet topped up: User {$user->id}, Amount: {$validated['amount']}, New Balance: {$wallet->balance}");

            return response()->json([
                'success' => true,
                'wallet' => [
                    'id' => $wallet->id,
                    'balance' => number_format($wallet->balance, 2, '.', ''),
                    'currency' => $wallet->currency ?? 'USD',
                    'formatted_balance' => '$' . number_format($wallet->balance, 2)
                ],
                'message' => 'Wallet topped up successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid amount: ' . $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Top-up error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Top-up failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
