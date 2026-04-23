<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 5 test consumers
        $consumers = [];
        $consumerPhones = [
            
            '+263777890123',
            '+263773456789',
            '+263774567890',
            '+263775678901',
        ];

        foreach ($consumerPhones as $phone) {
            $user = User::create([
                'phone_number' => $phone,
                'pin_hash' => Hash::make('1234'),
                'role' => 'consumer',
                'is_active' => true,
            ]);

            Wallet::create([
                'user_id' => $user->id,
                'balance' => rand(50, 500),
                'currency' => 'USD',
            ]);

            $consumers[] = $user;
        }

        // Create 5 test merchants
        $merchants = [];
        $merchantData = [
            [
                'phone' => '+263779876543',
                'name' => "Jay's Electronics",
                'location' => 'Chinhoyi CBD',
            ],
            [
                'phone' => '+263778765432',
                'name' => 'Quick Grocery Store',
                'location' => 'Chinhoyi Town Center',
            ],
            [
                'phone' => '+263777654321',
                'name' => 'Fashion Hub',
                'location' => 'Cold Comfort',
            ],
            [
                'phone' => '+263776543210',
                'name' => 'Tech World',
                'location' => 'Gadzema',
            ],
            [
                'phone' => '+263775432109',
                'name' => 'Food Corner',
                'location' => 'Chinhoyi CBD',
            ],
        ];

        foreach ($merchantData as $data) {
            $user = User::create([
                'phone_number' => $data['phone'],
                'pin_hash' => Hash::make('5678'),
                'role' => 'merchant',
                'is_active' => true,
            ]);

            Wallet::create([
                'user_id' => $user->id,
                'balance' => rand(100, 1000),
                'currency' => 'USD',
            ]);

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $data['name'],
                'merchant_code' => $this->generateMerchantCode(),
                'location' => $data['location'],
            ]);

            $merchants[] = ['user' => $user, 'merchant' => $merchant];
        }

        // Create sample transactions
        $paymentMethods = ['qr', 'nfc', 'ussd'];
        $statuses = ['completed', 'completed', 'completed', 'failed']; // 75% success

        for ($i = 0; $i < 30; $i++) {
            $consumer = $consumers[array_rand($consumers)];
            $merchantData = $merchants[array_rand($merchants)];
            $amount = rand(5, 200);

            Transaction::create([
                'transaction_reference' => Transaction::generateReference(),
                'sender_id' => $consumer->id,
                'receiver_id' => $merchantData['user']->id,
                'amount' => $amount,
                'currency' => 'USD',
                'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                'status' => $statuses[array_rand($statuses)],
                'created_at' => now()->subDays(rand(0, 30)),
            ]);
        }

        $this->command->info('✅ Created 5 consumers');
        $this->command->info('✅ Created 5 merchants');
        $this->command->info('✅ Created 30 sample transactions');
        $this->command->info('');
        $this->command->info('Test Credentials:');
        $this->command->info('Consumer PIN: 1234');
        $this->command->info('Merchant PIN: 5678');
    }

    private function generateMerchantCode(): string
    {
        do {
            $code = 'MERCH' . rand(10000, 99999);
        } while (Merchant::where('merchant_code', $code)->exists());

        return $code;
    }
}
