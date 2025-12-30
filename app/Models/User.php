<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'username',
        'role',
        'password',
        'nik',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Relasi ke Warga (jika role = warga)
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik');
    }
}
