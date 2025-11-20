<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Tron\TronHdWalletService;
use App\Services\Tron\TronWalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthService
{
    public function __construct(
        private ?TronHdWalletService $hdWalletService = null,
        private ?TronWalletService $tronWalletService = null
    ) {
    }
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

        // Generate Tron wallet address for user
        // Use the same logic as getOrCreateDepositAddress to ensure consistency
        if ($this->tronWalletService) {
            try {
                $this->tronWalletService->getOrCreateDepositAddress($user->id);
            } catch (\Exception $e) {
                // Log error but don't fail registration
                // Wallet will be created when user first calls getOrCreateDepositAddress
                \Log::error('Failed to generate Tron address for user during registration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
     * Request password reset.
     * 
     * Sends a password reset token to the user's email.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = User::where('email', $email)->first();

        // Don't reveal if user exists or not (security best practice)
        if (!$user) {
            return;
        }

        // Generate reset token
        $token = Str::random(64);
        
        // Store token in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        // Send notification with reset link
        $user->notify(new ResetPasswordNotification($token));
    }

    /**
     * Reset password using token.
     */
    public function resetPassword(string $email, string $token, string $newPassword): void
    {
        // Find reset record
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$record) {
            throw new \Exception('Invalid reset token');
        }

        // Check if token is expired (60 minutes)
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw new \Exception('Reset token has expired');
        }

        // Verify token
        if (!Hash::check($token, $record->token)) {
            throw new \Exception('Invalid reset token');
        }

        // Find user
        $user = User::where('email', $email)->first();
        if (!$user) {
            throw new \Exception('User not found');
        }

        // Update password
        $user->update([
            'password_hash' => Hash::make($newPassword, ['memory' => 65536, 'time' => 4, 'threads' => 3]),
        ]);

        // Delete reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    /**
     * Admin reset password (directly reset without token).
     * 
     * This method allows administrators to reset a user's password directly.
     */
    public function adminResetPassword(string $email, string $newPassword): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new \Exception('User not found');
        }

        // Update password
        $user->update([
            'password_hash' => Hash::make($newPassword, ['memory' => 65536, 'time' => 4, 'threads' => 3]),
        ]);

        // Invalidate all refresh tokens for this user
        // Note: This would require tracking refresh tokens per user, 
        // which is not currently implemented. Consider implementing if needed.
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

