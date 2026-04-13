<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function createdGroups()
    {
        return $this->hasMany(Group::class, 'created_by');
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_members');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'paid_by_user_id');
    }

    public function expenseSplits()
    {
        return $this->hasMany(ExpenseSplit::class);
    }

    public function sentSettlements()
    {
        return $this->hasMany(Settlement::class, 'from_user_id');
    }

    public function receivedSettlements()
    {
        return $this->hasMany(Settlement::class, 'to_user_id');
    }
}
