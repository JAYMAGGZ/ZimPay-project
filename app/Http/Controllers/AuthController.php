<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

/**
 * Authentication Controller
 * 
 * Handles user registration, login, and JWT token generation
 */
class AuthController extends Controller
{
    /**
     * Register a new user (consumer or merchant).
     * 
     * POST /api/register
     * Body: {phone_number, pin, role, business_name?, location?}
     */
    public function register(Request $request)
    {
        $normalizedPhone = $this->normalizeZimbabwePhone($request->phone_number);

        if (!$normalizedPhone) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'phone_number' => ['Invalid Zimbabwe phone number format'],
                ],
            ], 400);
        }

        $request->merge(['phone_number' => $normalizedPhone]);

        // Validate request
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|regex:/^\+263[0-9]{9}$/|unique:users,phone_number',
            'pin' => 'required|string|min:4|max:6',
            'role' => 'required|in:consumer,merchant',
            'business_name' => 'required_if:role,merchant|string|max:100',
            'location' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Create user
            $user = User::create([
                'phone_number' => $request->phone_number,
                'pin_hash' => Hash::make($request->pin),
                'role' => $request->role,
                'is_active' => true,
            ]);

            // Create wallet for user (starting balance: $0)
            Wallet::create([
                'user_id' => $user->id,
                'balance' => 0.00,
                'currency' => 'USD',
            ]);

            // If merchant, create merchant profile
            if ($request->role === 'merchant') {
                Merchant::create([
                    'user_id' => $user->id,
                    'business_name' => $request->business_name,
                    'merchant_code' => $this->generateMerchantCode(),
                    'location' => $request->location,
                ]);
            }

            DB::commit();

            // Generate JWT token
            $token = $this->generateJWT($user);

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'user' => [
                    'id' => $user->id,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                ],
                'token' => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login user and return JWT token.
     * 
     * POST /api/login
     * Body: {phone_number, pin}
     */
    public function login(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'pin' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 400);
        }

        $normalizedPhone = $this->normalizeZimbabwePhone($request->phone_number);

        if (!$normalizedPhone) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid credentials',
            ], 401);
        }

        try {
            // Find user by phone number
            $user = User::where('phone_number', $normalizedPhone)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid credentials',
                ], 401);
            }

            // Check if account is locked
            if ($user->isLocked()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account locked. Please try again later.',
                    'locked_until' => $user->locked_until->toIso8601String(),
                ], 403);
            }

            // Verify PIN
            if (!Hash::check($request->pin, $user->pin_hash)) {
                // Increment failed attempts
                $user->incrementFailedAttempts();

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid credentials',
                    'attempts_remaining' => max(0, 5 - $user->failed_login_attempts),
                ], 401);
            }

            // Reset failed attempts on successful login
            $user->resetFailedAttempts();

            // Generate JWT token
            $token = $this->generateJWT($user);

            // Get user details with wallet balance
            $wallet = $user->wallet;
            $merchant = $user->merchant;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'wallet_balance' => $wallet ? $wallet->formatted_balance : '$0.00',
                    'merchant_code' => $merchant ? $merchant->merchant_code : null,
                    'business_name' => $merchant ? $merchant->business_name : null,
                ],
                'token' => $token,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Login failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current user details (requires authentication).
     * 
     * GET /api/user
     * Headers: Authorization: Bearer {token}
     */
    public function getUser(Request $request)
    {
        try {
            $user = $request->user(); // Assumes auth middleware sets this

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            $wallet = $user->wallet;
            $merchant = $user->merchant;

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'wallet_balance' => $wallet ? $wallet->balance : 0,
                    'currency' => $wallet ? $wallet->currency : 'USD',
                    'merchant_code' => $merchant ? $merchant->merchant_code : null,
                    'business_name' => $merchant ? $merchant->business_name : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate JWT token for user.
     * 
     * @param User $user
     * @return string JWT token
     */
    private function generateJWT(User $user): string
    {
        $payload = [
            'user_id' => $user->id,
            'phone_number' => $user->phone_number,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24), // 24 hours
        ];

        // Simple JWT encoding (in production, use proper JWT library)
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload", env('JWT_SECRET', 'your-secret-key'), true);
        $signature = base64_encode($signature);

        return "$header.$payload.$signature";
    }

    /**
     * Normalize Zimbabwe phone numbers to +2637XXXXXXXX format.
     */
    private function normalizeZimbabwePhone(?string $phoneNumber): ?string
    {
        if (!$phoneNumber) {
            return null;
        }

        // Keep only digits and a possible leading plus sign.
        $clean = preg_replace('/[^0-9+]/', '', trim($phoneNumber));

        if (!$clean) {
            return null;
        }

        if (str_starts_with($clean, '+263')) {
            $local = substr($clean, 4);
        } elseif (str_starts_with($clean, '263')) {
            $local = substr($clean, 3);
        } elseif (str_starts_with($clean, '0')) {
            $local = substr($clean, 1);
        } else {
            $local = $clean;
        }

        // ZW mobile numbers after country/leading zero should be 9 digits and start with 7.
        if (!preg_match('/^7[0-9]{8}$/', $local)) {
            return null;
        }

        return '+263' . $local;
    }

    /**
     * Generate unique merchant code.
     * 
     * @return string Merchant code (e.g., MERCH12345)
     */
    private function generateMerchantCode(): string
    {
        do {
            $code = 'MERCH' . rand(10000, 99999);
        } while (Merchant::where('merchant_code', $code)->exists());

        return $code;
    }
}
