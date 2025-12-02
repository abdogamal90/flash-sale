<?php

namespace App\Http\Controllers;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
  public function index(){

    $products = Product::all();
    return response()->json($products);
  }

  public function show($id){
    // Cache product for 60 seconds
    $product = Cache::remember("product_{$id}", 60, function () use ($id) {
        return Product::find($id);
    });

    if(!$product){
        return response()->json([
            'status' => 'error',
            'message' => 'Product not found',
            'timestamp' => now()->toISOString()
        ], 404);
    }

    return response()->json($product);
  }

  public function create(Request $request){
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'total_stock' => 'required|integer|min:0',
        'price' => 'required|numeric|min:0',
        'available_stock' => 'required|integer|min:0|max:total_stock',
    ]);

    $product = Product::create($validated);

    return response()->json([
        'status' => 'success',
        'timestamp' => now()->toISOString(),
        'data' => $product
    ], 201);
  }

  public function buy(Request $request, $id){
    $product = Product::find($id);
    if(!$product){
        return response()->json([
            'status' => 'error',
            'message' => 'Product not found',
            'timestamp' => now()->toISOString()
        ], 404);
    }

    $validated = $request->validate([
        'amount' => 'required|integer|min:1|max:available_stock',
    ]);

    $product->available_stock -= $validated['amount'];
    $product->save();

    return response()->json([
        'status' => 'success',
        'timestamp' => now()->toISOString(),
        'data' => $product
    ]);
  }
}