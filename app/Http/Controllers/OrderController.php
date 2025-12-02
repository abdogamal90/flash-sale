<?php

namespace App\Http\Controllers;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
  public function index(){

    $orders = Order::all();
    return response()->json($orders);
  }

  public function show($id){

    $order = Order::find($id);
    if(!$order){
        return response()->json([
            'status' => 'error',
            'message' => 'Order not found',
            'timestamp' => now()->toISOString()
        ], 404);
    }

    return response()->json($order);
  }

  public function create(Request $request){

    $request->validate([
        'hold_id' => 'required|integer|exists:holds,id',
    ]);

    try {
        $order = DB::transaction(function () use ($request) {
            // Lock the hold to prevent race conditions
            $hold = Hold::where('id', $request->input('hold_id'))
                ->lockForUpdate()
                ->first();

            if(!$hold){
                throw new \Exception('Hold not found');
            }

            if($hold->hold_expires_at && $hold->hold_expires_at->isPast()){
                throw new \Exception('Cannot create order: Hold has expired');
            }

            if($hold->released_at !== null){
                throw new \Exception('Cannot create order: Hold has been released');
            }

            if($hold->order){
                throw new \Exception('Cannot create order: Hold is already associated with an order');
            }

            // Mark the hold as used
            $hold->used_at = now();
            $hold->save();

            // Create the order
            return Order::create(
                $request->only(['hold_id']) + ['status' => Order::PENDING]
            );

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity
            ]);
        });

        return response()->json([
            'status' => 'success',
            'timestamp' => now()->toISOString(),
            'data' => $order
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => now()->toISOString()
        ], 400);
    }
  }
}