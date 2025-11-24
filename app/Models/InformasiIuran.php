<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InformasiIuran extends Model
{
    use HasFactory;

    protected $table = 'informasi_iuran';

    protected $fillable = [
        'jenis_iuran',
        'periode',
        'jumlah_iuran',
        'keterangan',
        'status_aktif',
        'tanggal_nonaktif',
    ];

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'id_informasi_iuran');
    }
}
