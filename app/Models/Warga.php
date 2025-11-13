<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warga extends Model
{
    use HasFactory;

    protected $table = 'warga';
    protected $primaryKey = 'id_warga';

    protected $fillable = [
        'nik',
        'nama',
        'alamat',
        'no_telp',
        'status_keaktifan',
    ];

    // Relasi ke anggota regu
    public function anggotaRegu()
    {
        return $this->hasOne(AnggotaRegu::class, 'id_warga');
    }

    // Relasi ke pembayaran
    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'id_warga');
    }
}
