<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Validate the incoming webhook payload
        $request->validate([
            'event_id' => 'required|string',
            'order_id' => 'required|integer|exists:orders,id',
            'status' => 'required|string|in:paid,failed',
        ]);

        $eventId = $request->input('event_id');
        $orderId = $request->input('order_id');
        $paymentStatus = $request->input('status');

        try {
            $result = DB::transaction(function () use ($orderId, $eventId, $paymentStatus) {
                // Lock the order to prevent concurrent webhook processing
                $order = Order::where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    throw new \Exception('Order not found');
                }

                // Check for duplicate webhook (idempotency)
                if ($order->payment_idempotency_key === $eventId) {
                    Log::info("Duplicate webhook detected", [
                        'event_id' => $eventId,
                        'order_id' => $orderId,
                        'current_status' => $order->status
                    ]);
                    return ['duplicate' => true, 'order' => $order];
                }

                // Check if order is in a valid state for payment processing
                if ($order->status !== Order::PENDING) {
                    Log::warning("Webhook received for non-pending order", [
                        'event_id' => $eventId,
                        'order_id' => $orderId,
                        'current_status' => $order->status,
                        'payment_status' => $paymentStatus
                    ]);
                    throw new \Exception('Order is not in pending state');
                }

                // Update order status based on payment result
                $newStatus = $paymentStatus === 'paid' ? Order::COMPLETED : Order::CANCELLED;
                
                $order->status = $newStatus;
                $order->payment_idempotency_key = $eventId;
                $order->save();

                Log::info("Payment webhook processed successfully", [
                    'event_id' => $eventId,
                    'order_id' => $orderId,
                    'old_status' => Order::PENDING,
                    'new_status' => $newStatus
                ]);

                return ['duplicate' => false, 'order' => $order];
            });

            return response()->json([
                'status' => 'success',
                'message' => $result['duplicate'] ? 'Webhook already processed' : 'Payment processed',
                'timestamp' => now()->toISOString(),
                'data' => [
                    'order_id' => $result['order']->id,
                    'status' => $result['order']->status
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Payment webhook processing failed", [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 400);
        }
    }
}
