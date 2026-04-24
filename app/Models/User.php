<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * User Model
 * 
 * Represents system users (consumers and merchants)
 *
 * @property int $id
 * @property string $phone_number
 * @property string $pin_hash
 * @property string $role
 * @property bool $is_active
 * @property int $failed_login_attempts
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'phone_number',
        'pin_hash',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'pin_hash',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the wallet associated with the user.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get the merchant profile (if user is a merchant).
     */
    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    /**
     * Get transactions sent by this user.
     */
    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_id');
    }

    /**
     * Get transactions received by this user.
     */
    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'receiver_id');
    }

    /**
     * Get all transactions (sent and received).
     */
    public function allTransactions()
    {
        return Transaction::where('sender_id', $this->id)
            ->orWhere('receiver_id', $this->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Check if user is a merchant.
     */
    public function isMerchant(): bool
    {
        return $this->role === 'merchant';
    }

    /**
     * Check if user account is locked.
     */
    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Increment failed login attempts and lock if necessary.
     */
    public function incrementFailedAttempts(): void
    {
        $this->increment('failed_login_attempts');
        
        if ($this->failed_login_attempts >= 5) {
            $this->locked_until = Carbon::now()->addMinutes(15);
            $this->save();
        }
    }

    /**
     * Reset failed login attempts.
     */
    public function resetFailedAttempts(): void
    {
        $this->failed_login_attempts = 0;
        $this->locked_until = null;
        $this->save();
    }
}
