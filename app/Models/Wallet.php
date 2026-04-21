<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * Wallet Model
 * 
 * Digital wallet for user balances
 */
class Wallet extends Model
{
    /**
     * Disable created_at timestamp (wallet only has updated_at).
     */
    const CREATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'balance' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the wallet.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if wallet has sufficient balance for a transaction.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Debit amount from wallet (atomic).
     */
    public function debit(float $amount): bool
    {
        if (!$this->hasSufficientBalance($amount)) {
            return false;
        }

        return $this->decrement('balance', $amount) > 0;
    }

    /**
     * Credit amount to wallet (atomic).
     */
    public function credit(float $amount): bool
    {
        return $this->increment('balance', $amount) > 0;
    }

    /**
     * Get formatted balance with currency symbol.
     */
    public function getFormattedBalanceAttribute(): string
    {
        $symbols = [
            'USD' => '$',
            'ZWL' => 'Z$',
            'ZAR' => 'R',
        ];

        $symbol = $symbols[$this->currency] ?? '$';
        return $symbol . number_format((float) $this->balance, 2);
    }
}
