<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InformasiIuran extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'informasi_iuran';


    protected $fillable = [
        'judul_iuran',
        'jenis_iuran',
        'periode',
        'jumlah_iuran',
        'keterangan',
        'status_aktif',
        'tanggal_nonaktif',
        'nama_warga_meninggal',
        'nik_penanggung_jawab',
        'is_deleted',
        // 'deleted_at',
    ];

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class, 'id_informasi_iuran');
    }
    
    public function warga()
    {
        return $this->belongsTo(
            Warga::class,
            'nik_penanggung_jawab',
            'nik'
        );
    }
}
