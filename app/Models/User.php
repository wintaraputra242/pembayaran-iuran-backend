<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'id_user';

    protected $fillable = [
        'username',
        'password',
        'role', // admin / ketua_regu / warga
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function reguDikelola()
    {
        return $this->hasMany(Regu::class, 'id_ketua');
    }
}
