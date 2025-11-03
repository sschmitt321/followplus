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
     */
    public function availableWindows(Request $request): JsonResponse
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        
        $windows = $this->followService->getAvailableWindows($date);
        
        return response()->json([
            'date' => $date,
            'windows' => $windows,
        ]);
    }

    /**
     * Place a follow order.
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'follow_window_id' => 'required|integer|exists:follow_windows,id',
            'symbol_id' => 'required|integer|exists:symbols,id',
            'invite_token' => 'required|string|max:64',
            'amount_input' => 'nullable|string', // Optional, for audit
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
     */
    public function summary(): JsonResponse
    {
        $summary = $this->followService->getSummary(auth()->id());
        
        return response()->json($summary);
    }
}

