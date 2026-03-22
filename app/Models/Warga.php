<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warga extends Model
{
    use HasFactory;

    protected $table = 'warga';
    protected $primaryKey = 'nik';
    public $incrementing = false; // karena bukan auto-increment
    protected $keyType = 'string';

    protected $fillable = [
        'nik',
        'id_user',
        'nama_warga',
        'alamat',
        'no_hp',
        'status_keaktifan',
        'is_deleted',
        'deleted_at',
    ];

    // Relasi ke User (1 warga punya 1 user)
    public function users()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'nik', 'nik');
    }

    public function anggotaRegu()
    {
        return $this->hasOne(AnggotaRegu::class, 'nik', 'nik')
            ->whereNull('deleted_at')
            ->where('status_keaktifan', 1);
    }


    // // Relasi ke anggota_regu
    // public function anggotaRegu()
    // {
    //     return $this->hasMany(AnggotaRegu::class, 'nik', 'nik');
    // }

    // // Relasi ke pembayaran
    // public function pembayaran()
    // {
    //     return $this->hasMany(Pembayaran::class, 'nik', 'nik');
    // }
}
