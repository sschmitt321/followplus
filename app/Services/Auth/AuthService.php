<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthService
{
    /**
     * Register a new user.
     */
    public function register(string $email, string $password, ?string $inviteCode = null): array
    {
        // Generate unique invite code
        $userInviteCode = $this->generateInviteCode();

        // Find inviter if invite code provided
        $inviter = null;
        $refPath = '/';
        $refDepth = 0;

        if ($inviteCode) {
            $inviter = User::where('invite_code', $inviteCode)->first();
            if ($inviter) {
                $refPath = rtrim($inviter->ref_path, '/') . '/' . $inviter->id;
                $refDepth = $inviter->ref_depth + 1;
            }
        }

        // Create user with Argon2id password hash
        $user = User::create([
            'email' => $email,
            'password_hash' => Hash::make($password, ['memory' => 65536, 'time' => 4, 'threads' => 3]),
            'invite_code' => $userInviteCode,
            'invited_by_user_id' => $inviter?->id,
            'ref_path' => $refPath,
            'ref_depth' => $refDepth,
            'role' => 'user',
            'status' => 'active',
            'first_joined_at' => now(),
        ]);

        // Create user profile
        $user->profile()->create([]);

        // Create user KYC record
        $user->kyc()->create([
            'level' => 'none',
            'status' => 'pending',
        ]);

        // Generate tokens
        $token = JWTAuth::fromUser($user);
        $refreshToken = $this->generateRefreshToken($user->id);

        return [
            'access' => $token,
            'refresh' => $refreshToken,
        ];
    }

    /**
     * Login user.
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            throw new \Exception('Invalid credentials');
        }

        $token = JWTAuth::fromUser($user);
        $refreshToken = $this->generateRefreshToken($user->id);

        return [
            'access' => $token,
            'refresh' => $refreshToken,
        ];
    }

    /**
     * Refresh access token.
     */
    public function refresh(string $refreshToken): array
    {
        // Validate refresh token (stored in cache/redis)
        $userId = cache()->get("refresh_token:{$refreshToken}");
        if (!$userId) {
            throw new \Exception('Invalid refresh token');
        }

        $user = User::findOrFail($userId);
        $token = JWTAuth::fromUser($user);

        return [
            'access' => $token,
        ];
    }

    /**
     * Generate unique invite code.
     */
    private function generateInviteCode(): string
    {
        do {
            $code = Str::random(8);
        } while (User::where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * Generate and store refresh token.
     */
    private function generateRefreshToken(int $userId): string
    {
        $token = Str::random(64);
        cache()->put("refresh_token:{$token}", $userId, now()->addDays(30));

        return $token;
    }
}

