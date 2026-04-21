<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * Merchant Model
 * 
 * Business profile for merchant users
 */
class Merchant extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'business_name',
        'merchant_code',
        'qr_code_data',
        'location',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the merchant profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all transactions received by this merchant.
     */
    public function transactions()
    {
        return Transaction::where('receiver_id', $this->user_id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get total sales amount.
     */
    public function getTotalSales(): float
    {
        return $this->transactions()
            ->sum('amount');
    }

    /**
     * Get sales for a specific date range.
     */
    public function getSalesForPeriod($startDate, $endDate): float
    {
        return $this->transactions()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    /**
     * Get transaction count.
     */
    public function getTransactionCount(): int
    {
        return $this->transactions()->count();
    }

    /**
     * Get average transaction value.
     */
    public function getAverageTransactionValue(): float
    {
        $count = $this->getTransactionCount();
        return $count > 0 ? $this->getTotalSales() / $count : 0.0;
    }

    /**
     * Generate QR code data for payment.
     */
    public function generateQRCodeData(?float $amount = null): array
    {
        return [
            'merchant_id' => $this->user_id,
            'merchant_code' => $this->merchant_code,
            'business_name' => $this->business_name,
            'amount' => $amount,
            'timestamp' => now()->timestamp,
        ];
    }
}
