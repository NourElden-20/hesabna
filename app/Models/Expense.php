<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'name',
        'amount',
        'emoji',
        'group_id',
        'paid_by_user_id',
        'paid_by_guest_id',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function paidByUser()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function paidByGuest()
    {
        return $this->belongsTo(GroupGuest::class, 'paid_by_guest_id');
    }

    public function splits()
    {
        return $this->hasMany(ExpenseSplit::class);
    }
}
