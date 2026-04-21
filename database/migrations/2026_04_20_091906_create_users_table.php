<?php

/**
 * Migration: Create users table
 * 
 * Stores user account information for both consumers and merchants
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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 15)->unique()->comment('Zimbabwe phone format: +263XXXXXXXXX');
            $table->string('pin_hash')->comment('Bcrypt hashed PIN');
            $table->enum('role', ['consumer', 'merchant'])->default('consumer');
            $table->boolean('is_active')->default(true);
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('phone_number');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
