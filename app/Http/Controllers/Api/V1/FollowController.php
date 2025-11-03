<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Follow\FollowService;
use App\Services\Follow\FollowQuotaService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        
        $windows = $this->followService->getAvailableWindows($date);
        
        return response()->json([
            'date' => $date,
            'windows' => $windows,
        ]);
    }

    /**
     * Place a follow order.
     * 
     * Creates a follow order for the specified window. The actual investment amount is calculated
     * as 1% of user's total assets. The amount_input parameter is only used for audit purposes.
     * 
     * @param Request $request
     * @param int $request->follow_window_id Required. ID of the follow window to join. Window must be active and not expired.
     * @param int $request->symbol_id Required. ID of the trading symbol (must match window's symbol).
     * @param string $request->invite_token Required. Valid invite token for the window (max 64 characters).
     * @param string|null $request->amount_input Optional. User's intended amount (for audit only, actual amount is 1% of total assets).
     * 
     * @return JsonResponse Returns created order with calculated amount_base
     * 
     * Request example:
     * {
     *   "follow_window_id": 1,
     *   "symbol_id": 1,
     *   "invite_token": "ABCD1234",
     *   "amount_input": "100"
     * }
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'follow_window_id' => 'required|integer|exists:follow_windows,id', // Follow window ID (must exist and be active)
            'symbol_id' => 'required|integer|exists:symbols,id', // Trading symbol ID (must match window's symbol)
            'invite_token' => 'required|string|max:64', // Invite token for the window (max 64 characters)
            'amount_input' => 'nullable|string', // Optional user input amount (for audit only, actual amount = 1% of total assets)
        ]);

        try {
            $amountInput = isset($validated['amount_input']) 
                ? Decimal::of($validated['amount_input']) 
                : null;

            $order = $this->followService->placeOrder(
                auth()->id(),
                $validated['follow_window_id'],
                $validated['symbol_id'],
                $validated['invite_token'],
                $amountInput
            );

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => [
                    'id' => $order->id,
                    'amount_base' => $order->amount_base->toFixed(6),
                    'status' => $order->status,
                    'created_at' => $order->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
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
                    'settled_at' => $order->settled_at?->toIso8601String(),
                    'created_at' => $order->created_at->toIso8601String(),
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

