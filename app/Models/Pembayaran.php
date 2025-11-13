<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    protected $fillable = [
        'nik',
        'id_informasi_iuran',
        'tanggal_bayar',
        'total_bayar',
        'metode_bayar',
        'status_bayar',
        'bukti_pembayaran',
    ];

    // Relasi ke warga
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik');
    }

    // Relasi ke informasi iuran
    public function informasiIuran()
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi_iuran');
    }
}
