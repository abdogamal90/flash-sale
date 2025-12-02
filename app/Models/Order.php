<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use App\Models\Hold;

class Order extends Model {

  /** @use HasFactory<\Database\Factories\HoldFactory> */
    use HasFactory;

     /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'hold_id',
        'status',
    ];

    public function hold(){
        return $this->belongsTo(Hold::class);
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    // MAKE STATUS CONSTANTS
    const PENDING = 'pending';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
}