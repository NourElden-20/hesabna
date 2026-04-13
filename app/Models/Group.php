<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = [
        'name',
        'emoji',
        'created_by',
        'invite_token',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function groupMembers()
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }

    public function guests()
    {
        return $this->hasMany(GroupGuest::class, 'group_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'group_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'group_members');
    }
}
