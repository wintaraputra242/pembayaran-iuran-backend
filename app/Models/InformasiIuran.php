<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'nama_warga_meninggal',
        'nik_penanggung_jawab',
        'status_aktif',
    ];

    public function penanggungJawab(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'nik_penanggung_jawab', 'nik');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'id_informasi_iuran');
    }
}
