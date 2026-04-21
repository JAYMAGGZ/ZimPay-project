<?php

/**
 * Migration: Create wallets table
 * 
 * Digital wallets for storing user balances
 * One-to-one relationship with users table
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('balance', 12, 2)->default(0.00)->comment('Wallet balance');
            $table->enum('currency', ['USD', 'ZWL', 'ZAR'])->default('USD');
            $table->timestamp('updated_at');
            
            // Indexes
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
