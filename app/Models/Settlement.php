<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $fillable = [
        'group_id',
        'from_user_id',
        'from_guest_id',
        'to_user_id',
        'to_guest_id',
        'amount',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function fromGuest()
    {
        return $this->belongsTo(GroupGuest::class, 'from_guest_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function toGuest()
    {
        return $this->belongsTo(GroupGuest::class, 'to_guest_id');
    }
}
