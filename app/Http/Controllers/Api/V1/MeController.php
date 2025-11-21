<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    /**
     * Get current user information.
     * 
     * Returns comprehensive information about the authenticated user, including:
     * - Basic user details (id, email, invite_code, role, status)
     * - User profile information (name, city)
     * - KYC status (level and verification status)
     * - Assets summary (total balance, principal, profit, bonus)
     * - User status flags (is_newbie, kyc_verified, has_withdraw_password, has_withdraw_address)
     * 
     * This endpoint is used to fetch the current user's profile and account overview.
     * 
     * @return JsonResponse Returns user information including:
     * - user: Basic user information (id, email, invite_code, role, status, first_joined_at)
     * - profile: User profile information (name, city, withdraw_address) or null if not set
     * - kyc: KYC status (level, status) or null if not submitted
     * - role: User role (redundant with user.role)
     * - assets: Assets summary with total_balance, principal_balance, profit_balance, bonus_balance, and accounts (grouped by currency and account type)
     *   - accounts: Object with currency as key, containing spot and contract account balances (available and frozen)
     * - status: User status flags
     *   - is_newbie: Whether user is newbie (joined within 7 days)
     *   - kyc_verified: Whether user's identity is verified (KYC approved)
     *   - has_withdraw_password: Whether withdrawal password is set
     *   - has_withdraw_address: Whether withdrawal address is set
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $user->load(['profile', 'kyc']);

        // Get assets summary
        $summary = \App\Services\Assets\AssetsService::class;
        $assetsService = app($summary);
        $assetsSummary = $assetsService->getSummary($user->id);

        // Get account balances grouped by currency and type
        $accounts = \App\Models\Account::where('user_id', $user->id)
            ->get()
            ->groupBy('currency');

        $accountBalances = [];
        foreach ($accounts as $currency => $currencyAccounts) {
            $spotAccount = $currencyAccounts->firstWhere('type', 'spot');
            $contractAccount = $currencyAccounts->firstWhere('type', 'contract');

            $accountBalances[$currency] = [
                'spot' => [
                    'available' => $spotAccount ? $spotAccount->available->toFixed(6) : '0.000000',
                    'frozen' => $spotAccount ? $spotAccount->frozen->toFixed(6) : '0.000000',
                ],
                'contract' => [
                    'available' => $contractAccount ? $contractAccount->available->toFixed(6) : '0.000000',
                    'frozen' => $contractAccount ? $contractAccount->frozen->toFixed(6) : '0.000000',
                ],
            ];
        }

        // Calculate user status flags
        $isNewbie = $this->isNewbie($user);
        $kycVerified = $user->kyc && $user->kyc->status === 'approved';
        $hasWithdrawPassword = !empty($user->withdraw_password_hash);
        $hasWithdrawAddress = !empty($user->profile?->withdraw_address);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'invite_code' => $user->invite_code,
                'role' => $user->role,
                'status' => $user->status,
                'first_joined_at' => $user->first_joined_at,
            ],
            'profile' => $user->profile ? [
                'name' => $user->profile->name,
                'city' => $user->profile->city,
                'withdraw_address' => $user->profile->withdraw_address,
            ] : null,
            'kyc' => $user->kyc ? [
                'level' => $user->kyc->level,
                'status' => $user->kyc->status,
            ] : null,
            'role' => $user->role,
            'assets' => [
                'total_balance' => $assetsSummary->total_balance->toFixed(6),
                'principal_balance' => $assetsSummary->principal_balance->toFixed(6),
                'profit_balance' => $assetsSummary->profit_balance->toFixed(6),
                'bonus_balance' => $assetsSummary->bonus_balance->toFixed(6),
                'accounts' => $accountBalances, // 按币种和账户类型分组的余额
            ],
            'status' => [
                'is_newbie' => $isNewbie,
                'kyc_verified' => $kycVerified,
                'has_withdraw_password' => $hasWithdrawPassword,
                'has_withdraw_address' => $hasWithdrawAddress,
            ],
        ]);
    }

    /**
     * Check if user is newbie (joined within 7 days).
     */
    private function isNewbie($user): bool
    {
        if (!$user->first_joined_at) {
            return true;
        }

        return $user->first_joined_at->diffInDays(now()) <= 7;
    }
}
