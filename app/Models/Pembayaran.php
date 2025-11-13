<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';
    protected $primaryKey = 'id_pembayaran';

    protected $fillable = [
        'id_warga',
        'id_informasi',
        'tanggal_bayar',
        'jumlah_bayar',
        'metode_bayar',
        'status_bayar',
    ];

    public function warga()
    {
        return $this->belongsTo(Warga::class, 'id_warga');
    }

    public function informasiIuran()
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi');
    }
}
