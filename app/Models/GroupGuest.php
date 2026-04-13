<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupGuest extends Model
{
    protected $fillable = [
        'group_id',
        'name',
        'phone',
        'converted_to_user_id',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function convertedUser()
    {
        return $this->belongsTo(User::class, 'converted_to_user_id');
    }

    public function expenseSplits()
    {
        return $this->hasMany(ExpenseSplit::class, 'guest_id');
    }

    public function sentSettlements()
    {
        return $this->hasMany(Settlement::class, 'from_guest_id');
    }

    public function receivedSettlements()
    {
        return $this->hasMany(Settlement::class, 'to_guest_id');
    }
}