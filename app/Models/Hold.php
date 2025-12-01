<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class Hold extends Model {

  /** @use HasFactory<\Database\Factories\HoldFactory> */
    use HasFactory;

     /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'quantity',
        'hold_expires_at',
    ];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'hold_expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

}