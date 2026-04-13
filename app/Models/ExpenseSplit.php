<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseSplit extends Model
{
    protected $fillable = [
        'expense_id',
        'user_id',
        'guest_id',
        'amount',
        'is_settled',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guest()
    {
        return $this->belongsTo(GroupGuest::class, 'guest_id');
    }
}