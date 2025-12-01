<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHold implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $holdId;

    /**
     * Create a new job instance.
     */
    public function __construct($holdId)
    {
        $this->holdId = $holdId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function() {
            $hold = Hold::where('id', $this->holdId)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();
            
            if (!$hold) {
                Log::info('Hold already released or not found', ['hold_id' => $this->holdId]);
                return;
            }
            
            $product = Product::lockForUpdate()->find($hold->product_id);
            
            if (!$product) {
                Log::warning('Product not found for hold release', ['hold_id' => $this->holdId, 'product_id' => $hold->product_id]);
                return;
            }
            
            $product->available_stock += $hold->quantity;
            $product->save();
            
            $hold->released_at = now();
            $hold->save();
            
            Log::info('Hold released automatically', [
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'quantity_restored' => $hold->quantity,
                'new_available_stock' => $product->available_stock
            ]);
        });
    }
}