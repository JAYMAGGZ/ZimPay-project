<?php

/**
 * Migration: Create merchants table
 * 
 * Additional business information for merchant users
 * One-to-one optional relationship with users table
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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('business_name', 100);
            $table->string('merchant_code', 10)->unique()->comment('Unique merchant identifier for QR/USSD');
            $table->text('qr_code_data')->nullable()->comment('Generated QR code data');
            $table->string('location', 100)->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('merchant_code');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
