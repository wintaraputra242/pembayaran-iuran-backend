<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'username',
        'no_hp',      // ← tambah
        'password',
        'role',
        'is_active'
    ];

    protected $hidden   = ['password', 'deleted_at'];

    public function warga(): HasOne
    {
        return $this->hasOne(Warga::class, 'id_user');
    }

    public function regu(): HasMany
    {
        return $this->hasMany(Regu::class, 'id_user');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'id_user');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function pembayaranDiproses(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'processed_by');
    }

    public function getAuthIdentifierName()
    {
        return 'username';
    }
}
