<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * Transaction Model
 * 
 * Payment transaction records
 */
class Transaction extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'transaction_reference',
        'sender_id',
        'receiver_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sender (user who initiated payment).
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the receiver (user who received payment).
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Generate unique transaction reference.
     */
    public static function generateReference(): string
    {
        return 'TXN' . now()->format('YmdHis') . strtoupper(substr(md5(uniqid()), 0, 6));
    }

    /**
     * Mark transaction as completed.
     */
    public function markAsCompleted(): void
    {
        $this->status = 'completed';
        $this->save();
    }

    /**
     * Mark transaction as failed with error message.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->error_message = $errorMessage;
        $this->save();
    }

    /**
     * Check if transaction is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if transaction is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbols = [
            'USD' => '$',
            'ZWL' => 'Z$',
            'ZAR' => 'R',
        ];

        $symbol = $symbols[$this->currency] ?? '$';
        return $symbol . number_format((float) $this->amount, 2);
    }

    /**
     * Get transaction receipt data.
     */
    public function getReceiptData(): array
    {
        return [
            'reference' => $this->transaction_reference,
            'date' => $this->created_at->format('d M Y, H:i'),
            'from' => $this->sender->phone_number,
            'to' => $this->receiver->merchant->business_name ?? $this->receiver->phone_number,
            'amount' => $this->formatted_amount,
            'method' => strtoupper($this->payment_method),
            'status' => ucfirst($this->status),
        ];
    }
}
