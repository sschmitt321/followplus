<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\System\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SystemController extends Controller
{
    public function __construct(
        private ConfigService $configService
    ) {
    }

    /**
     * Get system announcements.
     * 
     * Returns all active system announcements. Announcements are cached for 1 hour.
     * Each announcement includes title, content, type, and publication timestamp.
     * 
     * @return JsonResponse Returns array of announcements
     */
    public function announcements(): JsonResponse
    {
        // Get announcements from config or cache
        $announcements = Cache::remember('system_announcements', 3600, function () {
            return $this->configService->get('announcements', [
                [
                    'id' => 1,
                    'title' => '欢迎使用 FollowPlus',
                    'content' => 'FollowPlus 是一个专业的跟单交易平台',
                    'type' => 'info',
                    'published_at' => now()->toIso8601String(),
                ],
            ]);
        });

        return response()->json([
            'announcements' => $announcements,
        ]);
    }

    /**
     * Get help content.
     * 
     * Returns FAQ (Frequently Asked Questions) and contact information.
     * Content is cached for 1 hour.
     * 
     * @return JsonResponse Returns FAQ list and contact details (email, telegram)
     */
    public function help(): JsonResponse
    {
        $help = Cache::remember('system_help', 3600, function () {
            return [
                'faq' => [
                    [
                        'question' => '如何充值？',
                        'answer' => '在钱包页面获取充币地址，转账到该地址即可。',
                    ],
                    [
                        'question' => '如何提现？',
                        'answer' => '在提现页面输入金额和地址，提交申请后等待审核。',
                    ],
                    [
                        'question' => '什么是跟单？',
                        'answer' => '跟单是指跟随专业交易员的交易策略进行交易。',
                    ],
                ],
                'contact' => [
                    'email' => 'support@followplus.com',
                    'telegram' => '@followplus_support',
                ],
            ];
        });

        return response()->json($help);
    }

    /**
     * Get app version info.
     * 
     * Returns current app version, build number, minimum required version,
     * and whether an update is required.
     * 
     * @return JsonResponse Returns version information including:
     * - version: Current app version (e.g., "1.0.0")
     * - build: Build number/date (e.g., "2025.11.06")
     * - min_version: Minimum required version
     * - update_required: Boolean indicating if update is mandatory
     */
    public function version(): JsonResponse
    {
        return response()->json([
            'version' => '1.0.0',
            'build' => '2025.11.06',
            'min_version' => '1.0.0',
            'update_required' => false,
        ]);
    }

    /**
     * Get app download links.
     * 
     * Returns download links for iOS and Android mobile apps.
     * Includes App Store and Google Play Store URLs with version information.
     * 
     * @return JsonResponse Returns download links for:
     * - ios: App Store URL and version
     * - android: Google Play Store URL and version
     */
    public function download(): JsonResponse
    {
        return response()->json([
            'ios' => [
                'url' => 'https://apps.apple.com/app/followplus',
                'version' => '1.0.0',
            ],
            'android' => [
                'url' => 'https://play.google.com/store/apps/details?id=com.followplus',
                'version' => '1.0.0',
            ],
        ]);
    }
}

