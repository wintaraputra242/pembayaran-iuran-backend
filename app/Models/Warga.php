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
        'nama_warga',
        'alamat',
        'no_hp',
        'status_keaktifan',
    ];

    // Relasi ke User (1 warga punya 1 user)
    public function user()
    {
        return $this->hasOne(User::class, 'nik', 'nik');
    }

    // Relasi ke anggota_regu
    public function anggotaRegu()
    {
        return $this->hasMany(AnggotaRegu::class, 'nik', 'nik');
    }

    // Relasi ke pembayaran
    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'nik', 'nik');
    }
}
