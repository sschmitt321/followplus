<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Follow\FollowService;
use App\Services\Follow\FollowQuotaService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowController extends Controller
{
    public function __construct(
        private FollowService $followService,
        private FollowQuotaService $quotaService
    ) {
    }

    /**
     * Get available windows for a date.
     * 
     * Returns all available follow windows for the specified date. Includes fixed daily windows
     * and bonus windows that the user is eligible for based on their account status.
     * 
     * @param Request $request Query parameters
     * @param string|null $request->date Optional. Date in YYYY-MM-DD format (default: today)
     * 
     * @return JsonResponse Returns list of available windows with details
     * 
     * Query example: ?date=2025-11-06
     */
    public function availableWindows(Request $request): JsonResponse
    {
        $date = $request->input('date', now()->format('Y-m-d')); // Date in YYYY-MM-DD format (default: today)
        
        // Pass user ID to filter windows by permission
        $userId = auth()->id();
        $windows = $this->followService->getAvailableWindows($date, $userId);
        
        return response()->json([
            'date' => $date,
            'windows' => $windows,
        ]);
    }

    /**
     * Place a follow order.
     * 
     * Creates a follow order for the specified window. The window and symbol are determined
     * automatically from the invite_token. The actual investment amount is calculated as 1% of
     * user's total assets. The amount_input parameter is only used for audit purposes.
     * 
     * @param Request $request
     * @param string $request->invite_token Required. Valid invite token for the window (max 64 characters).
     * @param string|null $request->amount_input Optional. User's intended amount (for audit only, actual amount is 1% of total assets).
     * 
     * @return JsonResponse Returns created order with calculated amount_base
     * 
     * Request example:
     * {
     *   "invite_token": "ABCD1234",
     *   "amount_input": "100"
     * }
     */
    public function placeOrder(Request $request): JsonResponse
    {
        try {
            // First validate basic rules - require invite_token
            $validated = $request->validate([
                'invite_token' => 'required|string|max:64', // Invite token for the window (max 64 characters)
                'amount_input' => 'nullable|string', // Optional user input amount (for audit only, actual amount = 1% of total assets)
            ]);

            // Find the invite token and get the window ID
            $inviteToken = $validated['invite_token'];
            $token = \App\Models\InviteToken::where('token', $inviteToken)->first();
            
            if (!$token) {
                \Log::warning('Follow order validation failed: invite token not found', [
                    'user_id' => auth()->id(),
                    'invite_token' => $inviteToken,
                    'input' => $request->all(),
                ]);

                $isDevelopment = app()->environment(['local', 'testing']) || config('app.debug');
                $response = [
                    'error' => '跟单码错误或者未生效，请跟管理员确认',
                ];

                if ($isDevelopment) {
                    $response['debug'] = [
                        'message' => "Invite token '{$inviteToken}' not found",
                        'suggestion' => 'Please check the invite token or call /api/v1/follow/windows to get available windows',
                    ];
                }

                return response()->json($response, 422);
            }

            // Get the window from the token
            $window = $token->followWindow;
            if (!$window) {
                \Log::warning('Follow order validation failed: window not found for token', [
                    'user_id' => auth()->id(),
                    'invite_token' => $inviteToken,
                    'follow_window_id' => $token->follow_window_id,
                ]);

                return response()->json([
                    'error' => '跟单码错误或者未生效，请跟管理员确认',
                ], 422);
            }

            if ($window->status !== 'active') {
                \Log::warning('Follow order validation failed: window not active', [
                    'user_id' => auth()->id(),
                    'follow_window_id' => $window->id,
                    'window_status' => $window->status,
                ]);

                return response()->json([
                    'error' => '跟单码错误或者未生效，请跟管理员确认',
                ], 422);
            }

            // Get symbol_id from the window
            $symbolId = $window->symbol_id;

            $amountInput = isset($validated['amount_input']) 
                ? Decimal::of($validated['amount_input']) 
                : null;

            $order = $this->followService->placeOrder(
                auth()->id(),
                $window->id,
                $symbolId,
                $inviteToken,
                $amountInput
            );

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => [
                    'id' => $order->id,
                    'amount_base' => $order->amount_base->toFixed(6),
                    'status' => $order->status,
                    'created_at' => $order->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ],
            ], 201);

        } catch (ValidationException $e) {
            // Log validation errors
            \Log::warning('Follow order validation failed', [
                'user_id' => auth()->id(),
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);

            // Return user-friendly error message
            $isDevelopment = app()->environment(['local', 'testing']) || config('app.debug');
            
            if ($isDevelopment) {
                return response()->json([
                    'error' => '跟单码错误或者未生效，请跟管理员确认',
                    'debug' => [
                        'validation_errors' => $e->errors(),
                    ],
                ], 422);
            } else {
                return response()->json([
                    'error' => '跟单码错误或者未生效，请跟管理员确认',
                ], 422);
            }
        } catch (\Exception $e) {
            // Log detailed error for debugging
            \Log::warning('Follow order failed', [
                'user_id' => auth()->id(),
                'invite_token' => $validated['invite_token'] ?? null,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error message based on error type
            $isDevelopment = app()->environment(['local', 'testing']) || config('app.debug');
            $errorMessage = $e->getMessage();
            
            // Handle specific error messages
            if (str_contains($errorMessage, 'already been used')) {
                $userMessage = '该跟单码已被使用，每个跟单码只能使用一次';
            } elseif (str_contains($errorMessage, '合约账户余额不足')) {
                // Keep the original message as it already guides user to transfer from spot to contract
                $userMessage = $errorMessage;
            } elseif (str_contains($errorMessage, 'Insufficient balance')) {
                $userMessage = '账户余额不足';
            } elseif (str_contains($errorMessage, 'Quota exhausted')) {
                $userMessage = '今日跟单额度已用完';
            } elseif (str_contains($errorMessage, 'permission')) {
                $userMessage = '您没有权限参与此类型的跟单窗口';
            } else {
                $userMessage = '跟单码错误或者未生效，请跟管理员确认';
            }
            
            if ($isDevelopment) {
                // In development, return detailed error for debugging
                return response()->json([
                    'error' => $userMessage,
                    'debug' => [
                        'message' => $errorMessage,
                        'error_class' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ], 400);
            } else {
                // In production, return user-friendly message only
                return response()->json([
                    'error' => $userMessage,
                ], 400);
            }
        }
    }

    /**
     * Get user's follow orders.
     * 
     * Returns paginated list of user's follow orders. Supports filtering by status.
     * Each order includes symbol information, window type, amount, profit, and settlement status.
     * 
     * @param Request $request Query parameters
     * @param string|null $request->status Optional. Filter by order status. Allowed values: "pending", "settled", "cancelled".
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated order list with metadata:
     * - orders: Array of order records with symbol, window_type, amount_base, profit, status, and timestamps
     * - pagination: Pagination metadata (current_page, total_pages, total)
     * 
     * Query example: ?status=pending&page=1
     */
    public function orders(Request $request): JsonResponse
    {
        $user = auth()->user();
        $status = $request->input('status');

        $query = \App\Models\FollowOrder::where('user_id', $user->id)
            ->with(['followWindow', 'symbol']);

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'symbol' => $order->symbol->name,
                    'window_type' => $order->followWindow->window_type,
                    'amount_base' => $order->amount_base->toFixed(6),
                    'profit' => $order->profit ? $order->profit->toFixed(6) : null,
                    'status' => $order->status,
                    'invite_token' => $order->invite_token,
                    'settled_at' => $order->settled_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                    'created_at' => $order->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ];
            }),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'total_pages' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get user's follow summary.
     * 
     * Returns statistics about user's follow trading activity, including:
     * - Total orders count
     * - Total investment amount
     * - Total profit/loss
     * - Success rate
     * - Recent activity summary
     * 
     * @return JsonResponse Returns summary statistics including:
     * - total_orders: Total number of follow orders placed
     * - total_investment: Total amount invested across all orders
     * - total_profit: Total profit/loss from settled orders
     * - success_rate: Percentage of profitable orders
     * - recent_activity: Summary of recent orders
     */
    public function summary(): JsonResponse
    {
        $summary = $this->followService->getSummary(auth()->id());
        
        return response()->json($summary);
    }
}

