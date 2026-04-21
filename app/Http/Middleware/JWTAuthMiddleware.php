<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * JWT Authentication Middleware
 * 
 * Verifies JWT tokens and authenticates users
 */
class JWTAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get token from Authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: No token provided',
            ], 401);
        }

        // Extract token
        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        try {
            // Decode and verify JWT
            $payload = $this->verifyJWT($token);

            if (!$payload) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized: Invalid token',
                ], 401);
            }

            // Check if token is expired
            if (isset($payload['exp']) && time() > $payload['exp']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized: Token expired',
                ], 401);
            }

            // Find user
            $user = User::find($payload['user_id']);

            if (!$user || !$user->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized: User not found or inactive',
                ], 401);
            }

            // Attach user to request
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: ' . $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Verify JWT token and extract payload.
     *
     * @param string $token
     * @return array|null
     */
    private function verifyJWT(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSignature = base64_encode(
            hash_hmac('sha256', "$header.$payload", env('JWT_SECRET', 'your-secret-key'), true)
        );

        if ($signature !== $expectedSignature) {
            return null;
        }

        // Decode payload
        $decodedPayload = json_decode(base64_decode($payload), true);

        return $decodedPayload ?: null;
    }
}
