<?php

namespace App\Http\Controllers;
use App\Models\Hold;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Jobs\ReleaseExpiredHold;

class HoldController extends Controller
{
  public function index(){

    $holds = Hold::all();
    return response()->json($holds);
  }

  public function show($id){

    $hold = Hold::find($id);
    if(!$hold){
        return response()->json([
            'status' => 'error',
            'message' => 'Hold not found',
            'timestamp' => now()->toISOString()
        ], 404);
    }

    return response()->json($hold);
  }

  public function create(Request $request){
    $validated = $request->validate([
        'product_id' => 'required|integer|exists:products,id',
        'quantity' => 'required|integer|min:1',
    ]);

    try {
        $hold = DB::transaction(function () use ($validated) {
            $product = Product::where('id', $validated['product_id'])
                ->lockForUpdate()
                ->first();

            if (!$product) {
                throw new \Exception('Product not found');
            }

            // Check if sufficient stock available
            if ($product->available_stock < $validated['quantity']) {
                throw new \Exception('Insufficient stock available. Only ' . $product->available_stock . ' units remaining.');
            }

            // Decrement stock atomically
            $product->available_stock -= $validated['quantity'];
            $product->save();

            // Create hold
            $hold_expires_at = now()->addMinutes(2);
            $hold = Hold::create([
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'hold_expires_at' => $hold_expires_at,
            ]);

            ReleaseExpiredHold::dispatch($hold->id)->delay($hold->hold_expires_at);

            return $hold;
        });

        return response()->json([
            'status' => 'success',
            'timestamp' => now()->toISOString(),
            'data' => [
                'hold_id' => $hold->id,
                'hold_expires_at' => $hold->hold_expires_at,
            ]
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