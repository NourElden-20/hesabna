<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'idempotency_key',
        'response_status',
        'response_body',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
