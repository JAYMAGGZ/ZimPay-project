<?php

/**
 * Migration: Create transactions table
 * 
 * Records all payment transactions in the system
 * Many-to-one relationships with users (sender and receiver)
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_reference', 20)->unique()->comment('Unique transaction identifier');
            $table->foreignId('sender_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('receiver_id')->constrained('users')->onDelete('restrict');
            $table->decimal('amount', 10, 2)->comment('Transaction amount');
            $table->string('currency', 3)->default('USD');
            $table->enum('payment_method', ['qr', 'ussd', 'nfc'])->default('qr');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('transaction_reference');
            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['sender_id', 'created_at']); // Composite for user history queries
            $table->index(['receiver_id', 'created_at']); // Composite for merchant sales queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
