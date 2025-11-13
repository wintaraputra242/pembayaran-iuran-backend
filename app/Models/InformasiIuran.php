<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InformasiIuran extends Model
{
    use HasFactory;

    protected $table = 'informasi_iuran';
    protected $primaryKey = 'id_informasi';

    protected $fillable = [
        'nama_iuran',
        'jumlah',
        'periode',
        'keterangan',
    ];

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'id_informasi');
    }
}
