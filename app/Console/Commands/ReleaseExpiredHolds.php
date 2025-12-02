<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ReleaseExpiredHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'release:expired-holds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired holds and restore product stock (backup safety net)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning for expired holds...');
        
        $expiredHolds = Hold::where('hold_expires_at', '<', now())
            ->whereNull('released_at')
            ->get();
        
        if ($expiredHolds->isEmpty()) {
            $this->info('No expired holds found.');
            return 0;
        }
        
        $this->info("Found {$expiredHolds->count()} expired holds to release.");
        
        $releasedCount = 0;
        $failedCount = 0;
        
        foreach ($expiredHolds as $hold) {
            try {
                DB::transaction(function() use ($hold) {
                    // Re-lock to ensure idempotency
                    $lockedHold = Hold::where('id', $hold->id)
                        ->whereNull('released_at')
                        ->lockForUpdate()
                        ->first();
                    
                    if (!$lockedHold) {
                        return; // Already released by job
                    }
                    
                    $product = Product::lockForUpdate()->find($lockedHold->product_id);
                    
                    if (!$product) {
                        $this->warn("Product {$lockedHold->product_id} not found for hold {$lockedHold->id}");
                        return;
                    }
                    
                    $product->available_stock += $lockedHold->quantity;
                    $product->save();
                    
                    // Invalidate product cache
                    Cache::forget("product_{$product->id}");
                    
                    $lockedHold->released_at = now();
                    $lockedHold->save();
                    
                    Log::info('Hold released by scheduled command', [
                        'hold_id' => $lockedHold->id,
                        'product_id' => $product->id,
                        'quantity_restored' => $lockedHold->quantity,
                        'new_available_stock' => $product->available_stock
                    ]);
                });
                
                $releasedCount++;
                
            } catch (\Exception $e) {
                $failedCount++;
                $this->error("Failed to release hold {$hold->id}: {$e->getMessage()}");
                Log::error('Hold release failed in command', [
                    'hold_id' => $hold->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->info("Released {$releasedCount} holds successfully.");
        
        if ($failedCount > 0) {
            $this->warn("Failed to release {$failedCount} holds.");
        }
        
        return 0;
    }
}
