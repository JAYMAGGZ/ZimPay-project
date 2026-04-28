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
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
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
    public function getTotalSales($startDate = null): float
    {
        return $this->transactions()
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->sum('amount');
    }

    /**
     * Get sales for a specific date range.
     */
    public function getSalesForPeriod($startDate, $endDate = null): float
    {
        if ($endDate !== null) {
            return $this->transactions()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount');
        }

        return $this->transactions()
            ->where('created_at', '>=', $startDate)
            ->sum('amount');
    }

    /**
     * Get transaction count.
     */
    public function getTransactionCount($startDate = null): int
    {
        return $this->transactions()
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->count();
    }

    /**
     * Get average transaction value.
     */
    public function getAverageTransactionValue($startDate = null): float
    {
        $count = $this->getTransactionCount($startDate);
        return $count > 0 ? $this->getTotalSales($startDate) / $count : 0.0;
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
